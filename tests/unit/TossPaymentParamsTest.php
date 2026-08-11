<?php

declare(strict_types=1);

use App\Libraries\PG\TossPaymentsAdapter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\PG;

/**
 * 토스페이먼츠 결제창 파라미터 검증.
 *
 * 토스 SDK 의 requestPayment() 는 결제창을 띄우기 전에 apigw(-sandbox).tosspayments.com
 * 으로 파라미터를 먼저 보내 검증받는다. 규격을 벗어나면 결제창이 열리지 않고
 * 브라우저 콘솔에 400 만 남는다 — 그래서 어댑터가 만드는 값 자체를 여기서 검증한다.
 *
 * @internal
 */
final class TossPaymentParamsTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * 로컬 .env 값에 결과가 좌우되지 않도록 키를 주입한 어댑터를 만든다.
     * 기본값은 결제창(API 개별 연동)용 정상 키다.
     */
    private function adapter(
        string $clientKey = 'test_ck_docsSampleClientKey0000000000',
        string $secretKey = 'test_sk_docsSampleSecretKey0000000000'
    ): TossPaymentsAdapter {
        $config                = new PG();
        $config->tossClientKey = $clientKey;
        $config->tossSecretKey = $secretKey;
        Factories::injectMock('config', 'PG', $config);

        return new TossPaymentsAdapter();
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id'             => 12,
            'order_number'   => 'ORD-20260807-42317',
            'total_amount'   => 22000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'items'          => [
                ['product_name' => '기본 티셔츠', 'qty' => 1],
            ],
        ];
    }

    /**
     * 토스 규격: orderId 는 영문 대소문자·숫자·`-`·`_` 만 허용하며 6자 이상 64자 이하.
     * 위반하면 apigw 가 400(INVALID_ORDER_ID)을 반환해 결제창이 뜨지 않는다.
     * DB PK(예: "12")를 그대로 넘기면 6자 미만이라 항상 실패한다.
     */
    public function testOrderIdMatchesTossFormatRule(): void
    {
        $params = $this->adapter()->buildPaymentParams($this->order());

        $orderId = (string) $params['orderId'];

        $this->assertGreaterThanOrEqual(6, strlen($orderId), 'orderId 는 6자 이상이어야 합니다.');
        $this->assertLessThanOrEqual(64, strlen($orderId), 'orderId 는 64자 이하여야 합니다.');
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+$/',
            $orderId,
            'orderId 는 영문·숫자·-·_ 만 사용할 수 있습니다.'
        );
    }

    /** 짧은 DB PK 대신 주문번호를 써야 규격을 만족한다. */
    public function testOrderIdUsesOrderNumber(): void
    {
        $params = $this->adapter()->buildPaymentParams($this->order());

        $this->assertSame('ORD-20260807-42317', $params['orderId']);
    }

    /**
     * 결제 성공 후 돌아올 successUrl 은 다른 PG 어댑터와 동일하게 어댑터가 만든다.
     * 뷰에서 orderId 를 콜백의 attempt_id 로 재사용하면, orderId 가 주문번호로 바뀌는
     * 순간 콜백이 주문 시도를 찾지 못한다(콜백은 DB PK 로 조회한다).
     */
    public function testSuccessUrlPointsToCallbackWithAttemptId(): void
    {
        $params = $this->adapter()->buildPaymentParams($this->order());

        $this->assertArrayHasKey('successUrl', $params);
        $this->assertStringContainsString('payment/callback/toss', (string) $params['successUrl']);
        $this->assertStringContainsString('attempt_id=12', (string) $params['successUrl']);
        $this->assertStringStartsWith('http', (string) $params['successUrl'], 'successUrl 은 절대 URL 이어야 합니다.');
    }

    /** 실패·취소 시 돌아올 failUrl 은 주문번호 기반 실패 페이지를 가리킨다. */
    public function testFailUrlPointsToOrderFailPage(): void
    {
        $params = $this->adapter()->buildPaymentParams($this->order());

        $this->assertArrayHasKey('failUrl', $params);
        $this->assertStringContainsString('order/fail/ORD-20260807-42317', (string) $params['failUrl']);
        $this->assertStringStartsWith('http', (string) $params['failUrl'], 'failUrl 은 절대 URL 이어야 합니다.');
    }

    /** 뷰의 launchPG 가 분기에 쓰는 pg 키는 유지해야 한다. */
    public function testKeepsPgDiscriminator(): void
    {
        $this->assertSame('toss', $this->adapter()->buildPaymentParams($this->order())['pg']);
    }

    // ─── 키 종류 검증 ─────────────────────────────────────────────────────────

    /**
     * 결제위젯 키(`test_gck_`·`live_gck_`)로 결제창 SDK 를 초기화하면
     * apigw 가 400 을 반환해 결제창이 뜨지 않는다. 토스는 결제창(API 개별 연동)에
     * `ck` 키를, 결제위젯에 `gck` 키를 쓰도록 구분한다.
     *
     * 브라우저 콘솔에 400 만 남으면 원인을 알 수 없으므로, 결제창을 열기 전에
     * 어댑터가 잡아내 사람이 읽을 수 있는 메시지로 알려야 한다.
     */
    public function testRejectsPaymentWidgetClientKey(): void
    {
        $params = $this->adapter('test_gck_docs_Ovk5rk1EwkEbP0W43n07xlzm')->buildPaymentParams($this->order());

        $this->assertArrayHasKey('error', $params, '결제위젯 키를 그대로 통과시키면 안 됩니다.');
        $this->assertStringContainsString('결제위젯', (string) $params['error']);
        $this->assertArrayNotHasKey('clientKey', $params, '잘못된 키로 결제창을 열어서는 안 됩니다.');
        $this->assertSame('toss', $params['pg'], '뷰가 오류를 표시하려면 pg 판별자는 남아 있어야 합니다.');
    }

    /** 운영용 결제위젯 키(live_gck_)도 동일하게 막는다. */
    public function testRejectsLivePaymentWidgetClientKey(): void
    {
        $params = $this->adapter('live_gck_00000000000000000000000000')->buildPaymentParams($this->order());

        $this->assertArrayHasKey('error', $params);
    }

    /** 키가 비어 있으면(.env 미설정) 결제창 대신 설정 안내를 내보낸다. */
    public function testRejectsEmptyClientKey(): void
    {
        $params = $this->adapter('')->buildPaymentParams($this->order());

        $this->assertArrayHasKey('error', $params);
        $this->assertStringContainsString('TOSS_CLIENT_KEY', (string) $params['error']);
    }

    /**
     * 시크릿 키도 짝이 맞아야 한다. 결제위젯 시크릿 키(`gsk`)를 쓰면 결제창은
     * 열리지만 서버 승인(confirm)이 INVALID_API_KEY 로 실패한다 —
     * 고객은 결제됐는데 주문은 완료되지 않는 최악의 경우라 미리 막는다.
     */
    public function testRejectsPaymentWidgetSecretKey(): void
    {
        $params = $this->adapter('test_ck_docsSampleClientKey0000000000', 'test_gsk_00000000000000000000000000')
            ->buildPaymentParams($this->order());

        $this->assertArrayHasKey('error', $params);
        $this->assertStringContainsString('시크릿', (string) $params['error']);
    }

    /** 정상적인 결제창 키 조합은 그대로 통과해야 한다. */
    public function testAcceptsApiIndividualKeys(): void
    {
        $params = $this->adapter()->buildPaymentParams($this->order());

        $this->assertArrayNotHasKey('error', $params);
        $this->assertSame('test_ck_docsSampleClientKey0000000000', $params['clientKey']);
    }
}
