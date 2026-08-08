<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\PG\PGInterface;
use App\Libraries\PgCancellationService;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PG 청구분 환불 추적 (이슈 #113 잔여 범위)
 *
 * confirmPaid() 는 재고 차감이 payments INSERT 보다 먼저라, 재고 부족으로
 * 롤백되면 **PG 청구 흔적이 DB 에 전혀 남지 않았다**. 고객은 청구됐는데
 * 시스템에는 단서가 로그뿐이었다.
 *
 * 이제 청구 사실을 payments 에 'paid' 로 남긴다. 주문은 cancelled 이므로
 * "cancelled 주문 + paid 결제" 조합이 곧 환불 필요 신호가 된다
 * (정상 취소 경로는 cancelOrder() 가 결제행도 cancelled 로 바꾼다).
 *
 * 실제 취소 호출은 관리자가 버튼을 눌렀을 때만 일어난다.
 *
 * @internal
 */
final class PgRefundPendingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private const PRODUCT_PRICE = 20000;
    private const POINT_BALANCE = 10000;
    private const POINT_USED    = 3000;
    private const COUPON_VALUE  = 5000;

    private OrderModel $model;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'order_status_logs' => [],
        'point_logs'        => [],
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

    // ── 픽스처 ────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'prp_' . $uid,
            'email'         => 'prp-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'PrpUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => self::POINT_BALANCE,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function insertProduct(int $stock): array
    {
        $db   = db_connect();
        $data = [
            'name'           => 'PrpProduct_' . uniqid(),
            'slug'           => 'prp-prod-' . uniqid(),
            'price'          => self::PRODUCT_PRICE,
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
     * 쿠폰·포인트를 실제로 소진하는 정상 경로로 pending 주문을 만든다.
     *
     * @param  array<string, mixed> $product
     * @return array{order_id: int, user_id: int}
     */
    private function createConsumedOrder(array $product, int $qty, int $couponDiscount = self::COUPON_VALUE): array
    {
        $db     = db_connect();
        $userId = $this->insertUser();

        $db->table('coupons')->insert([
            'code'                => 'PRP-' . strtoupper(uniqid()),
            'name'                => 'PrpCoupon',
            'type'                => 'fixed',
            'discount_value'      => $couponDiscount,
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

        $orderId = $this->model->createPending(
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
                'qty'            => $qty,
                'shipping_type'  => 'free',
                'shipping_fee'   => 0,
                'free_threshold' => 0,
            ]],
            $couponId,
            null,
            $couponDiscount,
            self::POINT_USED,
        );

        $this->assertGreaterThan(0, $orderId, '픽스처 주문 생성 실패');
        $this->cleanup['orders'][] = $orderId;
        $this->track($orderId, $userId);

        return ['order_id' => $orderId, 'user_id' => $userId];
    }

    private function track(int $orderId, int $userId): void
    {
        $db = db_connect();
        foreach (['order_items', 'payments', 'order_status_logs', 'user_coupons'] as $table) {
            $col = $table === 'user_coupons' ? 'order_id' : 'order_id';
            $ids = array_column(
                $db->table($table)->select('id')->where($col, $orderId)->get()->getResultArray(),
                'id'
            );
            $this->cleanup[$table] = array_merge($this->cleanup[$table], $ids);
        }
        $ids = array_column(
            $db->table('point_logs')->select('id')->where('user_id', $userId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['point_logs'] = array_merge($this->cleanup['point_logs'], $ids);
    }

    /** @return array<string, mixed>|null */
    private function paymentOf(int $orderId): ?array
    {
        return db_connect()->table('payments')->where('order_id', $orderId)->get()->getRowArray();
    }

    // ── 청구 기록 ─────────────────────────────────────────────────────────────

    /** 확정에 실패해도 PG 청구 사실은 남아야 한다 — 지금까지는 흔적이 없었다. */
    public function testFailedConfirmRecordsTheCharge(): void
    {
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $tid     = 'tid_' . uniqid();

        $this->model->confirmPaid($ctx['order_id'], 'toss', $tid, 'card', ['ok' => true]);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $payment = $this->paymentOf($ctx['order_id']);
        $this->assertNotNull($payment, 'PG 청구 기록이 남지 않았습니다.');
        $this->assertSame($tid, $payment['pg_tid']);
        $this->assertSame('toss', $payment['pg_provider']);
        $this->assertSame('paid', $payment['status'], '청구는 실제로 일어났으므로 paid 여야 합니다.');
        $this->assertGreaterThan(0, (int) $payment['amount']);
    }

    /** 주문은 취소, 결제는 paid — 이 조합이 환불 필요 신호다. */
    public function testFailedConfirmLeavesRefundPendingSignal(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);
        $this->assertSame('paid', $this->paymentOf($ctx['order_id'])['status']);

        $this->assertSame([$ctx['order_id']], array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->model->findRefundPending()
        ));
    }

    /** 청구가 없는 무료 주문은 결제 기록을 남기지 않는다. */
    public function testFreeOrderFailureRecordsNoCharge(): void
    {
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5, self::PRODUCT_PRICE * 5);

        $this->model->confirmFree($ctx['order_id']);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $this->assertNull($this->paymentOf($ctx['order_id']), '무료 주문에 결제 기록이 생겼습니다.');
        $this->assertSame([], $this->model->findRefundPending());
    }

    /** 정상 확정된 주문은 환불 필요 목록에 뜨지 않는다. */
    public function testSuccessfulOrderIsNotRefundPending(): void
    {
        $product = $this->insertProduct(10);
        $ctx     = $this->createConsumedOrder($product, 1);

        $this->assertTrue($this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $this->assertSame([], $this->model->findRefundPending());
    }

    // ── 관리자 취소 실행 ──────────────────────────────────────────────────────

    /** 취소가 성공하면 결제행을 cancelled 로 확정한다. */
    public function testAdminCancelMarksPaymentCancelledOnSuccess(): void
    {
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $pg = $this->createStub(PGInterface::class);
        $pg->method('cancel')->willReturn(['success' => true, 'message' => '취소 완료']);

        $result = new PgCancellationService()->cancelCharge($pg, $ctx['order_id']);

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $this->paymentOf($ctx['order_id'])['status']);
        $this->assertSame([], $this->model->findRefundPending(), '취소 후에도 환불 필요로 남아 있습니다.');
    }

    /** 취소에 실패하면 paid 로 남겨 다시 시도할 수 있어야 한다. */
    public function testAdminCancelKeepsPaidOnFailure(): void
    {
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $pg = $this->createStub(PGInterface::class);
        $pg->method('cancel')->willReturn(['success' => false, 'message' => '이미 취소된 거래']);

        $result = new PgCancellationService()->cancelCharge($pg, $ctx['order_id']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('이미 취소된 거래', $result['message']);
        $this->assertSame('paid', $this->paymentOf($ctx['order_id'])['status'], '실패했는데 취소로 표시됐습니다.');
        $this->assertNotSame([], $this->model->findRefundPending(), '재시도할 수 있게 목록에 남아야 합니다.');
    }

    /** 취소는 청구된 금액 그대로 요청해야 한다. */
    public function testAdminCancelRequestsRecordedAmount(): void
    {
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $tid     = 'tid_' . uniqid();
        $this->model->confirmPaid($ctx['order_id'], 'toss', $tid, 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $charged = (int) $this->paymentOf($ctx['order_id'])['amount'];

        $pg = $this->createMock(PGInterface::class);
        $pg->expects($this->once())
            ->method('cancel')
            ->with($tid, $charged, $this->anything())
            ->willReturn(['success' => true, 'message' => '취소 완료']);

        new PgCancellationService()->cancelCharge($pg, $ctx['order_id']);
    }

    /** 이미 취소된 건에 다시 요청하면 PG 를 호출하지 않는다 (중복 취소 방어). */
    public function testAdminCancelIsRejectedWhenNothingToRefund(): void
    {
        $product = $this->insertProduct(10);
        $ctx     = $this->createConsumedOrder($product, 1);
        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $pg = $this->createMock(PGInterface::class);
        $pg->expects($this->never())->method('cancel');

        $result = new PgCancellationService()->cancelCharge($pg, $ctx['order_id']);

        $this->assertFalse($result['success']);
    }
}
