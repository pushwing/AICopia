<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MyPageController::addressDelete() 회귀 테스트
 *
 * 기본 배송지를 지우면 남은 배송지 하나를 새 기본으로 승격시키는데, 이때
 * 승격 대상 id 를 조회 결과(MySQLi → 문자열)에서 그대로 꺼내
 * ShippingAddressModel::setDefault(int $id, ...) 에 넘기고 있었다.
 * strict_types 아래에서는 TypeError 라 삭제 요청이 통째로 500 이 된다.
 */
final class MyPageAddressDeleteTest extends CIUnitTestCase
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
        $this->prefix = 'MPAD' . substr(uniqid(), -6);
        session()->destroy();
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

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix,
            'email'      => $this->prefix . '@example.test',
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

    private function controller(): MyPageController
    {
        $controller = new MyPageController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    private function isDefault(int $addressId): bool
    {
        $row = db_connect()->table('shipping_addresses')
            ->select('is_default')->where('id', $addressId)->get()->getRowArray();

        return (int) ($row['is_default'] ?? 0) === 1;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testDeletingDefaultAddressPromotesRemainingOne(): void
    {
        $userId    = $this->insertUser();
        $remaining = $this->insertAddress($userId, false);
        $default   = $this->insertAddress($userId, true);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->controller()->addressDelete($default);

        $this->assertNull(
            db_connect()->table('shipping_addresses')->where('id', $default)->get()->getRowArray(),
            '기본 배송지가 삭제되지 않았다',
        );
        $this->assertTrue($this->isDefault($remaining), '남은 배송지가 기본 배송지로 승격되지 않았다');
    }

    public function testDeletingLastAddressLeavesNothingToPromote(): void
    {
        $userId  = $this->insertUser();
        $default = $this->insertAddress($userId, true);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->controller()->addressDelete($default);

        $this->assertNull(
            db_connect()->table('shipping_addresses')->where('id', $default)->get()->getRowArray(),
            '마지막 배송지가 삭제되지 않았다',
        );
    }
}
