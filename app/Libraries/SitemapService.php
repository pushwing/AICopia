<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Database;

/**
 * sitemap.xml 에 포함할 공개 URL 목록 수집.
 *
 * 색인 대상만 포함한다: 홈, 상품 목록, 공개 상품 상세, 공개 게시판 및 글, 공개 정적 페이지.
 * 관리자·주문·마이페이지·쿼리 파라미터 URL은 제외한다.
 */
class SitemapService
{
    /**
     * 사이트맵 항목 목록을 반환.
     *
     * @return array<int, array{loc: string, lastmod: ?string, changefreq: string, priority: string}>
     */
    public function collect(): array
    {
        $db = Database::connect();

        $urls = [];

        // 홈 · 상품 목록
        $urls[] = $this->entry(base_url('/'), null, 'daily', '1.0');
        $urls[] = $this->entry(base_url('shop'), null, 'daily', '0.9');

        // 공개 상품 상세 (판매중 · 품절)
        $products = $db->table('products')
            ->select('slug, updated_at')
            ->whereIn('status', ['on_sale', 'sold_out'])
            ->where('slug IS NOT NULL')
            ->get()->getResultArray();
        foreach ($products as $p) {
            if ($p['slug'] === null || $p['slug'] === '') {
                continue;
            }
            $urls[] = $this->entry(
                base_url('shop/' . $p['slug']),
                $this->w3cDate($p['updated_at'] ?? null),
                'weekly',
                '0.8'
            );
        }

        // 카테고리 랜딩 (소개 카피가 있는 활성 카테고리만 — 얇은 페이지 색인 방지)
        $categories = $db->table('categories')
            ->select('id, updated_at')
            ->where('is_active', 1)
            ->where('description IS NOT NULL')
            ->where('description !=', '')
            ->get()->getResultArray();
        foreach ($categories as $cat) {
            $urls[] = $this->entry(
                base_url('shop') . '?category_id=' . $cat['id'],
                $this->w3cDate($cat['updated_at'] ?? null),
                'weekly',
                '0.7'
            );
        }

        // 공개 정적 페이지 (published)
        $pages = $db->table('pages')
            ->select('slug, updated_at')
            ->where('status', 'published')
            ->get()->getResultArray();
        foreach ($pages as $pg) {
            $urls[] = $this->entry(
                base_url($pg['slug']),
                $this->w3cDate($pg['updated_at'] ?? null),
                'monthly',
                '0.5'
            );
        }

        // 공개 게시판(비회원 열람 가능) 목록 + 글
        $boards = $db->table('boards')
            ->select('id, slug')
            ->where('is_active', 1)
            ->where('read_permission', 'guest')
            ->get()->getResultArray();

        $boardSlugById = [];
        foreach ($boards as $b) {
            $boardSlugById[(int) $b['id']] = $b['slug'];
            $urls[] = $this->entry(base_url('board/' . $b['slug']), null, 'daily', '0.6');
        }

        if ($boardSlugById !== []) {
            $posts = $db->table('posts')
                ->select('id, board_id, updated_at')
                ->whereIn('board_id', array_keys($boardSlugById))
                ->where('is_secret', 0)
                ->get()->getResultArray();
            foreach ($posts as $post) {
                $slug = $boardSlugById[(int) $post['board_id']] ?? null;
                if ($slug === null) {
                    continue;
                }
                $urls[] = $this->entry(
                    base_url('board/' . $slug . '/' . $post['id']),
                    $this->w3cDate($post['updated_at'] ?? null),
                    'weekly',
                    '0.4'
                );
            }
        }

        return $urls;
    }

    /**
     * @return array{loc: string, lastmod: ?string, changefreq: string, priority: string}
     */
    private function entry(string $loc, ?string $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc'        => $loc,
            'lastmod'    => $lastmod,
            'changefreq' => $changefreq,
            'priority'   => $priority,
        ];
    }

    /** DB 타임스탬프를 W3C(ISO8601) 형식으로 변환. */
    private function w3cDate(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || $timestamp === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime($timestamp);

        return $ts === false ? null : date('c', $ts);
    }
}
