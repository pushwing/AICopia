<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel — createPending(P), confirmPaid(G), confirmBankTransfer(B)
 * 이슈 #12 · 2단계
 */
final class OrderFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    private array $cleanup = [
        'cart_items'   => [],
        'point_logs'   => [],
        'payments'     => [],
        'order_items'  => [],
        'orders'       => [],
        'user_coupons' => [],
        'coupons'      => [],
        'products'     => [],
        'users'        => [],
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

    /** createPending 후 생성된 orderId를 cleanup에 등록 */
    private function trackOrder(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
        }
        return $orderId;
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

    // ── P: createPending ──────────────────────────────────────────────────────

    /** P-01: payable_amount = product + shipping - coupon - point */
    public function testCreatePending_payableAmountCalculation(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(2000);
        $product = $this->insertProduct(['price' => 20000, 'shipping_type' => 'fixed', 'shipping_fee' => 3000]);
        $coupon  = $this->insertCoupon(['discount_value' => 5000]);
        $items   = [$this->makeCartItem($product)];

        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, $coupon['id'], null, 5000, 2000, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);
        $this->trackPointLogs($userId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        // 20000 + 3000 - 5000 - 2000 = 16000
        $this->assertSame(16000, (int) $order['payable_amount']);
    }

    /** P-02: payable_amount 최소 0 (음수 불가) */
    public function testCreatePending_payableAmountMinZero(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(4000);
        $product = $this->insertProduct(['price' => 5000, 'shipping_type' => 'free']);
        $coupon  = $this->insertCoupon(['discount_value' => 3000]);
        $items   = [$this->makeCartItem($product)];

        // 5000 + 0 - 3000 - 4000 = -2000 → max(0, -2000) = 0
        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, $coupon['id'], null, 3000, 4000, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);
        $this->trackPointLogs($userId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame(0, (int) $order['payable_amount']);
    }

    /** P-03: 쿠폰 확정 — user_coupon_id 경로 → status='used', used_count+1 */
    public function testCreatePending_couponConfirm_userCouponIdPath(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon(['used_count' => 0]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');
        $items        = [$this->makeCartItem($product)];

        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, $coupon['id'], $userCouponId, 3000, 0, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
        $this->assertSame((string) $orderId, (string) $uc['order_id']);

        $c = $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $c['used_count']);
    }

    /** P-04: 쿠폰 확정 — 코드 경로, 기존 issued UC 존재 → used로 전환 */
    public function testCreatePending_couponConfirm_codePathExistingUC(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');
        $items        = [$this->makeCartItem($product)];

        // userCouponId=null → 코드 경로
        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, $coupon['id'], null, 3000, 0, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
    }

    /** P-05: 쿠폰 확정 — 코드 경로, issued UC 없음 → user_coupons 신규 INSERT */
    public function testCreatePending_couponConfirm_codePathNewInsert(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon();
        $items   = [$this->makeCartItem($product)];

        $before  = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->countAllResults();

        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, $coupon['id'], null, 3000, 0, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);

        // 새로 INSERT된 user_coupon을 cleanup 등록
        $newUC = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->get()->getRowArray();
        if ($newUC) {
            $this->cleanup['user_coupons'][] = (int) $newUC['id'];
        }

        $after = $db->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $coupon['id'])->countAllResults();
        $this->assertSame($before + 1, $after);
        $this->assertSame('used', $newUC['status'] ?? '');
    }

    /** P-06: 포인트 차감 — point_balance 감소 + point_logs 기록 */
    public function testCreatePending_pointDeduction_updatesBalanceAndLogs(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();
        $items   = [$this->makeCartItem($product)];

        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, null, null, 0, 3000, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);
        $this->trackPointLogs($userId);

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(2000, (int) $user['point_balance']);

        $log = $db->table('point_logs')->where('user_id', $userId)->where('order_id', $orderId)->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame('use', $log['type']);
        $this->assertSame(-3000, (int) $log['amount']);
    }

    /** P-07: 포인트 잔액 부족 → 롤백, 주문 생성 안 됨 */
    public function testCreatePending_pointInsufficient_rollsBack(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();
        $items   = [$this->makeCartItem($product)];

        $result = $this->model->createPending($userId, $this->shippingData(), $items, null, null, 0, 6000, 0);

        $this->assertSame(0, $result);
        $count = $db->table('orders')->where('user_id', $userId)->countAllResults();
        $this->assertSame(0, $count);
    }

    /** P-08: 쿠폰 미사용 → user_coupons 불변 */
    public function testCreatePending_noCoupon_userCouponsUnchanged(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $items   = [$this->makeCartItem($product)];
        $before  = $db->table('user_coupons')->where('user_id', $userId)->countAllResults();

        $orderId = $this->model->createPending($userId, $this->shippingData(), $items, null, null, 0, 0, 0);
        $this->trackOrder($orderId);
        $this->trackOrderItems($orderId);

        $after = $db->table('user_coupons')->where('user_id', $userId)->countAllResults();
        $this->assertSame($before, $after);
    }

    // ── G: confirmPaid ────────────────────────────────────────────────────────

    /** G-01: payments.amount = payable_amount */
    public function testConfirmPaid_paymentAmountEqualsPayable(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['price' => 15000]);
        $items   = [$this->makeCartItem($product)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);

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
        $items   = [$this->makeCartItem($product, 3)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);

        $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->trackPayments($orderId);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(7, (int) $p['stock']);
    }

    /** G-03: 재고 부족 → false 반환, 주문 여전히 pending */
    public function testConfirmPaid_insufficientStock_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 2]);
        $items   = [$this->makeCartItem($product, 3)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);

        $result = $this->model->confirmPaid($orderId, 'toss', 'tid_' . uniqid(), 'card', []);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $order['status']);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(2, (int) $p['stock']);
    }

    /** G-04: 이미 결제된 주문에 중복 confirmPaid → false, 재고 이중 차감 없음 */
    public function testConfirmPaid_alreadyPaid_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $items   = [$this->makeCartItem($product, 3)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);

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
        $items   = [$this->makeCartItem($product, 3)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);
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
        $items   = [$this->makeCartItem($product)];

        // cart_item 삽입
        $db->table('cart_items')->insert([
            'user_id'    => $userId,
            'product_id' => $product['id'],
            'qty'        => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);
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
            'coupon_discount_amount'=> 0,
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

    /** B-02: 재고 부족 → false, order status 불변 */
    public function testConfirmBankTransfer_insufficientStock_rollsBack(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 1]);
        $orderId = $this->createBankTransferOrder($userId, $product, 3);

        $result = $this->model->confirmBankTransfer($orderId);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('awaiting_payment', $order['status']);

        $p = $db->table('products')->where('id', $product['id'])->get()->getRowArray();
        $this->assertSame(1, (int) $p['stock']);
    }

    /** B-03: pending 상태 주문에는 적용 불가 */
    public function testConfirmBankTransfer_pendingOrder_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $items   = [$this->makeCartItem($product)];

        $orderId = $this->trackOrder($this->model->createPending($userId, $this->shippingData(), $items));
        $this->trackOrderItems($orderId);

        $result = $this->model->confirmBankTransfer($orderId);
        $this->assertFalse($result);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $order['status']);
    }
}
