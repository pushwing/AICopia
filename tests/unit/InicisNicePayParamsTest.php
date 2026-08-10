<?php

declare(strict_types=1);

use App\Libraries\PG\InicisAdapter;
use App\Libraries\PG\NicePayAdapter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\PG;

/**
 * 이니시스·나이스페이 결제창 파라미터 검증.
 *
 * 두 PG 모두 결제창을 띄우려면 어댑터가 만들어 준 파라미터가 각 SDK 규격을
 * 만족해야 한다. 특히 returnUrl 이 없으면 인증 후 돌아올 곳이 없어 결제가 끊긴다.
 *
 * @internal
 */
final class InicisNicePayParamsTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * 로컬 .env 값에 결과가 좌우되지 않도록 키를 주입한 어댑터를 만든다.
     * 기본값은 이니시스가 공개한 웹표준 테스트 MID·signKey 다.
     */
    private function inicis(
        string $merchantId = 'INIpayTest',
        string $signKey = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
        ?bool $testMode = null
    ): InicisAdapter {
        $config                    = new PG();
        $config->inicisMerchantId  = $merchantId;
        $config->inicisSignKey     = $signKey;
        $config->inicisTestMode    = $testMode;
        Factories::injectMock('config', 'PG', $config);

        return new InicisAdapter();
    }

    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id'             => 4242,
            'order_number'   => 'ORD20260807XYZ',
            'total_amount'   => 22000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'items'          => [
                ['product_name' => '기본 티셔츠', 'qty' => 1],
            ],
        ];
    }

    // ─── 이니시스 ─────────────────────────────────────────────────────────────

    /** INIStdPay 는 version·currency·gopaymethod·acceptmethod 가 없으면 결제창을 띄우지 않는다. */
    public function testInicisParamsIncludeStdPayRequiredFields(): void
    {
        $params = $this->inicis()->buildPaymentParams($this->order());

        foreach (['version', 'currency', 'gopaymethod', 'acceptmethod'] as $key) {
            $this->assertArrayHasKey($key, $params, "이니시스 필수 파라미터 {$key} 가 없습니다.");
            $this->assertNotSame('', $params[$key], "이니시스 파라미터 {$key} 가 비어 있습니다.");
        }

        $this->assertSame('1.0', $params['version']);
        $this->assertSame('WON', $params['currency']);
    }

    /** 인증 완료 후 돌아올 returnUrl 이 콜백 라우트를 attempt_id 와 함께 가리켜야 한다. */
    public function testInicisParamsIncludeReturnUrl(): void
    {
        $params = $this->inicis()->buildPaymentParams($this->order());

        $this->assertArrayHasKey('returnUrl', $params);
        $this->assertStringContainsString('payment/callback/inicis', (string) $params['returnUrl']);
        $this->assertStringContainsString('attempt_id=4242', (string) $params['returnUrl']);
        $this->assertStringStartsWith('http', (string) $params['returnUrl'], 'returnUrl 은 절대 URL 이어야 합니다.');
    }

    /**
     * 사용자가 결제창을 그냥 닫은 것은 실패가 아니다 — closeUrl 은 order/fail(결제
     * 실패 화면)이 아니라 주문서(order)로 돌아가야 한다. 장바구니는 결제 확정 전까지
     * 비워지지 않으므로 주문서를 그대로 다시 보여줄 수 있다.
     */
    public function testInicisCloseUrlReturnsToOrderPageNotFailPage(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('closeUrl', $params);
        $this->assertStringNotContainsString('order/fail', (string) $params['closeUrl']);
        // 시도를 걷어내는 전용 라우트(이슈 #214)를 거쳐야 쿠폰·포인트가 복구된다 —
        // 곧장 /order 로 가면 그 복구가 빠진다.
        $this->assertStringEndsWith('/order/payment-cancel/ORD20260807XYZ', (string) $params['closeUrl']);
    }

    /** signature 는 oid·price·timestamp 를, mKey 는 signKey 를 SHA256 해시한 값이다. */
    public function testInicisSignatureMatchesManualFormula(): void
    {
        $params = $this->inicis()->buildPaymentParams($this->order());

        $expected = hash('sha256', sprintf(
            'oid=%s&price=%s&timestamp=%s',
            $params['oid'],
            $params['price'],
            $params['timestamp']
        ));

        $this->assertSame($expected, $params['signature']);
        $this->assertSame(hash('sha256', (string) config('PG')->inicisSignKey), $params['mKey']);
    }

    /** 구매자 연락처는 결제창 입력 편의를 위해 함께 넘긴다. */
    public function testInicisParamsIncludeBuyerContact(): void
    {
        $params = $this->inicis()->buildPaymentParams($this->order());

        $this->assertArrayHasKey('buyertel', $params);
        $this->assertSame('01012345678', $params['buyertel']);
    }

    /**
     * MID 가 비면 결제창을 열어선 안 된다.
     *
     * 빈 mid 로 stdpay.inicis.com/payMain/pay 를 호출하면 이니시스가 결제창 대신
     * resultCode=V022("필수 파라미터가 누락되었습니다") 안내 페이지를 오버레이 iframe 안에
     * 그려 넣는다. 그 페이지는 부모를 closeUrl 로 보내지 않으므로 INIStdPay 가 씌운
     * 전체화면 오버레이가 영영 걷히지 않아 주문서가 먹통이 된다.
     * → 어댑터가 error 를 담아 돌려주고 뷰가 pay() 호출 자체를 막는다.
     */
    public function testInicisReturnsErrorWhenMerchantIdMissing(): void
    {
        $params = $this->inicis(merchantId: '')->buildPaymentParams($this->order());

        $this->assertSame('inicis', $params['pg'], '뷰 분기용 pg 키는 유지해야 합니다.');
        $this->assertArrayHasKey('error', $params);
        $this->assertStringContainsString('INICIS_MERCHANT_ID', (string) $params['error']);
        $this->assertArrayNotHasKey('mid', $params, '키가 없으면 결제 파라미터를 만들지 않는다.');
    }

    /** signKey 가 없으면 mKey·signature 를 만들 수 없어 결제창이 인증에 실패한다. */
    public function testInicisReturnsErrorWhenSignKeyMissing(): void
    {
        $params = $this->inicis(signKey: '')->buildPaymentParams($this->order());

        $this->assertSame('inicis', $params['pg']);
        $this->assertArrayHasKey('error', $params);
        $this->assertStringContainsString('INICIS_SIGN_KEY', (string) $params['error']);
    }

    /** 키가 정상이면 error 없이 결제 파라미터가 온전히 나와야 한다. */
    public function testInicisHasNoErrorWhenKeysConfigured(): void
    {
        $params = $this->inicis()->buildPaymentParams($this->order());

        $this->assertArrayNotHasKey('error', $params);
        $this->assertSame('INIpayTest', $params['mid']);
    }

    // ─── 테스트/운영 도메인 분기 ──────────────────────────────────────────────

    /**
     * 테스트 MID 는 운영 결제창(stdpay)이 받아주지 않는다 — 이니시스는 테스트용
     * 결제창·API 를 stg 도메인으로 따로 운영한다. 어댑터가 SDK URL 을 함께
     * 내려주고, 뷰는 하드코딩 대신 그 값을 쓴다.
     */
    public function testInicisUsesStagingEndpointsForPublicTestMid(): void
    {
        $adapter = $this->inicis(merchantId: 'INIpayTest');
        $params  = $adapter->buildPaymentParams($this->order());

        $this->assertSame('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $params['sdkUrl']);
        $this->assertSame(
            'https://stginiapi.inicis.com/api/v1',
            $this->getPrivateProperty($adapter, 'apiBase'),
            '승인·취소 API 도 같은 환경을 봐야 한다.'
        );
    }

    /** 상점 MID(테스트 MID 가 아님)면 운영 도메인을 쓴다. */
    public function testInicisUsesLiveEndpointsForMerchantMid(): void
    {
        $adapter = $this->inicis(merchantId: 'aicopia01');
        $params  = $adapter->buildPaymentParams($this->order());

        $this->assertSame('https://stdpay.inicis.com/stdjs/INIStdPay.js', $params['sdkUrl']);
        $this->assertSame('https://iniapi.inicis.com/api/v1', $this->getPrivateProperty($adapter, 'apiBase'));
    }

    /** 상점별 테스트 MID 도 있으므로 INICIS_TEST_MODE 로 명시 지정할 수 있어야 한다. */
    public function testInicisTestModeCanBeForcedForMerchantMid(): void
    {
        $params = $this->inicis(merchantId: 'aicopia01', testMode: true)->buildPaymentParams($this->order());

        $this->assertSame('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $params['sdkUrl']);
    }

    /** 반대 방향 — 테스트 MID 라도 운영으로 강제 지정하면 운영 도메인을 쓴다. */
    public function testInicisLiveModeCanBeForcedForTestMid(): void
    {
        $params = $this->inicis(merchantId: 'INIpayTest', testMode: false)->buildPaymentParams($this->order());

        $this->assertSame('https://stdpay.inicis.com/stdjs/INIStdPay.js', $params['sdkUrl']);
    }

    // ─── 나이스페이 ───────────────────────────────────────────────────────────

    /** AUTHNICE.requestPay 는 method·returnUrl 이 없으면 결제창을 띄우지 않는다. */
    public function testNicepayParamsIncludeRequestPayRequiredFields(): void
    {
        $params = (new NicePayAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('method', $params, '나이스페이 method 파라미터가 없습니다.');
        $this->assertSame('card', $params['method']);

        $this->assertArrayHasKey('returnUrl', $params);
        $this->assertStringContainsString('payment/callback/nicepay', (string) $params['returnUrl']);
        $this->assertStringContainsString('attempt_id=4242', (string) $params['returnUrl']);
        $this->assertStringStartsWith('http', (string) $params['returnUrl'], 'returnUrl 은 절대 URL 이어야 합니다.');
    }

    // ─── 공통 ────────────────────────────────────────────────────────────────

    /** 뷰의 launchPG 가 분기에 쓰는 pg 키는 두 어댑터 모두 유지해야 한다. */
    public function testBothAdaptersKeepPgDiscriminator(): void
    {
        $this->assertSame('inicis', $this->inicis()->buildPaymentParams($this->order())['pg']);
        $this->assertSame('nicepay', (new NicePayAdapter())->buildPaymentParams($this->order())['pg']);
    }
}
