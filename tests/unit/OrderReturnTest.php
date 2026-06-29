<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 반품 요청·승인·거부 흐름 테스트
 * 재고 복구 / 쿠폰 복원 / 포인트 환급 및 적립 취소를 검증한다.
 */
final class OrderReturnTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    private array $cleanup = [
        'users'    => [],
        'products' => [],
        'orders'   => [],
        'coupons'  => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            $db->table('order_status_logs')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('payments')->whereIn('order_id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('user_coupons')->whereIn('user_id', $this->cleanup['users'])->delete();
            $db->table('point_logs')->whereIn('user_id', $this->cleanup['users'])->delete();
        }

        foreach (['orders', 'products', 'users', 'coupons'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'         => 'ret_' . uniqid() . '@test.local',
            'username'      => 'ret_' . uniqid(),
            'password'      => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'      => '반품테스터',
            'role'          => 'member',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(int $stock): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => '반품테스트상품',
            'slug'           => 'ret-prod-' . uniqid(),
            'price'          => 10000,
            'stock'          => $stock,
            'status'         => $stock > 0 ? 'on_sale' : 'sold_out',
            'shipping_type'  => 'fixed',
            'shipping_fee'   => 3000,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status, array $extra = []): int
    {
        $db = db_connect();
        $defaults = [
            'user_id'                => $userId,
            'order_number'           => 'RET-' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'shipping_fee'           => 3000,
            'total_amount'           => 13000,
            'payable_amount'         => 13000,
            'point_used_amount'      => 0,
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
        ];
        // delivered 상태 기본값: 1일 전 배송 완료 (7일 이내)
        if ($status === 'delivered' && ! isset($extra['delivered_at'])) {
            $defaults['delivered_at'] = date('Y-m-d H:i:s', strtotime('-1 day'));
        }
        $db->table('orders')->insert(array_merge($defaults, $extra));
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertOrderItem(int $orderId, int $productId, int $qty): void
    {
        db_connect()->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => '반품테스트상품',
            'product_price' => 10000,
            'qty'           => $qty,
            'subtotal'      => 10000 * $qty,
        ]);
    }

    private function insertPayment(int $orderId, string $status = 'paid'): void
    {
        db_connect()->table('payments')->insert([
            'order_id'    => $orderId,
            'pg_provider' => 'toss',
            'pg_tid'      => 'RTEST_' . uniqid(),
            'amount'      => 13000,
            'status'      => $status,
            'method'      => 'card',
            'paid_at'     => date('Y-m-d H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertCoupon(int $usedCount = 1): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'code'                => 'RET-' . uniqid(),
            'name'                => '반품테스트쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 1000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => 0,
            'used_count'          => $usedCount,
            'per_user_limit'      => 0,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId, int $orderId, string $source = 'admin'): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => $orderId,
            'source'     => $source,
            'status'     => 'used',
            'issued_at'  => date('Y-m-d H:i:s'),
            'used_at'    => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    private function insertEarnLog(int $userId, int $orderId, int $amount): void
    {
        db_connect()->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'earn',
            'amount'     => $amount,
            'order_id'   => $orderId,
            'note'       => '구매 적립',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── requestReturn ─────────────────────────────────────────────────────────

    public function testRequestReturnSucceeds(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');
        $db      = db_connect();

        $this->assertTrue($this->model->requestReturn($orderId, $userId, 'simple_change'));

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('return_requested', $order['status']);
        $this->assertSame('단순 변심', $order['return_reason']);
    }

    public function testRequestReturnFailsForNonDeliveredOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'paid');

        $this->assertFalse($this->model->requestReturn($orderId, $userId, 'simple_change'));
        $this->assertSame('paid',
            db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRequestReturnFailsForWrongUser(): void
    {
        $ownerId = $this->insertUser();
        $otherId = $this->insertUser();
        $orderId = $this->insertOrder($ownerId, 'delivered');

        $this->assertFalse($this->model->requestReturn($orderId, $otherId, 'simple_change'));
    }

    public function testRequestReturnFailsAfter7Days(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered', [
            'delivered_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
        ]);

        $this->assertFalse($this->model->requestReturn($orderId, $userId, 'simple_change'));
        $this->assertSame('delivered',
            db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRequestReturnSucceedsWithin7Days(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered', [
            'delivered_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
        ]);

        $this->assertTrue($this->model->requestReturn($orderId, $userId, 'simple_change'));
    }

    // ── approveReturn ─────────────────────────────────────────────────────────

    public function testApproveReturnRestoresStock(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);
        $orderId   = $this->insertOrder($userId, 'return_requested');
        $this->insertOrderItem($orderId, $productId, 3);
        $this->insertPayment($orderId);
        $db = db_connect();

        $this->assertTrue($this->model->approveReturn($orderId));

        $stock = (int) $db->table('products')->where('id', $productId)->get()->getRowArray()['stock'];
        $this->assertSame(13, $stock);

        $this->assertSame('return_approved',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testApproveReturnRefundsUsedPoints(): void
    {
        $userId    = $this->insertUser(500);
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'return_requested', ['point_used_amount' => 300]);
        $this->insertOrderItem($orderId, $productId, 1);
        $this->insertPayment($orderId);
        $db = db_connect();

        $this->model->approveReturn($orderId);

        $balance = (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'];
        $this->assertSame(800, $balance);

        $log = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'refund')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(300, (int) $log['amount']);
    }

    public function testApproveReturnRevokesEarnedPoints(): void
    {
        $userId    = $this->insertUser(1000);
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'return_requested', ['point_earned_amount' => 200]);
        $this->insertOrderItem($orderId, $productId, 1);
        $this->insertPayment($orderId);
        $this->insertEarnLog($userId, $orderId, 200);
        $db = db_connect();

        $this->model->approveReturn($orderId);

        $balance = (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'];
        $this->assertSame(800, $balance);

        $cancelLog = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'cancel')->get()->getRowArray();
        $this->assertNotNull($cancelLog);
        $this->assertSame(-200, (int) $cancelLog['amount']);
    }

    public function testApproveReturnRestoresCoupon(): void
    {
        $userId    = $this->insertUser();
        $couponId  = $this->insertCoupon(1);
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'return_requested', [
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => 1000,
        ]);
        $this->insertOrderItem($orderId, $productId, 1);
        $this->insertPayment($orderId);
        $ucId = $this->insertUserCoupon($userId, $couponId, $orderId, 'admin');
        $db = db_connect();

        $this->model->approveReturn($orderId);

        $usedCount = (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'];
        $this->assertSame(0, $usedCount);

        $uc = $db->table('user_coupons')->where('id', $ucId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertNull($uc['order_id']);
    }

    public function testApproveReturnDoesNotChangePaymentStatus(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'return_requested');
        $this->insertOrderItem($orderId, $productId, 1);
        $this->insertPayment($orderId, 'paid');
        $db = db_connect();

        $this->model->approveReturn($orderId);

        // 결제 상태는 confirmReturnRefund 호출 전까지 그대로 유지
        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $payment['status']);
    }

    public function testConfirmReturnRefundSetsRefunded(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'return_approved');
        $this->insertOrderItem($orderId, $productId, 1);
        $this->insertPayment($orderId, 'paid');
        $db = db_connect();

        $this->assertTrue($this->model->confirmReturnRefund($orderId));

        $this->assertSame('refunded',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('refunded', $payment['status']);
        $this->assertNotNull($payment['cancelled_at']);
    }

    public function testConfirmReturnRefundFailsForNonApprovedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'return_requested');

        $this->assertFalse($this->model->confirmReturnRefund($orderId));
    }

    public function testApproveReturnFailsForNonReturnRequestedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');

        $this->assertFalse($this->model->approveReturn($orderId));
    }

    // ── rejectReturn ──────────────────────────────────────────────────────────

    public function testRejectReturnRestoresDeliveredStatus(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'return_requested');
        $db      = db_connect();

        $this->assertTrue($this->model->rejectReturn($orderId));

        $this->assertSame('delivered',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRejectReturnFailsForNonReturnRequestedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');

        $this->assertFalse($this->model->rejectReturn($orderId));
    }
}
