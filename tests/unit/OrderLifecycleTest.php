<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\CouponService;
use App\Models\OrderAttemptModel;
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
        'order_attempts' => [],
        'orders'         => [],
        'user_coupons'   => [],
        'coupons'        => [],
        'products'       => [],
        'users'          => [],
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

        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
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

    /** 생성된 attemptId를 cleanup에 등록 */
    private function trackAttempt(int $attemptId): int
    {
        if ($attemptId > 0) {
            $this->cleanup['order_attempts'][] = $attemptId;
        }
        return $attemptId;
    }

    /**
     * 쿠폰 코드 경로(userCouponId=null)로 preemptCoupon() 이 신규 INSERT 한
     * user_coupons 행(source='code')을 조회해 cleanup 등록한다.
     */
    private function trackCodeCoupon(int $userId, int $couponId): void
    {
        $db  = db_connect();
        $ids = array_column(
            $db->table('user_coupons')->select('id')->where('user_id', $userId)->where('coupon_id', $couponId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['user_coupons'] = array_merge($this->cleanup['user_coupons'], $ids);
    }

    /**
     * 결제 확정된 주문을 만든다.
     *
     * 주문 생성은 order_attempts 를 거치도록 바뀌었다(이슈 #214). 시도를 만든 뒤
     * 즉시 전환해 기존 테스트가 기대하는 주문 id 를 돌려준다.
     */
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
        $attemptId = $this->trackAttempt((new OrderAttemptModel())->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product, $qty)],
            $couponId,
            $userCouponId,
            $couponDiscount,
            $pointUsed,
            $pointEarned,
            'toss'
        ));

        if ($attemptId === 0) {
            return 0;
        }

        $orderId = $this->trackOrder(
            $this->model->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', [])
        );

        $this->assertGreaterThan(0, $orderId, '주문 생성에 실패했습니다');

        return $orderId;
    }

    /**
     * 레거시 pending 주문을 orders 에 직접 만든다.
     *
     * expirePending()/cancelOrder() 의 pending 분기는 orders.status='pending' 인
     * 레거시 주문에만 동작한다(이슈 #214 이후 신규 결제는 order_attempts 를 거쳐
     * 바로 paid/awaiting_payment 로 전환되고 pending 을 거치지 않는다). 이 헬퍼는
     * 구 버전 주문 생성 로직이 주문 생성 시점에 하던 쿠폰·포인트 선점을 orders 에
     * 직접 재현해 그 레거시 경로에 대한 회귀 테스트를 계속 지탱한다.
     */
    private function insertLegacyPendingOrder(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0
    ): int {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $totalProduct = (int) $product['price'] * $qty;
        $payable      = max(0, $totalProduct - $couponDiscount - $pointUsed);

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'OL-LEG-' . uniqid(),
            'status'                 => 'pending',
            'total_product_price'    => $totalProduct,
            'shipping_fee'           => 0,
            'total_amount'           => $totalProduct,
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => $couponDiscount,
            'point_used_amount'      => $pointUsed,
            'point_earned_amount'    => 0,
            'payable_amount'         => $payable,
            'receiver_name'          => '테스트',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트구',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $orderId = $this->trackOrder((int) $db->insertID());

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => (int) $product['id'],
            'product_name'  => $product['name'],
            'product_price' => (int) $product['price'],
            'cost_price'    => 0,
            'qty'           => $qty,
            'subtotal'      => $totalProduct,
            'created_at'    => $now,
        ]);

        // 구 버전 주문 생성 로직이 주문 생성 시점에 하던 쿠폰·포인트 선점을 재현한다.
        if ($couponId) {
            $db->query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$couponId]);
            if ($userCouponId) {
                $db->table('user_coupons')->where('id', $userCouponId)->update([
                    'status'     => 'used',
                    'order_id'   => $orderId,
                    'used_at'    => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($pointUsed > 0) {
            $db->query('UPDATE users SET point_balance = point_balance - ? WHERE id = ?', [$pointUsed, $userId]);
            $db->table('point_logs')->insert([
                'user_id'    => $userId,
                'type'       => 'use',
                'amount'     => -$pointUsed,
                'order_id'   => $orderId,
                'note'       => '주문 포인트 사용',
                'created_at' => $now,
            ]);
        }

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
        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);

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
        $orderId = $this->createPaidOrder($userId, $product, 1, 0, null, null, 0, 3000);

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
        $orderId = $this->insertLegacyPendingOrder($userId, $product);
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
        $orderId = $this->insertLegacyPendingOrder($userId, $product);
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

        $orderId = $this->insertLegacyPendingOrder($userId, $product, 1, $coupon['id'], $userCouponId, 3000);
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
        $orderId = $this->insertLegacyPendingOrder($userId, $product, 1, null, null, 0, 5000);
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
        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);
        $this->ageOrder($orderId, 40);

        $before = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];
        $this->model->expirePending(30);
        $after  = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];

        $this->assertSame($before, $after);
        $this->assertSame(10, $after);
    }

    /** E-06: getByUser 기본("전체") 조회는 만료 주문을 제외한다 */
    public function testGetByUser_defaultStatus_excludesExpiredOrders(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertLegacyPendingOrder($userId, $product);
        $this->ageOrder($orderId, 40);
        $this->model->expirePending(30);

        $result = $this->model->getByUser($userId, ['status' => '']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertNotContains($orderId, $ids);
    }

    /** E-07: getByUser "취소/환불" 탭 조회에는 만료 주문이 계속 노출된다 */
    public function testGetByUser_cancelTab_includesExpiredOrders(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertLegacyPendingOrder($userId, $product);
        $this->ageOrder($orderId, 40);
        $this->model->expirePending(30);

        $result = $this->model->getByUser($userId, ['status' => 'cancel']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertContains($orderId, $ids);
    }

    /** E-08: getByUser 기본 조회는 레거시 pending 주문도 제외한다 */
    public function testGetByUser_defaultStatus_excludesLegacyPendingOrders(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertLegacyPendingOrder($userId, $product);

        $result = $this->model->getByUser($userId, ['status' => '']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertNotContains($orderId, $ids);
    }

    /** E-09: adminGetAll 기본 조회도 pending·expired 를 제외한다 */
    public function testAdminGetAll_excludesPendingAndExpired(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertLegacyPendingOrder($userId, $product);

        $result = $this->model->adminGetAll(['status' => '']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertNotContains($orderId, $ids);
    }

    /**
     * E-10: getWithItems 는 레거시 pending 주문 상세를 열어주지 않는다
     *
     * 목록에서 감췄어도 주문번호만 알면 /mypage/orders/{번호} 로 상세가 열렸다.
     * pending 은 어느 목록 탭에도 노출되지 않으므로 막아도 깨지는 링크가 없다. (이슈 #214)
     */
    public function testGetWithItems_returnsNullForLegacyPendingOrder(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertLegacyPendingOrder($userId, $product);

        $this->assertNull($this->model->getWithItems($orderId, $userId));
    }

    /**
     * E-11: getWithItems 는 만료 주문 상세는 그대로 열어준다
     *
     * expired 는 "취소/환불" 탭(status=cancel)에 계속 노출되는 것이 설계 결정이라
     * 상세까지 막으면 목록에서 클릭했을 때 깨진다.
     */
    public function testGetWithItems_returnsExpiredOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrderDirect($userId, 'expired');

        $order = $this->model->getWithItems($orderId, $userId);

        $this->assertNotNull($order);
        $this->assertSame('expired', $order['status']);
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
            'order_id' => $orderId,
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

    /** CC-01: 포인트 잔액 부족 → 시도 자체가 롤백, balance 불변 */
    public function testPointOveruse_rollsBack_balanceUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(3000);
        $product = $this->insertProduct();

        $result = $this->trackAttempt((new OrderAttemptModel())->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product)],
            null,
            null,
            0,
            4000,
            0  // try to use 4000, only have 3000
        ));
        $this->assertSame(0, $result);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(3000, (int) $user['point_balance']);
    }

    /**
     * CC-02: 동일 pg_tid로 두 주문 결제 시도 — 두 번째 실패, 재고 이중 차감 없음
     *
     * confirmPaid() 는 레거시 orders.status='pending' 주문에만 동작하므로, 이
     * 회귀 테스트는 레거시 pending 주문 두 건으로 계속 검증한다.
     */
    public function testDuplicatePgTid_secondConfirmFails_stockDeductedOnce(): void
    {
        $db       = db_connect();
        $userId   = $this->insertUser();
        $product  = $this->insertProduct(['stock' => 10]);
        $sameTid  = 'TID-DUPE-' . uniqid();

        $orderId1 = $this->insertLegacyPendingOrder($userId, $product, 2);
        $orderId2 = $this->insertLegacyPendingOrder($userId, $product, 2);

        $ok1 = $this->model->confirmPaid($orderId1, 'toss', $sameTid, 'card', []);
        $ok2 = $this->model->confirmPaid($orderId2, 'toss', $sameTid, 'card', []);

        $this->assertTrue($ok1);
        $this->assertFalse($ok2);

        // 재고는 orderId1의 차감(qty=2)만 반영
        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(8, (int) $p['stock']);
    }

    /**
     * CC-03: total_qty=1 쿠폰 — 한 주문 사용 후 validate 실패
     *
     * 쿠폰 선점은 이슈 #214 로 주문 시도 생성 시점(createAttempt)으로 옮겨졌다 —
     * used_count 증가는 결제 확정을 기다리지 않고 시도 생성 즉시 일어난다.
     */
    public function testCouponTotalQty_afterFirstUse_validateFails(): void
    {
        $userId1 = $this->insertUser();
        $userId2 = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon(['total_qty' => 1, 'used_count' => 0]);

        // 첫 번째 주문 시도에서 쿠폰 사용 → used_count=1
        $attemptId = $this->trackAttempt((new OrderAttemptModel())->createAttempt(
            $userId1,
            $this->shippingData(),
            [$this->makeCartItem($product)],
            $coupon['id'],
            null,
            3000
        ));
        $this->assertGreaterThan(0, $attemptId);
        $this->trackCodeCoupon($userId1, $coupon['id']);

        // used_count가 total_qty에 도달했으므로 두 번째 사용자 validate 실패
        $result = $this->couponService->validate($coupon['code'], $userId2, 10000);
        $this->assertFalse($result['valid']);
    }
}
