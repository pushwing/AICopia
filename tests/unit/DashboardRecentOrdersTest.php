<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel::getRecentForDashboard() 검증
 *
 * - 결제 확정 전(pending) 시도와 레거시 만료(expired) 주문은
 *   orders 테이블에 보존만 되고 관리자 대시보드 "최근 주문" 위젯에는
 *   노출되지 않아야 한다(이슈 #214).
 *
 * DashboardController::index() 가 실제로 호출하는 것과 동일한
 * OrderModel::getRecentForDashboard() 를 직접 호출해 검증하므로,
 * 컨트롤러 쪽에서 조건이 빠지면 이 테스트도 함께 실패한다.
 */
final class DashboardRecentOrdersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'orders' => [],
        'users'  => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'dash_' . $uid,
            'email'      => 'dash_' . $uid . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'dash_user',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status): int
    {
        $db      = db_connect();
        $orderNo = 'DASH-' . uniqid();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNo,
            'status'                 => $status,
            'total_product_price'    => 10000,
            'total_amount'           => 10000,
            'payable_amount'         => 10000,
            'shipping_fee'           => 0,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'receiver_name'          => '테스트',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    /**
     * OrderModel::getRecentForDashboard() 결과에서 검증 대상 주문 하나만 걸러낸다.
     * limit(5)는 다른 테스트가 같은 DB에 동시에 주문을 쌓는 상황에서 전역 개수에 의존하게
     * 되므로, 전체 결과를 넉넉히 가져온 뒤 id로 좁혀 exclude/include 여부만 확인한다.
     *
     * @return array<string, mixed>|null
     */
    private function findInRecentForDashboard(int $orderId): ?array
    {
        $orderModel = new OrderModel();
        foreach ($orderModel->getRecentForDashboard(1_000_000) as $row) {
            if ((int) $row['id'] === $orderId) {
                return $row;
            }
        }

        return null;
    }

    // ── 검증 ─────────────────────────────────────────────────────────────────

    public function testPendingOrderExcludedFromRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'pending');

        $result = $this->findInRecentForDashboard($orderId);

        $this->assertNull($result, 'pending 주문은 최근 주문 위젯 쿼리에 노출되면 안 됨');
    }

    public function testExpiredOrderExcludedFromRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'expired');

        $result = $this->findInRecentForDashboard($orderId);

        $this->assertNull($result, 'expired 주문은 최근 주문 위젯 쿼리에 노출되면 안 됨');
    }

    public function testPaidOrderStillAppearsInRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'paid');

        $result = $this->findInRecentForDashboard($orderId);

        $this->assertNotNull($result, '결제 완료 주문은 최근 주문 위젯 쿼리에 노출돼야 함');
        $this->assertSame($orderId, (int) $result['id']);
    }

    // ── "오늘 주문 수" 카운트 ─────────────────────────────────────────────────
    //
    // 같은 대시보드의 매출 쿼리들은 pending·expired 를 제외하는데 이 카운트만
    // 조건이 없어, 결제되지 않은 주문까지 오늘 주문 수에 잡혔다. (이슈 #214)

    /** 오늘 만든 주문 중 검증 대상만 세도록 카운트 차이로 확인한다 */
    private function countTodayOrdersDelta(string $status): int
    {
        $orderModel = new OrderModel();
        $todayStart = date('Y-m-d 00:00:00');

        $before = $orderModel->countTodayOrders($todayStart);
        $this->insertOrder($this->insertUser(), $status);

        return $orderModel->countTodayOrders($todayStart) - $before;
    }

    public function testPendingOrderExcludedFromTodayOrderCount(): void
    {
        $this->assertSame(0, $this->countTodayOrdersDelta('pending'), 'pending 주문이 오늘 주문 수에 잡혔다');
    }

    public function testExpiredOrderExcludedFromTodayOrderCount(): void
    {
        $this->assertSame(0, $this->countTodayOrdersDelta('expired'), 'expired 주문이 오늘 주문 수에 잡혔다');
    }

    public function testPaidOrderCountedInTodayOrderCount(): void
    {
        $this->assertSame(1, $this->countTodayOrdersDelta('paid'), '결제 완료 주문이 오늘 주문 수에서 빠졌다');
    }
}
