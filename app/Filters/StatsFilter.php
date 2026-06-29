<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class StatsFilter implements FilterInterface
{
    /** 로깅 제외 경로 접두사 */
    private const SKIP_PREFIXES = [
        '/admin/',
        '/auth/',
        '/payment/callback/',
        '/board/image-upload',
    ];

    /** 정적 파일 확장자 */
    private const SKIP_EXTS = ['js', 'css', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'];

    /** 봇 탐지 키워드 */
    private const BOT_KEYWORDS = ['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'yandex', 'whatsapp', 'semrush', 'ahref', 'screaming'];

    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null
    {
        if (ENVIRONMENT === 'testing') return null;

        // JSON 응답 제외
        $ct = $response->getHeaderLine('Content-Type');
        if ($ct !== '' && ! str_contains($ct, 'text/html')) return null;

        // 오류 응답 제외
        if ($response->getStatusCode() >= 400) return null;

        /** @var \CodeIgniter\HTTP\IncomingRequest $request */
        $path = '/' . ltrim($request->getUri()->getPath(), '/');

        // 관리자·인증·콜백 등 제외
        if ($path === '/admin') return null;
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) return null;
        }

        // 정적 파일 제외
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::SKIP_EXTS, true)) return null;

        // 봇 제외
        $ua = strtolower($request->getUserAgent()->getAgentString());
        foreach (self::BOT_KEYWORDS as $bot) {
            if (str_contains($ua, $bot)) return null;
        }

        $page = mb_substr($path, 0, 500);
        $url  = mb_substr((string) $request->getUri(), 0, 1000);

        Database::connect()->table('access_logs')->insert([
            'ip'         => $request->getIPAddress(),
            'page'       => $page,
            'url'        => $url,
            'user_id'    => session()->get('user_id') ?: null,
            'user_agent' => mb_substr($ua, 0, 500) ?: null,
            'referer'    => mb_substr($request->getServer('HTTP_REFERER') ?? '', 0, 1000) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return null;
    }
}
