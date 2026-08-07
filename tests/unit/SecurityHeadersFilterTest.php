<?php

declare(strict_types=1);

use App\Filters\SecurityHeadersFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecurityHeadersFilterTest extends CIUnitTestCase
{
    private SecurityHeadersFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new SecurityHeadersFilter();
    }

    public function testCspAllowsNaverShoppingImageCdn(): void
    {
        $request  = service('request');
        $response = service('response');

        $result = $this->filter->after($request, $response, null);

        $csp = $result->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString('shopping-phinf.pstatic.net', $csp);
    }

    /**
     * 토스페이먼츠 결제 SDK 스크립트가 script-src에 허용돼야 한다.
     * 빠지면 주문서에서 window.TossPayments가 정의되지 않아 결제가 시작되지 않는다.
     */
    public function testCspAllowsTossPaymentsSdkScript(): void
    {
        $this->assertContains('js.tosspayments.com', $this->directive('script-src'));
    }

    /**
     * 토스 SDK는 결제창을 열기 전 apigw로 결제 파라미터를 XHR 조회한다.
     * 차단되면 NETWORK_ERROR로 결제가 실패한다(라이브=apigw, 테스트키=apigw-sandbox).
     */
    public function testCspAllowsTossPaymentParameterEndpoints(): void
    {
        $connectSrc = $this->directive('connect-src');

        $this->assertContains('apigw.tosspayments.com', $connectSrc);
        $this->assertContains('apigw-sandbox.tosspayments.com', $connectSrc);
    }

    /**
     * 토스 SDK의 로그·지표 수집 엔드포인트.
     * 결제 기능에는 영향이 없지만, 막으면 SDK가 재시도하며 주문서 콘솔에 오류가 쌓인다.
     */
    public function testCspAllowsTossTelemetryEndpoints(): void
    {
        $connectSrc = $this->directive('connect-src');

        $this->assertContains('log.tosspayments.com', $connectSrc);
        $this->assertContains('event.tosspayments.com', $connectSrc);
    }

    /** 토스 결제창은 iframe으로 열리므로 frame-src에 결제 게이트웨이 호스트가 필요하다. */
    public function testCspAllowsTossPaymentWindowFrame(): void
    {
        $frameSrc = $this->directive('frame-src');

        $this->assertContains('payment-gateway.tosspayments.com', $frameSrc);
        $this->assertContains('payment-gateway-sandbox.tosspayments.com', $frameSrc);
    }

    /**
     * 카카오 우편번호(주소검색) 창도 iframe으로 열리므로 frame-src 허용이 필요하다.
     *
     * SDK의 open()은 window.open('')으로 빈 팝업을 띄운 뒤 그 안에
     * postcode.map.kakao.com iframe을 삽입한다. 이 팝업 문서는 about:blank(동일 출처)라
     * 부모 페이지의 CSP를 그대로 상속하므로, frame-src에서 빠지면 팝업 안이
     * "이 콘텐츠는 차단되어 있습니다"로 표시되고 주소검색이 아예 불가능해진다.
     */
    public function testCspAllowsKakaoPostcodeFrame(): void
    {
        $this->assertContains('postcode.map.kakao.com', $this->directive('frame-src'));
    }

    /** 이니시스·나이스페이는 결제 도메인으로 form POST를 보내므로 form-action 허용이 필요하다. */
    public function testCspAllowsInicisAndNicepayFormPost(): void
    {
        $formAction = $this->directive('form-action');

        $this->assertContains('stdpay.inicis.com', $formAction);
        $this->assertContains('pay.nicepay.co.kr', $formAction);
        $this->assertContains('sandbox-pay.nicepay.co.kr', $formAction);
    }

    /**
     * 이니시스·나이스페이 SDK 스크립트.
     * INIStdPay는 stdux.inicis.com 에서 2차 스크립트를 이어서 로드한다.
     */
    public function testCspAllowsInicisAndNicepaySdkScripts(): void
    {
        $scriptSrc = $this->directive('script-src');

        $this->assertContains('stdpay.inicis.com', $scriptSrc);
        $this->assertContains('stdux.inicis.com', $scriptSrc);
        $this->assertContains('pay.nicepay.co.kr', $scriptSrc);
    }

    /** INIStdPay는 결제창 CSS를, 나이스페이는 결제창 iframe을 각각 자기 도메인에서 가져온다. */
    public function testCspAllowsInicisStyleAndNicepayFrame(): void
    {
        $this->assertContains('stdpay.inicis.com', $this->directive('style-src'));
        $this->assertContains('stdux.inicis.com', $this->directive('img-src'));

        $frameSrc = $this->directive('frame-src');
        $this->assertContains('stdpay.inicis.com', $frameSrc);
        $this->assertContains('pay.nicepay.co.kr', $frameSrc);
        $this->assertContains('sandbox-pay.nicepay.co.kr', $frameSrc);
    }

    /** 화이트리스트를 와일드카드로 넓히지 않았는지 지킨다. */
    public function testCspContainsNoWildcardSource(): void
    {
        $request  = service('request');
        $response = service('response');
        $csp      = $this->filter->after($request, $response, null)->getHeaderLine('Content-Security-Policy');

        $this->assertDoesNotMatchRegularExpression('/(?:^|[\s;])\*(?:$|[\s;])/', $csp, 'CSP에 와일드카드(*) 소스가 있으면 안 된다.');
        $this->assertStringNotContainsString('*.', $csp, 'CSP에 와일드카드 서브도메인(*.example.com)이 있으면 안 된다.');
    }

    /**
     * CSP 헤더에서 특정 디렉티브의 소스 목록을 뽑아낸다.
     *
     * @return list<string>
     */
    private function directive(string $name): array
    {
        $request  = service('request');
        $response = service('response');
        $csp      = $this->filter->after($request, $response, null)->getHeaderLine('Content-Security-Policy');

        foreach (explode(';', $csp) as $part) {
            $tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($tokens !== [] && $tokens[0] === $name) {
                return array_values(array_slice($tokens, 1));
            }
        }

        $this->fail(sprintf('CSP에 %s 디렉티브가 없습니다: %s', $name, $csp));
    }
}
