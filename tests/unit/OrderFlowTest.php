<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel — 시도 기반 주문 생성(P), confirmPaid(G), confirmBankTransfer(B)
 * 이슈 #12 · 2단계 / 이슈 #214 (order_attempts 전환)
 */
final class OrderFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    private array $cleanup = [
        'order_attempts' => [],
        'cart_items'     => [],
        'point_logs'     => [],
        'payments'       => [],
        'order_items'    => [],
        'orders'         => [],
        'user_coupons'   => [],
        'coupons'        => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'oftest_' . $uid,
            'email'         => 'of-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OFTestUser',
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
            'name'           => 'OFProduct_' . uniqid(),
            'slug'           => 'of-prod-' . uniqid(),
            'price'          => 20000,
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
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return array_merge(['id' => $id], $data);
    }

    private function insertCoupon(array $extra = []): array
    {
        $db   = db_connect();
        $code = 'OF-' . strtoupper(uniqid());
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => 'OFCoupon',
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
        $id                         = (int) $db->insertID();
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
            'receiver_name'  => '테스트 수령인',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    /** 생성된 orderId를 cleanup에 등록 */
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

    /** order_items cleanup 등록 */
    private function trackOrderItems(int $orderId): void
    {
        $db  = db_connect();
        $ids = array_column(
            $db->table('order_items')->select('id')->where('order_id', $orderId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['order_items'] = array_merge($this->cleanup['order_items'], $ids);
    }

    /** payments cleanup 등록 */
    private function trackPayments(int $orderId): void
    {
        $db  = db_connect();
        $ids = array_column(
            $db->table('payments')->select('id')->where('order_id', $orderId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['payments'] = array_merge($this->cleanup['payments'], $ids);
    }

    /** point_logs cleanup 등록 */
    private function trackPointLogs(int $userId): void
    {
        $db  = db_connect();
        $ids = array_column(
            $db->table('point_logs')->select('id')->where('user_id', $userId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['point_logs'] = array_merge($this->cleanup['point_logs'], $ids);
    }

    /**
     * 결제 확정된 주문을 만든다.
     *
     * 주문 생성은 order_attempts 를 거치도록 바뀌었다(이슈 #214). 시도를 만든 뒤
     * 즉시 전환해 기존 테스트가 기대하는 주문 id 를 돌려주고, 전환 과정에서
     * 생긴 order_items/payments/point_logs 도 함께 정리 대상으로 등록한다.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function createPaidOrderFromItems(
        int $userId,
        array $items,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0
    ): int {
        $attemptId = $this->trackAttempt((new OrderAttemptModel())->createAttempt(
            $userId,
            $this->shippingData(),
            $items,
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

        if ($orderId > 0) {
            $this->trackOrderItems($orderId);
            $this->trackPayments($orderId);
            $this->trackPointLogs($userId);
        }

        return $orderId;
    }

    /** 단일 상품으로 결제 확정된 주문을 만든다. */
    private function createPaidOrder(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0
    ): int {
        return $this->createPaidOrderFromItems(
            $userId,
            [$this->makeCartItem($product, $qty)],
            $couponId,
            $userCouponId,
            $couponDiscount,
            $pointUsed,
            $pointEarned
        );
    }

    /**
     * 레거시 pending 주문을 orders 에 직접 만든다.
     *
     * confirmPaid() 는 orders.status='pending' 인 레거시 주문에만 동작한다(이슈
     * #214 이후 신규 결제는 order_attempts 를 거쳐 바로 paid 로 전환되고 pending 을
     * 거치지 않는다). 이 헬퍼는 confirmPaid() 자체의 회귀 테스트(G 그룹)를 위해
     * 구 버전 주문 생성 로직이 만들던 pending 주문 모양을 그대로 재현한다.
     */
    private function insertLegacyPendingOrder(int $userId, array $product, int $qty = 1): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $amount = (int) $product['price'] * $qty;

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'OF-LEG-' . uniqid(),
            'status'                 => 'pending',
            'total_product_price'    => $amount,
            'shipping_fee'           => 0,
            'total_amount'           => $amount,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'payable_amount'         => $amount,
            'receiver_name'          => '테스트 수령인',
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
            'subtotal'      => $amount,
            'created_at'    => $now,
        ]);
        $this->trackOrderItems($orderId);

        return $orderId;
    }

    // ── P: 시도 기반 주문 생성 (createAttempt + convertAttempt) ────────────────

    /** P-01: payable_amount = product + shipping - coupon - point */
    public function testCreatePaidOrder_payableAmountCalculation(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(2000);
        $product = $this->insertProduct(['price' => 20000, 'shipping_type' => 'fixed', 'shipping_fee' => 3000]);
        $coupon  = $this->insertCoupon(['discount_value' => 5000]);

        $orderId = $this->createPaidOrder($userId, $product, 1, $coupon['id'], null, 5000, 2000, 0);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        // 20000 + 3000 - 5000 - 2000 = 16000
        $this->assertSame(16000, (int) $order['payable_amount']);
    }

    /** P-02: payable_amount 최소 0 (음수 불가) */
    public function testCreatePaidOrder_payableAmountMinZero(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(4000);
        $product = $this->insertProduct(['price' => 5000, 'shipping_type' => 'free']);
        $coupon  = $this->insertCoupon(['discount_value' => 3000]);

        // 5000 + 0 - 3000 - 4000 = -2000 → max(0, -2000) = 0
        $orderId = $this->createPaidOrder($userId, $product, 1, $coupon['id'], null, 3000, 4000, 0);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame(0, (int) $order['payable_amount']);
    }

    /** P-03: 쿠폰 확정 — user_coupon_id 경로 → status='used', used_count+1, order_id 연결 */
    public function testCreatePaidOrder_couponConfirm_userCouponIdPath(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon(['used_count' => 0]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $orderId = $this->createPaidOrder($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
        $this->assertSame((string) $orderId, (string) $uc['order_id']);

        $c = $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $c['used_count']);
    }

    /** P-04: 쿠폰 확정 — 코드 경로, 기존 issued UC 존재 → used로 전환 */
    public function testCreatePaidOrder_couponConfirm_codePathExistingUC(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        // userCouponId=null → 코드 경로
        $orderId = $this->createPaidOrder($userId, $product, 1, $coupon['id'], null, 3000);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
    }

    /** P-05: 쿠폰 확정 — 코드 경로, issued UC 없음 → user_coupons 신규 INSERT */
    public function testCreatePaidOrder_couponConfirm_codePathNewInsert(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon();

        $before = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->countAllResults();

        $orderId = $this->createPaidOrder($userId, $product, 1, $coupon['id'], null, 3000);

        // 새로 INSERT된 user_coupon을 cleanup 등록
        $newUC = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->get()->getRowArray();
        if ($newUC) {
            $this->cleanup['user_coupons'][] = (int) $newUC['id'];
        }

        $after = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->countAllResults();
        $this->assertSame($before + 1, $after);
        $this->assertSame('used', $newUC['status'] ?? '');
        $this->assertSame($orderId, (int) ($newUC['order_id'] ?? 0));
    }

    /** P-06: 포인트 차감 — point_balance 감소 + point_logs 기록 (order_id 연결) */
    public function testCreatePaidOrder_pointDeduction_updatesBalanceAndLogs(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $orderId = $this->createPaidOrder($userId, $product, 1, null, null, 0, 3000, 0);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(2000, (int) $user['point_balance']);

        $log = $db->table('point_logs')->where('user_id', $userId)->where('order_id', $orderId)->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame('use', $log['type']);
        $this->assertSame(-3000, (int) $log['amount']);
    }

    /** P-07: 포인트 잔액 부족 → 시도 자체가 롤백, orders 는 손대지 않음 */
    public function testCreateAttempt_pointInsufficient_rollsBack(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $result = $this->trackAttempt((new OrderAttemptModel())->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product)],
            null,
            null,
            0,
            6000,
            0
        ));

        $this->assertSame(0, $result);
        $count = $db->table('orders')->where('user_id', $userId)->countAllResults();
        $this->assertSame(0, $count);
    }

    /** P-08: 쿠폰 미사용 → user_coupons 불변 */
    public function testCreatePaidOrder_noCoupon_userCouponsUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $before  = $db->table('user_coupons')->where('user_id', $userId)->countAllResults();

        $this->createPaidOrder($userId, $product, 1, null, null, 0, 0, 0);

        $after = $db->table('user_coupons')->where('user_id', $userId)->countAllResults();
        $this->assertSame($before, $after);
    }

    /** P-09: 장바구니의 parent_product_id 가 주문 항목에 그대로 승계된다 */
    public function testCreatePaidOrder_addonParentProductIdInherited(): void
    {
        $mainProduct  = $this->insertProduct(['price' => 20000]);
        $addonProduct = $this->insertProduct(['price' => 3000]);
        $userId       = $this->insertUser();
        $mainProductId = $mainProduct['id'];

        $items = [
            $this->makeCartItem($mainProduct),
            $this->makeCartItem($addonProduct) + ['parent_product_id' => $mainProductId],
        ];

        $orderId = $this->createPaidOrderFromItems($userId, $items);

        $orderItems = db_connect()->table('order_items')->where('order_id', $orderId)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertNull($orderItems[0]['parent_product_id'], '본품 자체는 parent_product_id가 없어야 한다');
        $this->assertSame($mainProductId, (int) $orderItems[1]['parent_product_id'], '주문 항목에 부모가 승계돼야 한다');
    }

    // ── G: confirmPaid (레거시 pending 주문 회귀 테스트) ─────────────────────────

    /** G-01: payments.amount = payable_amount */
    public function testConfirmPaid_paymentAmountEqualsPayable(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['price' => 15000]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product);

        $result = $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->assertTrue($result);
        $this->trackPayments($orderId);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame(15000, (int) $payment['amount']);
    }

    /** G-02: 재고 차감 */
    public function testConfirmPaid_stockDeducted(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);

        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->trackPayments($orderId);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(7, (int) $p['stock']);
    }

    /**
     * G-03: 재고 부족 → false 반환, 재고는 그대로, 주문은 취소로 확정 (이슈 #113)
     *
     * 예전에는 pending 으로 방치돼 쿠폰·포인트가 30분간 묶였다. 이제 실패한 자리에서
     * 보상하고 취소로 전이한다(보상 자체는 OrderConfirmCompensationTest 가 본다).
     */
    public function testConfirmPaid_insufficientStock_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 2]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);

        $result = $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(2, (int) $p['stock']);
    }

    /** G-04: 이미 결제된 주문에 중복 confirmPaid → false, 재고 이중 차감 없음 */
    public function testConfirmPaid_alreadyPaid_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);

        // 첫 번째 confirmPaid 성공
        $result1 = $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->assertTrue($result1);
        $this->trackPayments($orderId);

        $stockAfterFirst = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];

        // 두 번째 호출 — 이미 paid 상태이므로 pending 조건 미충족 → false
        $result2 = $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->assertFalse($result2);

        // 재고는 첫 번째 차감분만 유지
        $stockNow = (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock'];
        $this->assertSame($stockAfterFirst, $stockNow);
    }

    /** G-05: stock=qty → status='sold_out' */
    public function testConfirmPaid_stockReachesZero_setsStatusSoldOut(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 3]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product, 3);
        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->trackPayments($orderId);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(0, (int) $p['stock']);
        $this->assertSame('sold_out', $p['status']);
    }

    /** G-06: 장바구니에서 주문 상품 삭제 */
    public function testConfirmPaid_cartItemsDeleted(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        // cart_item 삽입
        $db->table('cart_items')->insert([
            'user_id'    => $userId,
            'product_id' => $product['id'],
            'qty'        => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $orderId = $this->insertLegacyPendingOrder($userId, $product);
        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->trackPayments($orderId);

        $count = $db->table('cart_items')->where('user_id', $userId)->where('product_id', $product['id'])->countAllResults();
        $this->assertSame(0, $count);
    }

    // ── B: confirmBankTransfer ────────────────────────────────────────────────

    /** awaiting_payment 주문 + bank_transfer pending 결제 생성 헬퍼 */
    private function createBankTransferOrder(int $userId, array $product, int $qty = 1): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('orders')->insert([
            'user_id'               => $userId,
            'order_number'          => 'BK' . uniqid(),
            'status'                => 'awaiting_payment',
            'total_product_price'   => $product['price'] * $qty,
            'shipping_fee'          => 0,
            'total_amount'          => $product['price'] * $qty,
            'coupon_discount_amount' => 0,
            'point_used_amount'     => 0,
            'point_earned_amount'   => 0,
            'payable_amount'        => $product['price'] * $qty,
            'receiver_name'         => '테스트',
            'receiver_phone'        => '010-0000-0000',
            'zipcode'               => '12345',
            'address1'              => '서울시',
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $product['id'],
            'product_name'  => $product['name'],
            'product_price' => $product['price'],
            'cost_price'    => 0,
            'qty'           => $qty,
            'subtotal'      => $product['price'] * $qty,
            'created_at'    => $now,
        ]);
        $itemId = (int) $db->insertID();
        $this->cleanup['order_items'][] = $itemId;

        $db->table('payments')->insert([
            'order_id'    => $orderId,
            'pg_provider' => 'bank_transfer',
            'pg_tid'      => null,
            'method'      => '무통장입금',
            'amount'      => $product['price'] * $qty,
            'status'      => 'pending',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $this->cleanup['payments'][] = (int) $db->insertID();

        return $orderId;
    }

    /** B-01: awaiting_payment → paid 전환 */
    public function testConfirmBankTransfer_normalFlow_setsStatusPaid(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 5]);
        $orderId = $this->createBankTransferOrder($userId, $product, 2);

        $result = $this->model->confirmBankTransfer($orderId);
        $this->assertTrue($result);

        $order   = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();

        $this->assertSame('paid', $order['status']);
        $this->assertSame('paid', $payment['status']);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(3, (int) $p['stock']);
    }

    /**
     * B-02: 재고 부족 → false, 재고는 그대로, 주문은 취소로 확정 (이슈 #113)
     *
     * awaiting_payment 는 expirePending() 대상이 아니라 예전에는 복구 경로가 아예
     * 없었다 — 쿠폰·포인트가 영구히 묶이던 자리다.
     */
    public function testConfirmBankTransfer_insufficientStock_rollsBack(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 1]);
        $orderId = $this->createBankTransferOrder($userId, $product, 3);

        $result = $this->model->confirmBankTransfer($orderId);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $p['stock']);
    }

    /** B-03: pending 상태 주문에는 적용 불가 */
    public function testConfirmBankTransfer_pendingOrder_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $orderId = $this->insertLegacyPendingOrder($userId, $product);

        $result = $this->model->confirmBankTransfer($orderId);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $order['status']);
    }
}
