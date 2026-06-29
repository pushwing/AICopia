<?php

namespace Tests\Unit;

use App\Libraries\CouponService;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel — 주문 생명주기 (X/E/S/R/CC 그룹)
 * 이슈 #12 · 3단계
 */
final class OrderLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel    $model;
    private CouponService $couponService;

    private array $cleanup = [
        'orders'       => [],
        'user_coupons' => [],
        'coupons'      => [],
        'products'     => [],
        'users'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model         = new OrderModel();
        $this->couponService = new CouponService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'point_logs', 'payments', 'order_items'] as $t) {
                $db->table($t)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }

        foreach (['user_coupons', 'coupons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 기본 헬퍼 ─────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'oltest_' . $uid,
            'email'         => 'ol-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OLUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OLProd_' . uniqid(),
            'slug'           => 'ol-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return array_merge(['id' => $id], $data);
    }

    private function insertCoupon(array $extra = []): array
    {
        $db   = db_connect();
        $code = 'OLC-' . strtoupper(uniqid());
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => 'OL Coupon',
            'type'                => 'fixed',
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return ['id' => $id, 'code' => $extra['code'] ?? $code];
    }

    private function insertUserCoupon(int $userId, int $couponId, string $status = 'issued'): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => null,
            'source'     => 'admin',
            'status'     => $status,
            'issued_at'  => date('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;
        return $id;
    }

    private function makeCartItem(array $product, int $qty = 1): array
    {
        return [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => $qty,
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
        ];
    }

    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function trackOrder(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
        }
        return $orderId;
    }

    /** createPending 래핑 헬퍼 */
    private function createPendingOrder(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0
    ): int {
        return $this->trackOrder(
            $this->model->createPending(
                $userId,
                $this->shippingData(),
                [$this->makeCartItem($product, $qty)],
                $couponId,
                $userCouponId,
                $couponDiscount,
                $pointUsed,
                $pointEarned
            )
        );
    }

    /** createPending + confirmPaid 래핑 헬퍼 */
    private function createPaidOrder(
        int $userId,
        array $product,
        int $qty = 1,
        int $pointEarned = 0,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0
    ): int {
        $orderId = $this->createPendingOrder(
            $userId, $product, $qty,
            $couponId, $userCouponId, $couponDiscount, $pointUsed, $pointEarned
        );
        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        return $orderId;
    }

    /** paid → preparing → shipped까지 진행 헬퍼 */
    private function createShippedOrder(int $userId, array $product, int $pointEarned = 0): int
    {
        $orderId = $this->createPaidOrder($userId, $product, 1, $pointEarned);
        $this->model->updateStatus($orderId, 'preparing');
        $this->model->updateStatus($orderId, 'shipped');
        return $orderId;
    }

    /** 특정 status 주문을 직접 INSERT */
    private function insertOrderDirect(int $userId, string $status, array $extra = []): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('orders')->insert(array_merge([
            'user_id'                => $userId,
            'order_number'           => 'OL' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'shipping_fee'           => 0,
            'total_amount'           => 10000,
            'coupon_id'              => null,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'payable_amount'         => 10000,
            'receiver_name'          => '테스트',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시',
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertPaymentDirect(int $orderId, string $status = 'paid', int $amount = 10000): void
    {
        db_connect()->table('payments')->insert([
            'order_id'    => $orderId,
            'pg_provider' => 'toss',
            'pg_tid'      => null,
            'method'      => 'card',
            'amount'      => $amount,
            'status'      => $status,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertOrderItemDirect(int $orderId, int $productId, int $qty = 1, int $price = 10000): void
    {
        db_connect()->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => 'OL-item',
            'product_price' => $price,
            'cost_price'    => 0,
            'qty'           => $qty,
            'subtotal'      => $price * $qty,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** point_logs에 earn 기록 직접 삽입 */
    private function insertEarnLog(int $userId, int $orderId, int $amount): void
    {
        db_connect()->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'earn',
            'amount'     => $amount,
            'order_id'   => $orderId,
            'note'       => 'test-earn',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 주문 created_at을 N분 전으로 변경 */
    private function ageOrder(int $orderId, int $minutesAgo): void
    {
        db_connect()->table('orders')->where('id', $orderId)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime("-{$minutesAgo} minutes")),
        ]);
    }

    // ── X: cancelOrder / adminCancel ─────────────────────────────────────────

    /** X-01: paid 취소 → 재고 복구 */
    public function testCancelOrder_paidStatus_stockRestored(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $orderId = $this->createPaidOrder($userId, $product, 3);

        $result = $this->model->cancelOrder($orderId, $userId);
        $this->assertTrue($result);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(10, (int) $p['stock']);
    }

    /** X-02: paid 취소 — sold_out 상품이 on_sale로 복구 */
    public function testCancelOrder_paidSoldOut_restoresOnSale(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 3]);
        $orderId = $this->createPaidOrder($userId, $product, 3);  // stock → 0, sold_out

        $this->model->cancelOrder($orderId, $userId);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame('on_sale', $p['status']);
        $this->assertSame(3, (int) $p['stock']);
    }

    /** X-03: paid 취소 → payment.status='cancelled' */
    public function testCancelOrder_paidStatus_paymentCancelled(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPaidOrder($userId, $product);

        $this->model->cancelOrder($orderId, $userId);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('cancelled', $payment['status']);
    }

    /** X-04: pending 취소 → 재고 변경 없음 */
    public function testCancelOrder_pendingStatus_stockUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $orderId = $this->createPendingOrder($userId, $product, 3);

        $result = $this->model->cancelOrder($orderId, $userId);
        $this->assertTrue($result);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(10, (int) $p['stock']);
    }

    /** X-05: 쿠폰 복구 — user_coupon.status='issued', coupons.used_count 감소 */
    public function testCancelOrder_couponRestored(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $orderId = $this->createPaidOrder($userId, $product, 1, 0, $coupon['id'], $userCouponId, 3000);

        $countBefore = (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count'];
        $this->model->cancelOrder($orderId, $userId);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);

        $countAfter = (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count'];
        $this->assertSame($countBefore - 1, $countAfter);
    }

    /** X-06: 포인트 복구 — point_balance 증가 + point_logs 'refund' 기록 */
    public function testCancelOrder_pointRestored(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(3000);
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product, 1, null, null, 0, 3000);
        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);

        $this->model->cancelOrder($orderId, $userId);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(3000, (int) $user['point_balance']);

        $log = $db->table('point_logs')
            ->where('user_id', $userId)->where('order_id', $orderId)->where('type', 'refund')
            ->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(3000, (int) $log['amount']);
    }

    /** X-07: 미배송 주문 취소 — point_earned_amount가 있어도 미적립이므로 point_balance 불변 */
    public function testCancelOrder_earnedPointsNotYetGranted_balanceUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(0);
        $product = $this->insertProduct();
        // pointEarned=1000 → 주문에 기록되지만 배송완료 전이므로 실제 지급 없음
        $orderId = $this->createPaidOrder($userId, $product, 1, 1000);

        $this->model->cancelOrder($orderId, $userId);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(0, (int) $user['point_balance']);
    }

    /** X-08: adminCancel — shipped 상태는 취소 불가 */
    public function testAdminCancel_shippedStatus_returnsFalse(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createShippedOrder($userId, $product);

        $result = $this->model->adminCancel($orderId);
        $this->assertFalse($result);
    }

    /** X-09: adminCancel — preparing 취소 가능 + 재고 복구 */
    public function testAdminCancel_preparingStatus_stockRestored(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $orderId = $this->createPaidOrder($userId, $product, 3);  // stock → 7
        $this->model->updateStatus($orderId, 'preparing');

        $result = $this->model->adminCancel($orderId);
        $this->assertTrue($result);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(10, (int) $p['stock']);
    }

    /** X-10: 타인 주문 cancelOrder → false */
    public function testCancelOrder_wrongUser_returnsFalse(): void
    {
        $db       = db_connect();
        $userId1  = $this->insertUser();
        $userId2  = $this->insertUser();
        $product  = $this->insertProduct();
        $orderId  = $this->createPaidOrder($userId1, $product);

        $result = $this->model->cancelOrder($orderId, $userId2);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $order['status']);
    }

    // ── E: expirePending ──────────────────────────────────────────────────────

    /** E-01: 30분 초과 pending → expired, 반환 count=1 */
    public function testExpirePending_oldOrder_marksExpiredAndReturnsCount(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product);
        $this->ageOrder($orderId, 40);

        $count = $this->model->expirePending(30);

        $this->assertSame(1, $count);
        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('expired', $order['status']);
    }

    /** E-02: 29분 pending → 만료 대상 아님, status 유지 */
    public function testExpirePending_recentOrder_staysPending(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product);
        $this->ageOrder($orderId, 29);

        $this->model->expirePending(30);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $order['status']);
    }

    /** E-03: 만료 시 쿠폰 복구 */
    public function testExpirePending_couponRestored(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $orderId = $this->createPendingOrder($userId, $product, 1, $coupon['id'], $userCouponId, 3000);
        $this->ageOrder($orderId, 40);

        $this->model->expirePending(30);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertSame(0, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);
    }

    /** E-04: 만료 시 포인트 환급 */
    public function testExpirePending_pointRefunded(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product, 1, null, null, 0, 5000);
        $this->ageOrder($orderId, 40);

        $this->model->expirePending(30);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(5000, (int) $user['point_balance']);

        $log = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'refund')
            ->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(5000, (int) $log['amount']);
    }

    /** E-05: pending 만료 전후 재고 불변 (pending은 재고 미차감) */
    public function testExpirePending_stockUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $orderId = $this->createPendingOrder($userId, $product, 3);
        $this->ageOrder($orderId, 40);

        $before = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];
        $this->model->expirePending(30);
        $after  = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];

        $this->assertSame($before, $after);
        $this->assertSame(10, $after);
    }

    // ── S: updateStatus ───────────────────────────────────────────────────────

    /** S-01: paid → preparing */
    public function testUpdateStatus_paidToPreparing(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPaidOrder($userId, $product);

        $result = $this->model->updateStatus($orderId, 'preparing');
        $this->assertTrue($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('preparing', $order['status']);
    }

    /** S-02: preparing → shipped */
    public function testUpdateStatus_preparingToShipped(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPaidOrder($userId, $product);
        $this->model->updateStatus($orderId, 'preparing');

        $result = $this->model->updateStatus($orderId, 'shipped');
        $this->assertTrue($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('shipped', $order['status']);
    }

    /** S-03: shipped → delivered */
    public function testUpdateStatus_shippedToDelivered(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createShippedOrder($userId, $product);

        $result = $this->model->updateStatus($orderId, 'delivered');
        $this->assertTrue($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('delivered', $order['status']);
    }

    /** S-04: preparing → paid (역방향) → false */
    public function testUpdateStatus_reverse_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPaidOrder($userId, $product);
        $this->model->updateStatus($orderId, 'preparing');

        $result = $this->model->updateStatus($orderId, 'paid');
        $this->assertFalse($result);
    }

    /** S-05: paid → shipped (비연속) → false */
    public function testUpdateStatus_nonSequential_returnsFalse(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPaidOrder($userId, $product);

        $result = $this->model->updateStatus($orderId, 'shipped');
        $this->assertFalse($result);
    }

    /** S-06: delivered 전환 + point_earned_amount=1000 → 포인트 적립 */
    public function testUpdateStatus_deliveredWithEarnedPoints_earnLog(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(0);
        $product = $this->insertProduct();
        $orderId = $this->createShippedOrder($userId, $product, 1000);

        $this->model->updateStatus($orderId, 'delivered');

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(1000, (int) $user['point_balance']);

        $log = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'earn')
            ->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(1000, (int) $log['amount']);
    }

    /** S-07: delivered 전환 + point_earned_amount=0 → 포인트 로직 건너뜀 */
    public function testUpdateStatus_deliveredNoEarnedPoints_noEarnLog(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(0);
        $product = $this->insertProduct();
        $orderId = $this->createShippedOrder($userId, $product, 0);

        $this->model->updateStatus($orderId, 'delivered');

        $logCount = $db->table('point_logs')->where('order_id', $orderId)->where('type', 'earn')->countAllResults();
        $this->assertSame(0, $logCount);
    }

    /** S-08: refund_requested → refunded */
    public function testUpdateStatus_refundRequestedToRefunded(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $orderId = $this->insertOrderDirect($userId, 'refund_requested');

        $result = $this->model->updateStatus($orderId, 'refunded');
        $this->assertTrue($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('refunded', $order['status']);
    }

    /** S-09: 존재하지 않는 orderId → false */
    public function testUpdateStatus_nonExistentOrder_returnsFalse(): void
    {
        $result = $this->model->updateStatus(999999999, 'preparing');
        $this->assertFalse($result);
    }

    // ── R: markRefunded ───────────────────────────────────────────────────────

    /** R-01: paid 상태에서 markRefunded → false */
    public function testMarkRefunded_notRefundRequestedStatus_returnsFalse(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrderDirect($userId, 'paid');

        $result = $this->model->markRefunded($orderId);
        $this->assertFalse($result);
    }

    /** R-02: 쿠폰 복구 */
    public function testMarkRefunded_couponRestored(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['used_count' => 1]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $orderId = $this->insertOrderDirect($userId, 'refund_requested', [
            'coupon_id'              => $coupon['id'],
            'coupon_discount_amount' => 3000,
        ]);
        // user_coupon을 'used' 상태로 연결
        $db->table('user_coupons')->where('id', $userCouponId)->update([
            'status'  => 'used',
            'order_id'=> $orderId,
            'used_at' => date('Y-m-d H:i:s'),
        ]);
        $this->insertPaymentDirect($orderId, 'paid');

        $result = $this->model->markRefunded($orderId);
        $this->assertTrue($result);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
    }

    /** R-03: 포인트 사용분 환급 */
    public function testMarkRefunded_usedPointsRefunded(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(0);  // points already spent
        $orderId = $this->insertOrderDirect($userId, 'refund_requested', [
            'point_used_amount' => 3000,
        ]);
        $this->insertPaymentDirect($orderId, 'paid');

        $result = $this->model->markRefunded($orderId);
        $this->assertTrue($result);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(3000, (int) $user['point_balance']);

        $log = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'refund')
            ->get()->getRowArray();
        $this->assertNotNull($log);
    }

    /** R-04: 이미 적립된 포인트 회수 */
    public function testMarkRefunded_earnedPointsRevoked(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(1000);  // already earned
        $orderId = $this->insertOrderDirect($userId, 'refund_requested', [
            'point_earned_amount' => 1000,
        ]);
        $this->insertPaymentDirect($orderId, 'paid');
        $this->insertEarnLog($userId, $orderId, 1000);

        $result = $this->model->markRefunded($orderId);
        $this->assertTrue($result);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(0, (int) $user['point_balance']);  // 1000 - 1000 = 0

        $cancelLog = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'cancel')
            ->get()->getRowArray();
        $this->assertNotNull($cancelLog);
        $this->assertSame(-1000, (int) $cancelLog['amount']);
    }

    /** R-05: earn 로그 없으면 포인트 회수 건너뜀 */
    public function testMarkRefunded_noEarnLog_skipRevoke(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(0);
        $orderId = $this->insertOrderDirect($userId, 'refund_requested', [
            'point_earned_amount' => 1000,
        ]);
        $this->insertPaymentDirect($orderId, 'paid');
        // earn log 없음

        $this->model->markRefunded($orderId);

        $cancelLogCount = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'cancel')
            ->countAllResults();
        $this->assertSame(0, $cancelLogCount);
    }

    /** R-06: payments.status='refunded' */
    public function testMarkRefunded_paymentStatusRefunded(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $orderId = $this->insertOrderDirect($userId, 'refund_requested');
        $this->insertPaymentDirect($orderId, 'paid');

        $result = $this->model->markRefunded($orderId);
        $this->assertTrue($result);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('refunded', $payment['status']);
    }

    // ── CC: 동시성 방어 ───────────────────────────────────────────────────────

    /** CC-01: 포인트 잔액 부족 → 롤백, balance 불변 */
    public function testPointOveruse_rollsBack_balanceUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(3000);
        $product = $this->insertProduct();

        $result = $this->model->createPending(
            $userId, $this->shippingData(), [$this->makeCartItem($product)],
            null, null, 0, 4000, 0  // try to use 4000, only have 3000
        );
        $this->assertSame(0, $result);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(3000, (int) $user['point_balance']);
    }

    /** CC-02: 동일 pg_tid로 두 주문 결제 시도 — 두 번째 실패, 재고 이중 차감 없음 */
    public function testDuplicatePgTid_secondConfirmFails_stockDeductedOnce(): void
    {
        $db       = db_connect();
        $userId   = $this->insertUser();
        $product  = $this->insertProduct(['stock' => 10]);
        $sameTid  = 'TID-DUPE-' . uniqid();

        $orderId1 = $this->createPendingOrder($userId, $product, 2);
        $orderId2 = $this->createPendingOrder($userId, $product, 2);

        $ok1 = $this->model->confirmPaid($orderId1, 'toss', $sameTid, 'card', []);
        $ok2 = $this->model->confirmPaid($orderId2, 'toss', $sameTid, 'card', []);

        $this->assertTrue($ok1);
        $this->assertFalse($ok2);

        // 재고는 orderId1의 차감(qty=2)만 반영
        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(8, (int) $p['stock']);
    }

    /** CC-03: total_qty=1 쿠폰 — 한 주문 사용 후 validate 실패 */
    public function testCouponTotalQty_afterFirstUse_validateFails(): void
    {
        $userId1 = $this->insertUser();
        $userId2 = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon(['total_qty' => 1, 'used_count' => 0]);

        // 첫 번째 주문에서 쿠폰 사용 → used_count=1
        $orderId = $this->createPendingOrder($userId1, $product, 1, $coupon['id'], null, 3000);
        $this->assertGreaterThan(0, $orderId);

        // used_count가 total_qty에 도달했으므로 두 번째 사용자 validate 실패
        $result = $this->couponService->validate($coupon['code'], $userId2, 10000);
        $this->assertFalse($result['valid']);
    }
}
