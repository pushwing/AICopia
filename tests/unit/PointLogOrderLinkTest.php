<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use App\Models\PointLogModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 마이페이지 포인트 내역에서 주문과 연결된 로그가
 * 해당 주문 상세로 이동할 수 있는지 검증한다.
 */
final class PointLogOrderLinkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['point_logs' => [], 'orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PLOL' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['point_logs', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['point_logs' => [], 'orders' => [], 'users' => []];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $this->prefix,
            'email'         => $this->prefix . '@example.test',
            'password'      => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'      => $this->prefix,
            'role'          => 'member',
            'point_balance' => 5000,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $orderNumber): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => 'paid',
            'total_product_price'    => 10000,
            'shipping_fee'           => 0,
            'total_amount'           => 10000,
            'payable_amount'         => 9000,
            'point_used_amount'      => 1000,
            'point_earned_amount'    => 0,
            'coupon_id'              => null,
            'coupon_discount_amount' => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트로 1',
            'address2'               => '',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id                        = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    private function insertPointLog(int $userId, string $type, int $amount, ?int $orderId, ?string $note): void
    {
        $db = db_connect();
        $db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => $type,
            'amount'     => $amount,
            'order_id'   => $orderId,
            'note'       => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['point_logs'][] = (int) $db->insertID();
    }

    private function controller(): MyPageController
    {
        $controller = new MyPageController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testGetByUserExposesOrderNumber(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertPointLog($userId, 'use', -1000, $orderId, '주문 포인트 사용');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(1, $result['items']);
        $this->assertSame($orderNumber, $result['items'][0]['order_number'] ?? null, '포인트 로그에 주문번호가 실려오지 않았다');
    }

    public function testGetByUserLeavesOrderNumberNullWhenOrderGone(): void
    {
        $userId = $this->insertUser();
        // 주문이 지워졌거나 없는 order_id 를 가리키는 로그
        $this->insertPointLog($userId, 'use', -1000, 999_999_999, '주문 포인트 사용');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(1, $result['items']);
        $this->assertNull($result['items'][0]['order_number'], '없는 주문인데 주문번호가 생겼다');
    }

    public function testPointsPageLinksUseLogToOrderDetail(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertPointLog($userId, 'use', -1000, $orderId, '주문 포인트 사용');

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->points();

        $this->assertStringContainsString('/mypage/orders/' . $orderNumber, $result, '주문 상세로 가는 링크가 없다');
        $this->assertStringContainsString($orderNumber, $result, '주문번호가 표시되지 않았다');
        $this->assertStringContainsString('주문 포인트 사용', $result, '기존 내용(note)이 사라졌다');
        $this->assertStringNotContainsString('주문 #' . $orderId, $result, '고객에게 의미 없는 내부 주문 id 가 그대로 보인다');
    }

    public function testPointsPageShowsNoLinkWhenOrderGone(): void
    {
        $userId = $this->insertUser();
        $this->insertPointLog($userId, 'use', -1000, 999_999_999, '주문 포인트 사용');

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->points();

        $this->assertStringContainsString('주문 포인트 사용', $result);
        $this->assertStringNotContainsString('/mypage/orders/999999999', $result, '없는 주문으로 가는 링크가 생겼다');
    }

    public function testPointsPageLinksEarnLogToOrderDetail(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertPointLog($userId, 'earn', 500, $orderId, '배송 완료 포인트 적립');

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->points();

        $this->assertStringContainsString('/mypage/orders/' . $orderNumber, $result, '적립 로그도 주문으로 이어져야 한다');
    }
}
