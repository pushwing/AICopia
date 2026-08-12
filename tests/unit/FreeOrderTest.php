<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 무료 주문 확정 — OrderModel::confirmFree()
 *
 * payable_amount 가 0 인 주문(100% 할인 쿠폰, 포인트 전액 사용)은 PG 를 거치지
 * 않고 주문 생성과 동시에 paid 로 확정한다. 재고 차감·장바구니 비우기·상태
 * 로그는 PG 결제와 동일하게 처리해야 한다.
 *
 * @internal
 */
final class FreeOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'order_attempts'    => [],
        'order_status_logs' => [],
        'cart_items'        => [],
        'payments'          => [],
        'order_items'       => [],
        'orders'            => [],
        'user_coupons'      => [],
        'coupons'           => [],
        'products'          => [],
        'users'             => [],
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

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'free_' . $uid,
            'email'         => 'free-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'FreeOrderUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function insertProduct(int $stock = 10): array
    {
        $db   = db_connect();
        $data = [
            'name'           => 'FreeProduct_' . uniqid(),
            'slug'           => 'free-prod-' . uniqid(),
            'price'          => 20000,
            'cost_price'     => 0,
            'stock'          => $stock,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return array_merge(['id' => $id], $data);
    }

    /**
     * payable_amount = 0 인 pending 주문을 만든다.
     *
     * @param array<string, mixed> $product
     */
    private function insertFreeOrder(int $userId, array $product, int $qty = 1): int
    {
        $db     = db_connect();
        $now    = date('Y-m-d H:i:s');
        $amount = (int) $product['price'] * $qty;

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'ORD-FREE-' . uniqid(),
            'status'                 => 'pending',
            'total_product_price'    => $amount,
            'shipping_fee'           => 0,
            'total_amount'           => $amount,
            'coupon_discount_amount' => $amount,   // 100% 할인 쿠폰
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'payable_amount'         => 0,
            'receiver_name'          => '테스트 수령인',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트구',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => (int) $product['id'],
            'sku_id'        => null,
            'product_name'  => $product['name'],
            'product_price' => (int) $product['price'],
            'cost_price'    => 0,
            'qty'           => $qty,
            'subtotal'      => $amount,
            'created_at'    => $now,
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();

        return $orderId;
    }

    private function trackSideEffects(int $orderId): void
    {
        $db = db_connect();
        foreach (['payments', 'order_status_logs', 'order_items', 'user_coupons'] as $table) {
            $ids = array_column(
                $db->table($table)->select('id')->where('order_id', $orderId)->get()->getResultArray(),
                'id'
            );
            $this->cleanup[$table] = array_merge($this->cleanup[$table], $ids);
        }
    }

    // ── 확정 ──────────────────────────────────────────────────────────────────

    /** 0원 주문은 PG 없이 바로 paid 로 확정된다. */
    public function testConfirmFreeMarksOrderPaid(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertFreeOrder($userId, $product);

        $this->assertTrue($this->model->confirmFree($orderId));
        $this->trackSideEffects($orderId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $order['status']);
        $this->assertNotNull($order['paid_at']);
    }

    /** 무료 주문도 결제 이력을 남긴다 — pg_tid 는 없고 금액은 0원. */
    public function testConfirmFreeRecordsZeroAmountPayment(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertFreeOrder($userId, $product);

        $this->model->confirmFree($orderId);
        $this->trackSideEffects($orderId);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertNotNull($payment, '결제 이력이 남지 않았습니다.');
        $this->assertSame('free', $payment['pg_provider']);
        $this->assertNull($payment['pg_tid'], 'PG 거래번호가 없어야 합니다.');
        $this->assertSame(0, (int) $payment['amount']);
        $this->assertSame('paid', $payment['status']);
    }

    /**
     * 무료 주문이 두 건 이상 쌓여도 확정된다.
     *
     * payments.pg_tid 에 UNIQUE 제약이 있어, 빈 문자열을 넣으면 두 번째 주문이
     * 중복으로 거부된다. NULL 이어야 여러 건이 공존한다.
     */
    public function testSecondFreeOrderIsNotBlockedByUniquePgTid(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $first  = $this->insertFreeOrder($userId, $product);
        $second = $this->insertFreeOrder($userId, $product);

        $this->assertTrue($this->model->confirmFree($first));
        $this->trackSideEffects($first);

        $this->assertTrue($this->model->confirmFree($second), '두 번째 무료 주문이 확정되지 않았습니다.');
        $this->trackSideEffects($second);
    }

    /** PG 결제와 마찬가지로 확정 시점에 재고를 차감한다. */
    public function testConfirmFreeDeductsStock(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(10);
        $orderId = $this->insertFreeOrder($userId, $product, 3);

        $this->model->confirmFree($orderId);
        $this->trackSideEffects($orderId);

        $stock = (int) $db->table('products')->select('stock')->where('id', $product['id'])->get()->getRow()->stock;
        $this->assertSame(7, $stock);
    }

    /**
     * 재고가 모자라면 확정하지 않고 주문을 취소로 되돌린다 (이슈 #113).
     *
     * 결제 이력은 남기지 않는다 — 애초에 결제가 없었다.
     */
    public function testConfirmFreeFailsWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(1);
        $orderId = $this->insertFreeOrder($userId, $product, 5);

        $this->assertFalse($this->model->confirmFree($orderId));
        $this->trackSideEffects($orderId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);
        $this->assertSame(0, $db->table('payments')->where('order_id', $orderId)->countAllResults());
    }

    /**
     * 100% 할인 쿠폰 주문의 실제 경로 — 시도를 만들고 그대로 무료 확정한다.
     *
     * 앞의 테스트들은 주문 행을 직접 넣어 confirmFree 만 떼어 본다. 여기서는
     * 실제 운영 코드(OrderController)가 쓰는 경로 그대로 — order_attempts 로
     * 쿠폰 확정·payable_amount 산출까지 거친 뒤 convertAttempt() 로 바로
     * paid 확정한다(이슈 #214). 무료 주문은 pending 을 거치지 않으므로
     * confirmFree() 는 더 이상 이 경로에 관여하지 않는다.
     */
    public function testHundredPercentCouponOrderIsCreatedAndConfirmed(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $db->table('coupons')->insert([
            'code'                => 'FREE-' . strtoupper(uniqid()),
            'name'                => '100% 할인 쿠폰',
            'type'                => 'fixed',
            'discount_value'      => (int) $product['price'],
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $couponId = (int) $db->insertID();
        $this->cleanup['coupons'][] = $couponId;

        $attemptId = (new OrderAttemptModel())->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트 수령인',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => 1,
                'shipping_type'  => 'free',
                'shipping_fee'   => 0,
                'free_threshold' => 0,
            ]],
            $couponId,
            null,
            (int) $product['price'],   // 상품가 전액 할인 → payable_amount = 0
            0,
            0,
            'free',
        );

        $this->assertGreaterThan(0, $attemptId, '주문 시도가 생성되지 않았습니다.');
        $this->cleanup['order_attempts'][] = $attemptId;

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame(0, (int) $attempt['payable_amount']);

        // 실제 컨트롤러(OrderController)와 동일하게 free provider 로 바로 paid 전환한다.
        $orderId = $this->model->convertAttempt($attemptId, 'paid', 'free', null, 'free', ['reason' => 'payable_amount = 0']);
        $this->assertGreaterThan(0, $orderId, '무료 주문이 확정되지 않았습니다.');
        $this->cleanup['orders'][] = $orderId;
        $this->trackSideEffects($orderId);

        $confirmed = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $confirmed['status']);
        $this->assertSame(0, (int) $confirmed['payable_amount']);
    }

    /** 이미 확정된 주문을 다시 확정하려 하면 거부한다. */
    public function testConfirmFreeIsRejectedForNonPendingOrder(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->insertFreeOrder($userId, $product);

        $this->assertTrue($this->model->confirmFree($orderId));
        $this->trackSideEffects($orderId);

        $this->assertFalse($this->model->confirmFree($orderId), '중복 확정이 허용됐습니다.');
    }
}
