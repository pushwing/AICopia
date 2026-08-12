<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\PaymentController;
use App\Libraries\PG\PGInterface;
use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 승인 뒤 확정에 실패한 청구가 관리자 "환불 필요" 목록에서 사라지지 않는지 (이슈 #214 PR2).
 *
 * 금액 2차 검증 실패는 $pg->confirm() 이 이미 성공한 뒤라 청구가 살아 있는데,
 * 기존 구현은 곧장 /order/fail 로 보내고 OrderController::fail() 이 시도를 failed
 * 로 확정해 버려 orders·payments 어디에도 흔적이 남지 않았다.
 *
 * @internal
 */
final class PaymentCallbackChargeTrackingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string            $prefix;
    private OrderAttemptModel $attemptModel;
    private OrderModel        $orderModel;

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
        $this->prefix       = 'PCT' . substr(uniqid(), -6);
        $this->attemptModel = new OrderAttemptModel();
        $this->orderModel   = new OrderModel();
        session()->destroy();
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
        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = substr(uniqid(), -8);
        $db->table('users')->insert([
            'username'      => $this->prefix . $uid,
            'email'         => $this->prefix . $uid . '@example.test',
            'password'      => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'      => $this->prefix,
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

    /** @return array<string, mixed> */
    private function insertProduct(): array
    {
        $db   = db_connect();
        $data = [
            'name'           => 'PCTProd_' . uniqid(),
            'slug'           => 'pct-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        $db->table('products')->insert($data);
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    private function insertCoupon(): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'code'                => 'PCT' . uniqid(),
            'name'                => 'PCT쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 1000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => 10,
            'used_count'          => 5,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id                         = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $product */
    private function createAttempt(int $userId, array $product, ?int $couponId, int $couponDiscount, int $pointUsed): int
    {
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
                'qty'            => 1,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            $couponId,
            null,
            $couponDiscount,
            $pointUsed,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    /**
     * 승인 결과를 고정한 PG 어댑터로 콜백을 실행한다.
     *
     * @param array<string, mixed> $confirmResult
     * @param array<string, mixed> $getParams
     */
    private function runCallback(array $getParams, array $confirmResult): RedirectResponse
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, 'payment/callback/toss'),
            null,
            new UserAgent(),
        );
        $request->setGlobal('get', $getParams);

        $controller = new class ($confirmResult) extends PaymentController {
            /** @param array<string, mixed> $confirmResult */
            public function __construct(private readonly array $confirmResult)
            {
                parent::__construct();
            }

            protected function makePg(string $pgProvider): PGInterface
            {
                return new class ($this->confirmResult) implements PGInterface {
                    /** @param array<string, mixed> $confirmResult */
                    public function __construct(private readonly array $confirmResult)
                    {
                    }

                    /**
                     * @param  array<string, mixed> $order
                     * @return array<string, mixed>
                     */
                    public function buildPaymentParams(array $order): array
                    {
                        return [];
                    }

                    /** @return array<string, mixed> */
                    public function confirm(string $pgToken, int $expectedAmount): array
                    {
                        return $this->confirmResult;
                    }

                    /** @return array{success: bool, message: string} */
                    public function cancel(string $pgTid, int $amount, string $reason): array
                    {
                        return ['success' => false, 'message' => '미구현'];
                    }

                    public function getProviderName(): string
                    {
                        return 'toss';
                    }
                };
            }
        };

        $controller->initController($request, service('response'), service('logger'));

        $response = $controller->callback('toss');
        $this->assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function trackOrdersOf(int $userId): void
    {
        $rows = db_connect()->table('orders')->select('id')->where('user_id', $userId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['orders'][] = (int) $row['id'];
        }
    }

    // ── 회귀 테스트 ───────────────────────────────────────────────────────────

    /**
     * T-01: 승인 금액이 주문 금액과 다르면 취소 주문 + paid 결제행을 남겨
     * 관리자 "환불 필요" 목록에서 청구를 추적할 수 있어야 한다.
     */
    public function testAmountMismatchLeavesRefundPendingRecord(): void
    {
        $db       = db_connect();
        $userId   = $this->insertUser();
        $product  = $this->insertProduct();
        $couponId = $this->insertCoupon();

        $db->table('users')->where('id', $userId)->update(['point_balance' => 5000]);

        $attemptId = $this->createAttempt($userId, $product, $couponId, 1000, 2000);
        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(3000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(6, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count']);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $tid      = 'TID-CB-MISMATCH-' . uniqid();
        $response = $this->runCallback(
            ['attempt_id' => $attemptId, 'paymentKey' => 'PAYKEY-' . uniqid()],
            // 시도의 payable_amount(7000)와 다른 금액으로 승인이 돌아온 상황.
            ['success' => true, 'tid' => $tid, 'method' => 'card', 'amount' => 99000, 'raw' => ['x' => 1]],
        );
        $this->trackOrdersOf($userId);

        $this->assertStringContainsString('order/fail', (string) $response->header('Location')->getValue());

        // 핵심 — 청구가 환불 대상으로 남아야 한다.
        $pending = array_column($this->orderModel->findRefundPending(), 'pg_tid');
        $this->assertContains($tid, $pending, '금액 불일치 청구가 환불 필요 목록에서 사라졌다');

        $order = $db->table('orders')->where('user_id', $userId)->get()->getRowArray();
        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order['status']);

        // 시도는 failed 로 확정되고 쿠폰·포인트는 정확히 1회 복구된다.
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(5, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count']);

        // 재고는 손대지 않는다.
        $this->assertSame(10, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        // 안내는 원인을 정확히 짚고, 자동 환불을 약속하지 않는다.
        $message = (string) session()->getFlashdata('pg_error');
        $this->assertStringContainsString('결제 금액', $message);
        $this->assertStringNotContainsString('재고', $message, '금액 불일치를 재고 부족으로 안내하고 있다');
        $this->assertStringNotContainsString('자동 환불', $message);
    }

    /**
     * T-02: 재고 부족으로 전환에 실패한 경우도 환불 추적이 유지되고, 안내가
     * 금액 불일치와 구분돼야 한다.
     */
    public function testOutOfStockKeepsRefundTrailWithStockSpecificMessage(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, null, 0, 0);
        $this->assertGreaterThan(0, $attemptId);

        // 결제창이 떠 있는 사이 재고가 소진된 상황을 만든다.
        $db->table('products')->where('id', $product['id'])->update(['stock' => 0]);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $tid      = 'TID-CB-OOS-' . uniqid();
        $response = $this->runCallback(
            ['attempt_id' => $attemptId, 'paymentKey' => 'PAYKEY-' . uniqid()],
            ['success' => true, 'tid' => $tid, 'method' => 'card', 'amount' => 10000, 'raw' => []],
        );
        $this->trackOrdersOf($userId);

        $this->assertStringContainsString('order/fail', (string) $response->header('Location')->getValue());

        $pending = array_column($this->orderModel->findRefundPending(), 'pg_tid');
        $this->assertContains($tid, $pending);

        $message = (string) session()->getFlashdata('pg_error');
        $this->assertStringContainsString('재고', $message);
        $this->assertStringNotContainsString('자동 환불', $message);
    }
}
