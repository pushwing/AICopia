<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Controllers\Front\PaymentController;
use App\Models\OrderAttemptModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 네이버페이·토스는 카카오페이·PAYCO·이니시스([[PgCancelReturnsToOrderTest]])처럼
 * 어댑터가 만드는 전용 "취소 URL"이 없다 — 성공/취소 판정을 컨트롤러가 직접 해야 한다.
 *
 * - 네이버페이: 성공·취소 모두 같은 returnUrl(payment/callback/naverpay)로 오고
 *   resultCode 로만 구분된다. PaymentController::callback() 이 이 값을 안 보면
 *   취소(닫기)가 승인 실패로 오인돼 order/fail 로 간다.
 * - 토스: 성공은 successUrl, 실패·취소는 모두 failUrl(order/fail)로 오지만
 *   code 로 "사용자가 그냥 닫음"과 "진짜 승인 거절"을 구분할 수 있다.
 *   OrderController::fail() 이 이 code 를 안 보면 취소도 실패 화면으로 뜬다.
 *
 * 아래 attempt_id 기반 테스트들은 이슈 #214(PaymentController 를 attempt_id 로
 * 전환)에서 새로 추가된 네이버페이 취소 시 markFailed() 호출과, findPendingForUser()
 * 의 소유권 검증을 검증한다. 레거시 order_id 경로는 위 테스트들이 계속 담당한다.
 *
 * @internal
 */
final class PgCancelControllerReturnsToOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'orders'         => [],
        'users'          => [],
        'order_attempts' => [],
        'products'       => [],
        'coupons'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix       = 'PCC' . substr(uniqid(), -6);
        $this->attemptModel = new OrderAttemptModel();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
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
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
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
            'username'   => $this->prefix . substr(uniqid(), -4),
            'email'      => $this->prefix . substr(uniqid(), -4) . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
            'point_balance' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertPendingOrder(int $userId, string $orderNumber): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => 'pending',
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
        ]);
        $id                        = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'PCCProd_' . uniqid(),
            'slug'           => 'pcc-prod-' . uniqid(),
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
            'code'                => 'PCC' . uniqid(),
            'name'                => 'PCC쿠폰',
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

    /**
     * @param array<string, mixed> $product
     */
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
            'naverpay'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    /** @param array<string, mixed> $getParams */
    private function requestWithGet(string $path, array $getParams): IncomingRequest
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, $path),
            null,
            new UserAgent(),
        );
        $request->setGlobal('get', $getParams);

        return $request;
    }

    /** @param array<string, mixed> $getParams */
    private function paymentController(array $getParams): PaymentController
    {
        $controller = new PaymentController();
        $controller->initController(
            $this->requestWithGet('payment/callback/naverpay', $getParams),
            service('response'),
            service('logger'),
        );

        return $controller;
    }

    /** @param array<string, mixed> $getParams */
    private function orderController(array $getParams): OrderController
    {
        $controller = new OrderController();
        $controller->initController(
            $this->requestWithGet('order/fail', $getParams),
            service('response'),
            service('logger'),
        );

        return $controller;
    }

    // ── 네이버페이: PaymentController::callback() (레거시 order_id) ─────────────

    public function testNaverpayCancelReturnsToOrderPageNotFailPage(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertPendingOrder($userId, $orderNumber);
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->paymentController(['order_id' => $orderId, 'resultCode' => 'Fail'])
            ->callback('naverpay');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringNotContainsString('order/fail', $location, '취소가 실패 화면으로 갔다');
        $this->assertStringEndsWith('/order', rtrim($location, '/'));
    }

    /** paymentId 가 있고 resultCode 가 Fail 이 아니면(=정상 성공 흐름) 취소 분기를 타면 안 된다. */
    public function testNaverpaySuccessDoesNotShortCircuitToOrderPage(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertPendingOrder($userId, $orderNumber);
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        // paymentId 없이 넘기면(=서버가 승인 못 함) 취소 분기가 아니라 기존
        // "결제 정보를 받지 못했습니다" → order/fail 분기를 타야 한다.
        $result = $this->paymentController(['order_id' => $orderId])->callback('naverpay');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringContainsString('order/fail', $location, 'resultCode 없이도 취소 분기를 타 버렸다');
    }

    // ── 네이버페이: PaymentController::callback() (신규 attempt_id) ─────────────

    /**
     * attempt_id + resultCode=Fail 콜백은 시도를 failed 로 확정하고 선점해 둔
     * 쿠폰·포인트를 복구한 뒤 /order 로 돌려보내야 한다(order/fail 이 아니다).
     */
    public function testNaverpayCancelWithAttemptIdMarksFailedAndRestoresCouponAndPoints(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);
        $couponId = $this->insertCoupon(['used_count' => 5, 'total_qty' => 10]);

        $db->table('users')->where('id', $userId)->update(['point_balance' => 5000]);

        $attemptId = $this->createAttempt($userId, $product, $couponId, 1000, 2000);
        $this->assertGreaterThan(0, $attemptId, '시도 생성(쿠폰·포인트 선점)은 성공해야 한다');

        // 선점 직후 상태 확인 — 이후 복구가 실제로 되돌리는지 대조하기 위한 기준선.
        $this->assertSame(3000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(6, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count']);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->paymentController(['attempt_id' => $attemptId, 'resultCode' => 'Fail'])
            ->callback('naverpay');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringNotContainsString('order/fail', $location, '취소가 실패 화면으로 갔다');
        $this->assertStringEndsWith('/order', rtrim($location, '/'));

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('failed', $attempt['status'], '취소된 시도는 failed 로 확정돼야 한다');

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

        // 취소된 시도는 주문으로 전환되지 않는다.
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());
    }

    /**
     * 남의 attempt_id 로 콜백을 호출하면 findPendingForUser() 가 소유권 불일치로
     * null 을 돌려주고, 전환(주문 생성)이 전혀 일어나지 않은 채 홈으로 튕겨야 한다.
     */
    public function testCallbackWithAnotherUsersAttemptIdRejectsOwnershipAndCreatesNoOrder(): void
    {
        $db      = db_connect();
        $ownerId = $this->insertUser();
        $callerId = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);

        $attemptId = $this->createAttempt($ownerId, $product);
        $this->assertGreaterThan(0, $attemptId);

        // 시도의 진짜 소유자가 아니라 다른 로그인 사용자로 콜백을 호출한다.
        session()->set(['user_id' => $callerId, 'user_role' => 'member']);

        $result = $this->paymentController(['attempt_id' => $attemptId])->callback('naverpay');

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringNotContainsString('order/fail', $location, '소유권 거부가 실패 화면으로 갔다');
        $this->assertStringNotContainsString('/order/complete', $location, '소유권 거부인데 주문이 완료 처리됐다');

        // 시도는 여전히 pending 이고, 어떤 사용자에게도 주문이 생기지 않아야 한다.
        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('pending', $attempt['status'], '소유권이 없는 콜백이 시도 상태를 바꾸면 안 된다');
        $this->assertSame(0, $db->table('orders')->where('user_id', $ownerId)->countAllResults());
        $this->assertSame(0, $db->table('orders')->where('user_id', $callerId)->countAllResults());
    }

    // ── 토스: OrderController::fail() ────────────────────────────────────────

    public function testTossCancelCodeReturnsToOrderPageNotFailPage(): void
    {
        $result = $this->orderController(['code' => 'PAY_PROCESS_CANCELED'])
            ->fail('ORD-' . $this->prefix);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringNotContainsString('order/fail', $location, '취소가 실패 화면으로 갔다');
        $this->assertStringEndsWith('/order', rtrim($location, '/'));
    }

    /** 카드 한도 초과 등 진짜 승인 실패는 여전히 실패 화면을 보여줘야 한다. */
    public function testTossRealFailureStillShowsFailPage(): void
    {
        $result = $this->orderController(['code' => 'REJECT_CARD_COMPANY'])
            ->fail('ORD-' . $this->prefix);

        $this->assertIsString($result, '진짜 결제 실패인데 리다이렉트됐다');
        $this->assertStringContainsString('결제', $result);
    }
}
