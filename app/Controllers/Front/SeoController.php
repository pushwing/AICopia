<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\SitemapService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * robots.txt / sitemap.xml 동적 생성.
 */
class SeoController extends BaseController
{
    /**
     * 크롤 허용(Disallow보다 더 구체적 → 우선). 카테고리 랜딩은 색인 대상이므로
     * 쿼리 URL 전면 차단(`/*?`)에서 예외로 허용한다.
     */
    private const ALLOW = [
        '/shop?category_id=',
    ];

    /** 봇 무관 크롤 차단 경로(비공개·기능성 URL) */
    private const DISALLOW = [
        '/admin/',
        '/cart',
        '/order',
        '/mypage',
        '/auth',
        '/payment/',
        '/*?', // 쿼리 파라미터 URL(정렬·필터·검색) — canonical로 정규화됨
    ];

    /** 실시간 검색·인용용 AI 봇(브랜드 노출 목적으로 허용) */
    private const AI_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'ClaudeBot'];

    /** GET /robots.txt */
    public function robots(): ResponseInterface
    {
        $lines = [];

        // 일반 크롤러
        $lines[] = 'User-agent: *';
        foreach (self::ALLOW as $path) {
            $lines[] = 'Allow: ' . $path;
        }
        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = '';

        // 검색·인용용 AI 봇: 공개 콘텐츠 허용 + 비공개 경로는 동일 차단
        foreach (self::AI_BOTS as $bot) {
            $lines[] = 'User-agent: ' . $bot;
            foreach (self::ALLOW as $path) {
                $lines[] = 'Allow: ' . $path;
            }
            foreach (self::DISALLOW as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = 'Sitemap: ' . base_url('sitemap.xml');

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->setBody(implode("\n", $lines) . "\n");
    }

    /** GET /sitemap.xml */
    public function sitemap(): ResponseInterface
    {
        $cache = service('cache');
        $xml   = $cache->get('sitemap_xml');

        if (! is_string($xml)) {
            $xml = $this->buildSitemapXml((new SitemapService())->collect());
            $cache->save('sitemap_xml', $xml, 3600); // 1시간 캐시
        }

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->setBody($xml);
    }

    /**
     * 사이트맵 항목 배열을 XML 문자열로 직렬화.
     *
     * @param array<int, array{loc: string, lastmod: ?string, changefreq: string, priority: string}> $urls
     */
    private function buildSitemapXml(array $urls): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . esc($url['loc']) . '</loc>' . "\n";
            if (! empty($url['lastmod'])) {
                $xml .= '    <lastmod>' . esc($url['lastmod']) . '</lastmod>' . "\n";
            }
            $xml .= '    <changefreq>' . esc($url['changefreq']) . '</changefreq>' . "\n";
            $xml .= '    <priority>' . esc($url['priority']) . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>' . "\n";

        return $xml;
    }
}
