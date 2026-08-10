<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use App\Models\ShippingAddressModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MyPageController::addressDelete() 회귀 테스트
 *
 * MySQLi 드라이버는 조회 결과를 모두 문자열로 돌려준다. 기본 배송지를 지우면
 * 남은 배송지를 새 기본으로 승격시키는데, 그때 first() 로 얻은 문자열 id 를
 * int 파라미터로 선언된 ShippingAddressModel::setDefault() 에 그대로 넘기면
 * strict_types 아래에서 TypeError 가 나 배송지 삭제가 500 이 된다.
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

    private function insertAddress(int $userId, string $label, bool $isDefault): int
    {
        $db = db_connect();
        $db->table('shipping_addresses')->insert([
            'user_id'        => $userId,
            'receiver_name'  => $label,
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트로 1',
            'address2'       => $label,
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

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testDeletingDefaultAddressPromotesRemainingOneWithoutTypeError(): void
    {
        $userId    = $this->insertUser();
        $defaultId = $this->insertAddress($userId, '기본배송지', true);
        $otherId   = $this->insertAddress($userId, '예비배송지', false);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->controller()->addressDelete($defaultId);

        $model = new ShippingAddressModel();

        $this->assertNull($model->find($defaultId), '기본 배송지가 삭제되지 않았다');

        $remaining = $model->find($otherId);
        $this->assertNotNull($remaining, '남은 배송지가 사라졌다');
        $this->assertSame(1, (int) $remaining['is_default'], '남은 배송지가 새 기본 배송지로 승격되지 않았다');
    }

    public function testDeletingNonDefaultAddressKeepsExistingDefault(): void
    {
        $userId    = $this->insertUser();
        $defaultId = $this->insertAddress($userId, '기본배송지', true);
        $otherId   = $this->insertAddress($userId, '예비배송지', false);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $this->controller()->addressDelete($otherId);

        $model = new ShippingAddressModel();

        $this->assertNull($model->find($otherId), '배송지가 삭제되지 않았다');
        $this->assertSame(1, (int) $model->find($defaultId)['is_default'], '기본 배송지가 바뀌었다');
    }
}
