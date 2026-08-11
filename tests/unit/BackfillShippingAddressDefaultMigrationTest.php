<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * BackfillShippingAddressDefault 마이그레이션
 *
 * OrderController::create()가 "배송지 저장" 체크박스로 저장하는 첫 주소를
 * 기본 배송지(is_default)로 지정하지 않던 버그(#178에서 코드는 수정됨)가
 * 배포 전에 만들어둔 기존 행에는 소급 적용되지 않는다. 그 결과 배포 후에도
 * 기존 사용자는 여전히 주문서에서 배송지 입력란이 비어 보인다.
 *
 * 이 마이그레이션은 기본 배송지가 하나도 없는 사용자에 한해, 가장 먼저
 * 저장된(최소 id) 주소를 기본으로 승격시킨다. 이미 기본 배송지가 있는
 * 사용자·주소는 건드리지 않는다.
 */
final class BackfillShippingAddressDefaultMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['shipping_addresses' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'BSAD' . substr(uniqid(), -6);

        $files = glob(APPPATH . 'Database/Migrations/*_BackfillShippingAddressDefault.php');
        require_once $files[0];
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['shipping_addresses', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['shipping_addresses' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix . substr(uniqid(), -4),
            'email'      => $this->prefix . uniqid() . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertAddress(int $userId, bool $isDefault): int
    {
        $db = db_connect();
        $db->table('shipping_addresses')->insert([
            'user_id'        => $userId,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트로 ' . substr(uniqid(), -4),
            'address2'       => '',
            'is_default'     => $isDefault ? 1 : 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id                                    = (int) $db->insertID();
        $this->cleanup['shipping_addresses'][] = $id;

        return $id;
    }

    private function isDefault(int $addressId): bool
    {
        $row = db_connect()->table('shipping_addresses')
            ->select('is_default')->where('id', $addressId)->get()->getRowArray();

        return (int) ($row['is_default'] ?? 0) === 1;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function test_promotes_earliest_address_when_no_default_exists(): void
    {
        $userId  = $this->insertUser();
        $first   = $this->insertAddress($userId, false);
        $second  = $this->insertAddress($userId, false);

        (new \App\Database\Migrations\BackfillShippingAddressDefault())->up();

        $this->assertTrue($this->isDefault($first), '가장 먼저 저장된 주소가 기본으로 승격돼야 한다');
        $this->assertFalse($this->isDefault($second), '나중에 저장된 주소는 그대로 둬야 한다');
    }

    public function test_does_not_touch_user_who_already_has_a_default(): void
    {
        $userId  = $this->insertUser();
        $first   = $this->insertAddress($userId, false);
        $default = $this->insertAddress($userId, true);

        (new \App\Database\Migrations\BackfillShippingAddressDefault())->up();

        $this->assertFalse($this->isDefault($first), '이미 기본 배송지가 있으면 다른 주소를 건드리면 안 된다');
        $this->assertTrue($this->isDefault($default), '기존 기본 배송지는 그대로 유지돼야 한다');
    }

    public function test_is_idempotent(): void
    {
        $userId = $this->insertUser();
        $first  = $this->insertAddress($userId, false);

        $migration = new \App\Database\Migrations\BackfillShippingAddressDefault();
        $migration->up();
        $migration->up();

        $this->assertTrue($this->isDefault($first));
    }
}
