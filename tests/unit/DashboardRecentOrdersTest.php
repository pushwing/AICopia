<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DashboardController::index() 의 recentOrders 쿼리 검증
 *
 * - 결제 확정 전(pending) 시도와 레거시 만료(expired) 주문은
 *   orders 테이블에 보존만 되고 관리자 대시보드 "최근 주문" 위젯에는
 *   노출되지 않아야 한다(이슈 #214).
 *
 * DashboardController::index() 는 $this->render()로 뷰를 직접 렌더링해
 * 컨트롤러 단위 테스트가 어려우므로, 컨트롤러와 동일한 쿼리 조건을
 * 이 테스트에서 재현해 검증한다(DashboardChartTest 와 동일한 방식).
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
     * DashboardController::index() 의 recentOrders 쿼리 조건(정렬·limit 제외)을 그대로 재현한다.
     * limit(5)는 다른 테스트가 같은 DB에 동시에 주문을 쌓는 상황에서 전역 개수에 의존하게 되므로,
     * 검증 대상 주문 하나만 조회하도록 id로 좁혀 exclude/include 여부만 확인한다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOrdersQuery(int $orderId): array
    {
        $db = db_connect();

        return $db->table('orders o')
            ->select('o.id, o.order_number, o.status, o.total_amount, o.created_at, u.nickname AS user_nickname')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->whereNotIn('o.status', ['pending', 'expired'])
            ->where('o.id', $orderId)
            ->orderBy('o.id', 'DESC')
            ->get()->getResultArray();
    }

    // ── 검증 ─────────────────────────────────────────────────────────────────

    public function testPendingOrderExcludedFromRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'pending');

        $result = $this->recentOrdersQuery($orderId);

        $this->assertCount(0, $result, 'pending 주문은 최근 주문 위젯 쿼리에 노출되면 안 됨');
    }

    public function testExpiredOrderExcludedFromRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'expired');

        $result = $this->recentOrdersQuery($orderId);

        $this->assertCount(0, $result, 'expired 주문은 최근 주문 위젯 쿼리에 노출되면 안 됨');
    }

    public function testPaidOrderStillAppearsInRecentOrders(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'paid');

        $result = $this->recentOrdersQuery($orderId);

        $this->assertCount(1, $result, '결제 완료 주문은 최근 주문 위젯 쿼리에 노출돼야 함');
        $this->assertSame($orderId, (int) $result[0]['id']);
    }
}
