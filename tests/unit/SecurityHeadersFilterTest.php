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

    /** 이니시스·나이스페이는 결제 도메인으로 form POST를 보내므로 form-action 허용이 필요하다. */
    public function testCspAllowsInicisAndNicepayFormPost(): void
    {
        $formAction = $this->directive('form-action');

        $this->assertContains('stdpay.inicis.com', $formAction);
        $this->assertContains('pay.nicepay.co.kr', $formAction);
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
