<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Controllers\Front\PaymentController;
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
 * @internal
 */
final class PgCancelControllerReturnsToOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PCC' . substr(uniqid(), -6);
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
        $this->cleanup = ['orders' => [], 'users' => []];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix,
            'email'      => $this->prefix . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
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

    // ── 네이버페이: PaymentController::callback() ───────────────────────────────

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
