<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Models\OrderAttemptModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 카카오페이·PAYCO·이니시스 결제창의 "닫기/취소" 는 order/payment-cancel/:orderNumber
 * (OrderController::cancelPayment()) 로 돌아온다. 실패가 아니므로 실패 화면을 절대
 * 렌더링하지 않고, 시도를 조용히 걷어내(쿠폰·포인트 복구) 주문서로만 돌려보내야 한다.
 * (이슈 #214)
 *
 * @internal
 */
final class PaymentCancelRouteControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'users'          => [],
        'products'       => [],
        'coupons'        => [],
        'order_attempts' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix       = 'PCR' . substr(uniqid(), -6);
        $this->attemptModel = new OrderAttemptModel();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
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
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $this->prefix . substr(uniqid(), -4),
            'email'         => $this->prefix . substr(uniqid(), -4) . '@example.test',
            'password'      => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'      => $this->prefix,
            'role'          => 'member',
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'PCRProd_' . uniqid(),
            'slug'           => 'pcr-prod-' . uniqid(),
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
            'code'                => 'PCR' . uniqid(),
            'name'                => 'PCR쿠폰',
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
                'qty'            => 1,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            $couponId,
            null,
            $couponDiscountAmount,
            $pointUsed,
            0,
            'kakaopay'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    private function cancelPaymentController(string $orderNumber): OrderController
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, 'order/payment-cancel/' . $orderNumber),
            null,
            new UserAgent(),
        );

        $controller = new OrderController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    /**
     * 결제창 닫기(cancelPayment)는 시도를 failed 로 확정하고 선점해 둔 쿠폰·포인트를
     * 복구한 뒤 /order 로 리다이렉트해야 한다 — order/fail(실패 화면)이 아니다.
     */
    public function testCancelPaymentMarksFailedRestoresCouponAndPointsAndRedirectsToOrder(): void
    {
        $db       = db_connect();
        $userId   = $this->insertUser();
        $product  = $this->insertProduct(['stock' => 10]);
        $couponId = $this->insertCoupon(['used_count' => 5, 'total_qty' => 10]);

        $db->table('users')->where('id', $userId)->update(['point_balance' => 5000]);

        $attemptId = $this->createAttempt($userId, $product, $couponId, 1000, 2000);
        $this->assertGreaterThan(0, $attemptId, '시도 생성(쿠폰·포인트 선점)은 성공해야 한다');

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->cancelPaymentController($attempt['order_number'])
            ->cancelPayment($attempt['order_number']);

        $this->assertInstanceOf(RedirectResponse::class, $result, '뷰를 렌더링하지 않고 리다이렉트해야 한다');
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringNotContainsString('order/fail', $location, '취소가 실패 화면으로 갔다');
        $this->assertStringEndsWith('/order', rtrim($location, '/'));

        $updated = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('failed', $updated['status'], '취소된 시도는 failed 로 확정돼야 한다');

        $this->assertSame(
            5000,
            (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'],
            '선점했던 포인트가 복구돼야 한다'
        );
        $this->assertSame(
            5,
            (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'],
            '선점했던 쿠폰이 복구돼야 한다'
        );
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults(), '취소된 시도는 주문으로 전환되지 않는다');
    }

    /** 남의 order_number 로 호출하면 소유권이 없으므로 아무 일도 일어나지 않는다. */
    public function testCancelPaymentWithAnotherUsersOrderNumberDoesNothing(): void
    {
        $db       = db_connect();
        $ownerId  = $this->insertUser();
        $callerId = $this->insertUser();
        $product  = $this->insertProduct(['stock' => 10]);

        $attemptId = $this->createAttempt($ownerId, $product);
        $this->assertGreaterThan(0, $attemptId);
        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        // 시도의 진짜 소유자가 아니라 다른 로그인 사용자로 취소 콜백을 호출한다.
        session()->set(['user_id' => $callerId, 'user_role' => 'member']);

        $result = $this->cancelPaymentController($attempt['order_number'])
            ->cancelPayment($attempt['order_number']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringEndsWith('/order', rtrim($location, '/'), '소유권이 없어도 화면은 조용히 주문서로 돌려보낸다');

        $unchanged = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('pending', $unchanged['status'], '소유권 없는 취소 호출이 남의 시도 상태를 바꾸면 안 된다');
    }

    /** 이미 markFailed() 로 처리된 시도를 다시 취소해도 조용히 아무 일도 일어나지 않는다(멱등). */
    public function testCancelPaymentOnAlreadyFailedAttemptIsNoop(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);

        $attemptId = $this->createAttempt($userId, $product);
        $attempt   = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->attemptModel->markFailed($attemptId, '먼저 처리됨');

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->cancelPaymentController($attempt['order_number'])
            ->cancelPayment($attempt['order_number']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringEndsWith('/order', rtrim($location, '/'));

        $unchanged = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('failed', $unchanged['status']);
        $this->assertSame('먼저 처리됨', $unchanged['fail_reason'], '두 번째 호출이 실패 사유를 덮어쓰면 안 된다');
    }
}
