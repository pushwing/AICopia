<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\RequestInterface;

/**
 * 이니시스·나이스페이처럼 결제창을 자기 iframe 안에서 직접 그리는 PG 는
 * returnUrl·closeUrl 도 그 iframe 안에서 로드한다. 이때 SameSite=Lax 세션
 * 쿠키는 크로스사이트 iframe 서브요청에 실리지 않아 세션이 비어 보이고,
 * 인증이 필요한 페이지가 로그인 화면이나 엉뚱한 곳으로 튕겨버린다.
 *
 * iframe 안에서 로드된 요청이면 실제 처리 대신 최상위 창을 같은 URL로
 * 이동시키는 탈출(bridge) 페이지만 돌려준다 — 탈출 후 재요청은 최상위 이동이라
 * 세션이 정상 전달된다.
 */
final class FrameBridge
{
    /** 최신 브라우저는 iframe 으로 로드되는 요청에 Sec-Fetch-Dest: iframe 을 보낸다. */
    public static function isFramed(RequestInterface $request): bool
    {
        return $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe';
    }

    public static function render(string $targetUrl): string
    {
        return view('shop/frame_bridge', ['targetUrl' => $targetUrl]);
    }
}
