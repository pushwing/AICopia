<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, mixed $arguments = null): mixed
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        // PG 결제 호스트는 와일드카드 없이 실제로 필요한 것만 개별 명시한다.
        // 각 PG 어댑터의 REST API 호출(api.tosspayments.com 등)은 PHP curl(서버사이드)이라
        // CSP 영향을 받지 않는다 — 아래 목록은 "브라우저가 직접 요청하는 것"만 담는다.
        $csp = implode('; ', [
            "default-src 'self'",

            // t1.kakaocdn.net — 카카오 우편번호(주소검색) 서비스 로더
            // js.tosspayments.com — 주문서(shop/checkout)가 로드하는 토스페이먼츠 결제 SDK
            "script-src 'self' cdn.jsdelivr.net t1.kakaocdn.net js.tosspayments.com 'unsafe-inline'",

            "style-src 'self' cdn.jsdelivr.net 'unsafe-inline'",
            "img-src 'self' data: blob: shopping-phinf.pstatic.net",
            "font-src 'self' cdn.jsdelivr.net data:",

            // apigw(-sandbox).tosspayments.com — 토스 SDK가 결제창을 열기 전
            // 결제 파라미터를 XHR로 조회하는 엔드포인트. 차단되면 NETWORK_ERROR로 결제가 실패한다.
            // 라이브 키는 apigw, 테스트 키는 apigw-sandbox를 쓰므로 둘 다 필요하다.
            // log·event.tosspayments.com — SDK가 보내는 로그·지표 수집(결제 기능 자체와는 무관).
            // 막아도 결제는 되지만 SDK가 재시도하며 주문서 콘솔에 오류를 계속 쌓고,
            // 결제 장애 문의 시 토스 측이 이 로그를 근거로 확인하므로 함께 허용한다.
            "connect-src 'self' apigw.tosspayments.com apigw-sandbox.tosspayments.com log.tosspayments.com event.tosspayments.com",

            // payment-gateway(-sandbox).tosspayments.com — 토스 결제창은 팝업이 아니라
            // iframe으로 열린다. frame-src를 지정하지 않으면 default-src 'self'로 폴백돼 차단된다.
            "frame-src 'self' payment-gateway.tosspayments.com payment-gateway-sandbox.tosspayments.com",

            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",

            // stdpay.inicis.com / pay.nicepay.co.kr — 이니시스·나이스페이는 주문서에서
            // 동적 생성한 form을 결제 도메인으로 POST 전송한다. form-action 'self'만으로는 차단된다.
            // (카카오페이·페이코는 location.href 최상위 이동이라 CSP 대상이 아니다.)
            "form-action 'self' stdpay.inicis.com pay.nicepay.co.kr",
        ]);

        $response->setHeader('Content-Security-Policy', $csp);
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
