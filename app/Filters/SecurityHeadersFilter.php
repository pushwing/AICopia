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
            // stdpay.inicis.com — INIStdPay SDK 본체
            // stdux.inicis.com — INIStdPay가 이어서 로드하는 2차 스크립트(INIStdPay_third-party.js)
            // pay.nicepay.co.kr — 나이스페이 AUTHNICE SDK
            // nsp.pay.naver.com — 네이버페이 SDK(Naver.Pay) 본체
            "script-src 'self' cdn.jsdelivr.net t1.kakaocdn.net js.tosspayments.com stdpay.inicis.com stdux.inicis.com pay.nicepay.co.kr nsp.pay.naver.com 'unsafe-inline'",

            // stdpay.inicis.com — INIStdPay가 <link>로 주입하는 결제창 스타일(stdcss/INIStdPay.css)
            // ssl.pstatic.net — 네이버페이 SDK가 로드하는 결제창 스타일(naverpay_sdk_*.css)
            "style-src 'self' cdn.jsdelivr.net stdpay.inicis.com ssl.pstatic.net 'unsafe-inline'",

            // stdux.inicis.com — 이니시스 결제창 UI 이미지(닫기 버튼 등)
            // search.pstatic.net — 상품등록 "네이버 이미지 검색" 결과 미리보기(썸네일 프록시)
            "img-src 'self' data: blob: search.pstatic.net stdux.inicis.com",

            "font-src 'self' cdn.jsdelivr.net data:",

            // apigw(-sandbox).tosspayments.com — 토스 SDK가 결제창을 열기 전
            // 결제 파라미터를 XHR로 조회하는 엔드포인트. 차단되면 NETWORK_ERROR로 결제가 실패한다.
            // 라이브 키는 apigw, 테스트 키는 apigw-sandbox를 쓰므로 둘 다 필요하다.
            // log·event.tosspayments.com — SDK가 보내는 로그·지표 수집(결제 기능 자체와는 무관).
            // 막아도 결제는 되지만 SDK가 재시도하며 주문서 콘솔에 오류를 계속 쌓고,
            // 결제 장애 문의 시 토스 측이 이 로그를 근거로 확인하므로 함께 허용한다.
            // cdn.jsdelivr.net — 부트스트랩 소스맵(*.map). 개발자도구를 열면 브라우저가
            // 소스맵을 fetch 하는데 이것도 connect-src 대상이라, 빠지면 주문서 콘솔에
            // CSP 위반 메시지가 계속 쌓여 실제 결제 오류를 가린다(기능 영향은 없다).
            "connect-src 'self' cdn.jsdelivr.net apigw.tosspayments.com apigw-sandbox.tosspayments.com log.tosspayments.com event.tosspayments.com",

            // payment-gateway(-sandbox).tosspayments.com — 토스 결제창은 팝업이 아니라
            // iframe으로 열린다. frame-src를 지정하지 않으면 default-src 'self'로 폴백돼 차단된다.
            // stdpay.inicis.com — INIStdPay가 팝업 차단 해제용으로 넣는 allowPopupIframe.jsp.
            //   (결제창 자체는 window.open 팝업이라 frame-src 대상이 아니다.)
            // pay·sandbox-pay.nicepay.co.kr — 나이스페이 결제창은 iframe(newPayIframe)으로 열린다.
            //   clientId 접두어가 R1_/R2_면 운영(pay), S1_/S2_면 샌드박스(sandbox-pay)를 쓴다.
            //
            // postcode.map.kakao.com — 카카오 우편번호(주소검색) 창. SDK의 open()은
            // window.open('')으로 빈 팝업을 띄운 뒤 그 안에 iframe을 삽입하는데,
            // 그 팝업 문서는 about:blank(동일 출처)라 이 페이지의 CSP를 그대로 상속한다.
            // 따라서 팝업 방식이어도 frame-src 허용이 필요하다 — 빠지면 팝업 안이
            // "이 콘텐츠는 차단되어 있습니다"로 뜨고 주소검색이 불가능해진다.
            //
            // nsp.pay.naver.com — 네이버페이 결제창도 SDK가 띄운 팝업 안에 iframe으로
            // 렌더링된다(위 카카오 우편번호와 동일 구조). 빠지면 팝업이 뜨자마자
            // "연결을 거부했습니다"로 결제창 내용이 차단된다.
            "frame-src 'self' payment-gateway.tosspayments.com payment-gateway-sandbox.tosspayments.com stdpay.inicis.com pay.nicepay.co.kr sandbox-pay.nicepay.co.kr postcode.map.kakao.com nsp.pay.naver.com",

            // 이니시스·나이스페이는 결제창을 팝업이 아니라 페이지 위 레이어(iframe)로
            // 띄우고, 그 iframe 안에서 returnUrl·closeUrl(order/fail, payment/callback)을
            // 직접 로드한다. frame-ancestors 'none' 이면 우리 페이지가 그 iframe 안에서
            // "연결을 거부했습니다"로 차단돼 결제 종료 후 화면이 아예 뜨지 않는다 —
            // 이 두 PG 도메인만 예외로 허용한다(frame-src 목록과 대칭).
            'frame-ancestors stdpay.inicis.com pay.nicepay.co.kr sandbox-pay.nicepay.co.kr',
            "object-src 'none'",
            "base-uri 'self'",

            // 이니시스·나이스페이 SDK는 파라미터 form을 결제 도메인으로 POST 전송한다.
            // stdpay.inicis.com — INIStdPay.pay()가 form action을 payMain/pay로 바꿔 전송
            // pay·sandbox-pay.nicepay.co.kr — AUTHNICE가 결제창 iframe을 대상으로 form 전송
            // (카카오페이·페이코는 location.href 최상위 이동이라 CSP 대상이 아니다.)
            "form-action 'self' stdpay.inicis.com pay.nicepay.co.kr sandbox-pay.nicepay.co.kr",
        ]);

        $response->setHeader('Content-Security-Policy', $csp);
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
