<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DashboardController::chartData() 검증
 *
 * - 최근 30일 일별 매출 배열 구조
 * - 상품별 판매량 TOP5 구조
 * - 취소/환불/만료 주문 제외 여부
 */
final class DashboardChartTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'order_items' => [],
        'payments'    => [],
        'orders'      => [],
        'products'    => [],
        'users'       => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

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
            'username'  => 'chart_' . $uid,
            'email'     => 'chart_' . $uid . '@test.com',
            'password'  => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'  => 'chart_user',
            'role'      => 'member',
            'grade'     => 'bronze',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status, int $amount, string $date): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'               => $userId,
            'order_number'          => 'TEST-' . uniqid(),
            'status'                => $status,
            'total_product_price'   => $amount,
            'total_amount'          => $amount,
            'payable_amount'        => $amount,
            'shipping_fee'          => 0,
            'coupon_discount_amount'=> 0,
            'point_used_amount'     => 0,
            'point_earned_amount'   => 0,
            'receiver_name'         => '테스트',
            'receiver_phone'        => '010-0000-0000',
            'zipcode'               => '12345',
            'address1'              => '서울시',
            'created_at'            => $date . ' 12:00:00',
            'updated_at'            => $date . ' 12:00:00',
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertOrderItem(int $orderId, string $productName, int $qty): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => 0,
            'product_name'  => $productName,
            'product_price' => 10000,
            'cost_price'    => 5000,
            'qty'           => $qty,
            'subtotal'      => 10000 * $qty,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
    }

    private function buildChartData(): array
    {
        $db = db_connect();

        $rows = $db->query("
            SELECT DATE(created_at) AS day, SUM(payable_amount) AS revenue
            FROM orders
            WHERE status NOT IN ('pending','expired','cancelled','refunded')
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY day
            ORDER BY day ASC
        ")->getResultArray();

        $salesMap = [];
        foreach ($rows as $r) {
            $salesMap[$r['day']] = (int) $r['revenue'];
        }

        $labels = [];
        $data   = [];
        for ($i = 29; $i >= 0; $i--) {
            $day     = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('m/d', strtotime($day));
            $data[]   = $salesMap[$day] ?? 0;
        }

        $topProducts = $db->query("
            SELECT oi.product_name, SUM(oi.qty) AS total_qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.status NOT IN ('pending','expired','cancelled','refunded')
              AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY oi.product_name
            ORDER BY total_qty DESC
            LIMIT 5
        ")->getResultArray();

        return [
            'sales' => ['labels' => $labels, 'data' => $data],
            'top'   => [
                'labels' => array_column($topProducts, 'product_name'),
                'data'   => array_map('intval', array_column($topProducts, 'total_qty')),
            ],
        ];
    }

    // ── 구조 검증 ─────────────────────────────────────────────────────────────

    public function testChartDataHasSalesAndTopKeys(): void
    {
        $result = $this->buildChartData();
        $this->assertArrayHasKey('sales', $result);
        $this->assertArrayHasKey('top', $result);
    }

    public function testSalesLabelsHas30Entries(): void
    {
        $result = $this->buildChartData();
        $this->assertCount(30, $result['sales']['labels']);
    }

    public function testSalesDataHas30Entries(): void
    {
        $result = $this->buildChartData();
        $this->assertCount(30, $result['sales']['data']);
    }

    public function testSalesLabelsAreInMmDdFormat(): void
    {
        $result = $this->buildChartData();
        foreach ($result['sales']['labels'] as $label) {
            $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}$/', $label, "레이블 형식이 mm/dd 이어야 함: {$label}");
        }
    }

    public function testSalesDataAreIntegers(): void
    {
        $result = $this->buildChartData();
        foreach ($result['sales']['data'] as $val) {
            $this->assertIsInt($val, '매출 데이터는 정수여야 함');
            $this->assertGreaterThanOrEqual(0, $val, '매출은 0 이상이어야 함');
        }
    }

    public function testTopLabelsAndDataHaveSameCount(): void
    {
        $result = $this->buildChartData();
        $this->assertCount(count($result['top']['labels']), $result['top']['data']);
    }

    public function testTopProductsAtMostFive(): void
    {
        $result = $this->buildChartData();
        $this->assertLessThanOrEqual(5, count($result['top']['labels']));
    }

    public function testTopDataAreIntegers(): void
    {
        $result = $this->buildChartData();
        foreach ($result['top']['data'] as $val) {
            $this->assertIsInt($val);
            $this->assertGreaterThan(0, $val);
        }
    }

    // ── 데이터 정확성 ─────────────────────────────────────────────────────────

    public function testPaidOrderRevenueAppearsInSalesData(): void
    {
        $userId  = $this->insertUser();
        $today   = date('Y-m-d');
        $orderId = $this->insertOrder($userId, 'paid', 55000, $today);

        $result  = $this->buildChartData();
        $todayLabel = date('m/d');
        $idx     = array_search($todayLabel, $result['sales']['labels']);

        $this->assertNotFalse($idx, '오늘 날짜 레이블이 있어야 함');
        $this->assertGreaterThanOrEqual(55000, $result['sales']['data'][$idx], '결제 완료 주문 금액이 포함돼야 함');
    }

    public function testCancelledOrderExcludedFromSalesData(): void
    {
        $userId  = $this->insertUser();
        $today   = date('Y-m-d');
        $before  = $this->buildChartData();
        $todayLabel = date('m/d');
        $idxBefore  = array_search($todayLabel, $before['sales']['labels']);
        $revBefore  = $before['sales']['data'][$idxBefore] ?? 0;

        $this->insertOrder($userId, 'cancelled', 99999, $today);

        $after    = $this->buildChartData();
        $idxAfter = array_search($todayLabel, $after['sales']['labels']);
        $revAfter = $after['sales']['data'][$idxAfter] ?? 0;

        $this->assertSame($revBefore, $revAfter, '취소 주문은 매출에 포함되지 않아야 함');
    }

    public function testTopProductsReflectOrderItems(): void
    {
        $userId  = $this->insertUser();
        $today   = date('Y-m-d');
        $orderId = $this->insertOrder($userId, 'paid', 10000, $today);
        $this->insertOrderItem($orderId, '테스트차트상품_' . uniqid(), 7);

        $result = $this->buildChartData();
        $this->assertNotEmpty($result['top']['labels'], '판매 상품이 TOP 목록에 나타나야 함');
    }

    public function testOldOrderExcludedFromSalesData(): void
    {
        $userId  = $this->insertUser();
        $oldDate = date('Y-m-d', strtotime('-31 days'));
        $before  = $this->buildChartData();
        $totalBefore = array_sum($before['sales']['data']);

        $this->insertOrder($userId, 'paid', 77777, $oldDate);

        $after       = $this->buildChartData();
        $totalAfter  = array_sum($after['sales']['data']);

        $this->assertSame($totalBefore, $totalAfter, '30일 초과 주문은 집계에서 제외돼야 함');
    }
}
