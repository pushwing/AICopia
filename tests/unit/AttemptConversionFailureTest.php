<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Order\ConversionFailure;
use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 전환 실패 원인 구분 — 이슈 #214 PR2
 *
 * convertAttempt() 는 실패를 0 으로 뭉뚱그리지 않고 ConversionFailure 로 구분해
 * 돌려준다. 원인마다 "보상 주문 + paid 결제행이 남아 환불 추적이 되는지"가 다르고,
 * 컨트롤러의 로그·사용자 안내가 그 차이에 따라 달라져야 하기 때문이다.
 *
 * @internal
 */
final class AttemptConversionFailureTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel        $orderModel;
    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'orders'         => [],
        'products'       => [],
        'users'          => [],
        'coupons'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'point_logs', 'payments', 'order_items'] as $table) {
                $db->table($table)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('user_coupons')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        if ($this->cleanup['coupons'] !== []) {
            $db->table('user_coupons')->whereIn('coupon_id', $this->cleanup['coupons'])->delete();
            $db->table('coupons')->whereIn('id', $this->cleanup['coupons'])->delete();
        }
        foreach (['products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'acf_' . $uid,
            'email'         => 'acf-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'ACFUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'ACFProd_' . uniqid(),
            'slug'           => 'acf-prod-' . uniqid(),
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
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /** @param array<string, mixed> $overrides */
    private function insertCoupon(array $overrides = []): int
    {
        $db   = db_connect();
        $data = array_merge([
            'code'                => 'ACF' . uniqid(),
            'name'                => 'ACF쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 1000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => 10,
            'used_count'          => 5,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $overrides);
        $db->table('coupons')->insert($data);
        $id                         = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $product */
    private function createAttempt(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        int $couponDiscountAmount = 0,
        int $pointUsed = 0
    ): int {
        $id = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
                'address2'       => null,
                'delivery_memo'  => null,
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => $qty,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            $couponId,
            null,
            $couponDiscountAmount,
            $pointUsed,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    /** 이 시도가 만들어 낸 주문(성공·보상 모두)을 정리 대상으로 등록한다. */
    private function trackOrdersOf(int $userId): void
    {
        $rows = db_connect()->table('orders')->select('id')->where('user_id', $userId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['orders'][] = (int) $row['id'];
        }
    }

    // ── 성공 ─────────────────────────────────────────────────────────────────

    /** F-01: 전환에 성공하면 실패 원인은 비어 있고 주문 id 가 실린다 */
    public function testConvertAttempt_success_carriesOrderIdAndNoFailure(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 2);

        $result = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', []);
        $this->trackOrdersOf($userId);

        $this->assertTrue($result->succeeded());
        $this->assertGreaterThan(0, $result->orderId);
        $this->assertNull($result->failure);
    }

    // ── 재고 부족 (보상 O) ────────────────────────────────────────────────────

    /**
     * F-02: 재고 부족은 OutOfStock 으로 보고되고, 취소 주문 + paid 결제행이 남아
     * 관리자 "환불 필요" 목록에 자동으로 뜬다.
     */
    public function testConvertAttempt_insufficientStock_reportsOutOfStockAndLeavesRefundTrail(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 1]);
        $attemptId = $this->createAttempt($userId, $product, 5);
        $tid       = 'TID-OOS-' . uniqid();

        $result = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', $tid, 'card', []);
        $this->trackOrdersOf($userId);

        $this->assertFalse($result->succeeded());
        $this->assertSame(ConversionFailure::OutOfStock, $result->failure);
        $this->assertTrue($result->compensated, '재고 부족은 보상 주문이 남아 환불 추적이 가능해야 한다');
        $this->assertSame(0, $result->orderId);

        $order = $db->table('orders')->where('user_id', $userId)->get()->getRowArray();
        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order['status']);

        $pending = array_column($this->orderModel->findRefundPending(), 'pg_tid');
        $this->assertContains($tid, $pending, '환불 필요 목록에 떠야 한다');
    }

    // ── fail-closed 거부 (보상 X) ────────────────────────────────────────────

    /**
     * F-03: 이미 확정(failed)된 시도에 승인이 늦게 도착하면 AlreadyFinalized 로
     * 보고된다. 이 경로는 아무 행도 남기지 않으므로 환불 추적이 불가능하다 —
     * 재고 부족과 절대 같은 메시지로 뭉뚱그리면 안 되는 이유다.
     */
    public function testConvertAttempt_alreadyFinalizedAttempt_reportsAlreadyFinalizedWithoutCompensation(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product);

        $this->assertTrue($this->attemptModel->markFailed($attemptId, '결제창 이탈'));

        $result = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-LATE-' . uniqid(), 'card', []);
        $this->trackOrdersOf($userId);

        $this->assertFalse($result->succeeded());
        $this->assertSame(ConversionFailure::AlreadyFinalized, $result->failure);
        $this->assertFalse($result->compensated, 'fail-closed 거부는 보상 주문을 남기지 않는다');
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());
        $this->assertSame([], $this->orderModel->findRefundPending());
    }

    // ── 스냅샷 손상 (보상 O) ──────────────────────────────────────────────────

    /** F-04: 스냅샷이 비면 CorruptSnapshot 으로 보고되고 보상 경로를 탄다 */
    public function testConvertAttempt_emptySnapshot_reportsCorruptSnapshotAndCompensates(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product);
        $tid       = 'TID-SNAP-' . uniqid();

        $db->table('order_attempts')->where('id', $attemptId)->update(['items_snapshot' => '[]']);

        $result = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', $tid, 'card', []);
        $this->trackOrdersOf($userId);

        $this->assertFalse($result->succeeded());
        $this->assertSame(ConversionFailure::CorruptSnapshot, $result->failure);
        $this->assertTrue($result->compensated);

        $pending = array_column($this->orderModel->findRefundPending(), 'pg_tid');
        $this->assertContains($tid, $pending);
    }

    // ── 금액 불일치 (컨트롤러가 부르는 보상 진입점) ─────────────────────────────

    /**
     * F-05: 승인 금액이 시도 금액과 다르면 컨트롤러가 이 진입점으로 보상을 남긴다.
     * 지금까지는 아무 행도 만들지 않고 /order/fail 로만 보내 청구가 추적 불가였다.
     */
    public function testCompensateChargedAttempt_amountMismatch_leavesRefundTrailAndRestoresReservation(): void
    {
        $db       = db_connect();
        $userId   = $this->insertUser();
        $product  = $this->insertProduct(['stock' => 10]);
        $couponId = $this->insertCoupon(['used_count' => 5, 'total_qty' => 10]);

        $db->table('users')->where('id', $userId)->update(['point_balance' => 5000]);

        $attemptId = $this->createAttempt($userId, $product, 1, $couponId, 1000, 2000);
        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(3000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(6, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count']);

        $attempt = $this->attemptModel->withItems(
            $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()
        );
        $tid = 'TID-MISMATCH-' . uniqid();

        $result = $this->orderModel->compensateChargedAttempt($attempt, ConversionFailure::AmountMismatch, [
            'pg_provider' => 'toss',
            'pg_tid'      => $tid,
            'method'      => 'card',
            'amount'      => 99000,
            'raw'         => [],
        ]);
        $this->trackOrdersOf($userId);

        $this->assertFalse($result->succeeded());
        $this->assertSame(ConversionFailure::AmountMismatch, $result->failure);
        $this->assertTrue($result->compensated);

        // 청구 추적 — 취소 주문 + paid 결제행
        $pending = array_column($this->orderModel->findRefundPending(), 'pg_tid');
        $this->assertContains($tid, $pending, '금액 불일치 청구가 환불 필요 목록에 떠야 한다');

        // 시도는 failed 로 확정되고 선점한 쿠폰·포인트는 정확히 1회 복구된다.
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(5, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count']);

        // 재고는 건드리지 않는다(전환 자체를 시작하지 않았다).
        $this->assertSame(10, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
    }

    // ── 원인별 안내 문구 ──────────────────────────────────────────────────────

    /**
     * F-06: PG 자동 취소는 미구현(TODO #113)이다 — 어떤 실패 문구도 "자동 환불"을
     * 약속해선 안 되고, 원인이 다르면 문구도 달라야 한다.
     */
    public function testConversionFailure_messagesAreDistinctAndPromiseNoAutoRefund(): void
    {
        $messages = [];

        foreach (ConversionFailure::cases() as $failure) {
            $message = $failure->userMessage();
            $this->assertNotSame('', $message);
            $this->assertStringNotContainsString('자동 환불', $message, "{$failure->value}: 자동 환불을 약속하면 안 된다");
            $this->assertNotSame('', $failure->note());
            $messages[$failure->value] = $message;

            // 청구가 없는 경로(무료 주문·무통장)에는 환불 안내를 붙이면 안 된다.
            $this->assertStringNotContainsString(
                '환불',
                $failure->userMessage(charged: false),
                "{$failure->value}: 청구가 없는데 환불을 안내하면 안 된다"
            );
        }

        $this->assertStringContainsString('재고', $messages['out_of_stock']);
        $this->assertStringNotContainsString('재고', $messages['amount_mismatch'], '금액 불일치를 재고 부족으로 안내하면 안 된다');
        $this->assertStringNotContainsString('재고', $messages['already_finalized'], 'fail-closed 거부를 재고 부족으로 안내하면 안 된다');

        // 보상 경로를 타는 실패만 "환불 추적 가능"으로 분류된다.
        $this->assertTrue(ConversionFailure::OutOfStock->compensates());
        $this->assertTrue(ConversionFailure::CorruptSnapshot->compensates());
        $this->assertTrue(ConversionFailure::OrderNumberConflict->compensates());
        $this->assertTrue(ConversionFailure::AmountMismatch->compensates());
        $this->assertFalse(ConversionFailure::AlreadyFinalized->compensates());
        $this->assertFalse(ConversionFailure::PaymentConflict->compensates());
        $this->assertFalse(ConversionFailure::CommitFailed->compensates());
        $this->assertFalse(ConversionFailure::Unknown->compensates());
    }
}
