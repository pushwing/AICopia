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
        $csp = implode('; ', [
            "default-src 'self'",
            // t1.kakaocdn.net — 카카오 우편번호(주소검색) 서비스 로더
            "script-src 'self' cdn.jsdelivr.net t1.kakaocdn.net 'unsafe-inline'",
            "style-src 'self' cdn.jsdelivr.net 'unsafe-inline'",
            "img-src 'self' data: blob: shopping-phinf.pstatic.net",
            "font-src 'self' cdn.jsdelivr.net data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->setHeader('Content-Security-Policy', $csp);
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
