<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 페이지별 메타태그 / OG태그 / canonical / 구조화 데이터(JSON-LD) 생성
 */
class SeoHelper
{
    /** JSON-LD 인코딩 플래그 — <, >, &, ' 를 이스케이프해 <script> 이탈 방지 */
    private const JSON_LD_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /** @param array<string, mixed> $settings */
    public function __construct(private array $settings)
    {
    }

    /**
     * <head> 메타 블록 렌더.
     *
     * @param array<string, mixed>|null $page 페이지별 메타 오버라이드. 지원 키:
     *   meta_title, meta_desc(또는 title), og_image, og_type, canonical,
     *   jsonld(array<int, array<string, mixed>> — 페이지별 JSON-LD 그래프 목록)
     */
    public function render(?array $page = null): string
    {
        $siteName = $this->settings['site_name'] ?? '';
        $title    = $page['meta_title'] ?? ($page['title'] ?? $siteName);
        $desc     = $page['meta_desc']  ?? ($this->settings['site_desc'] ?? '');
        $ogImage  = $page['og_image']   ?? ($this->settings['site_logo'] ?? '');
        $ogType   = $page['og_type']    ?? 'website';
        // canonical: 명시값 우선, 없으면 쿼리스트링을 제거한 현재 URL(정렬·필터 파라미터 정규화)
        $canonical = $page['canonical'] ?? current_url();

        $html  = '<title>' . esc($title) . "</title>\n";
        $html .= '<meta name="description" content="' . esc($desc) . "\">\n";
        $html .= '<link rel="canonical" href="' . esc($canonical) . "\">\n";
        $html .= '<meta property="og:title" content="' . esc($title) . "\">\n";
        $html .= '<meta property="og:description" content="' . esc($desc) . "\">\n";
        $html .= '<meta property="og:url" content="' . esc($canonical) . "\">\n";
        $html .= '<meta property="og:site_name" content="' . esc($siteName) . "\">\n";
        $html .= '<meta property="og:type" content="' . esc($ogType) . "\">\n";

        if ($ogImage) {
            $html .= '<meta property="og:image" content="' . esc(base_url($ogImage)) . "\">\n";
        }

        // 트위터 카드 (이미지가 있으면 large summary)
        $html .= '<meta name="twitter:card" content="' . ($ogImage ? 'summary_large_image' : 'summary') . "\">\n";
        $html .= '<meta name="twitter:title" content="' . esc($title) . "\">\n";
        $html .= '<meta name="twitter:description" content="' . esc($desc) . "\">\n";
        if ($ogImage) {
            $html .= '<meta name="twitter:image" content="' . esc(base_url($ogImage)) . "\">\n";
        }

        // 네이버 웹마스터 인증
        if (! empty($this->settings['naver_verify'])) {
            $html .= '<meta name="naver-site-verification" content="' . esc($this->settings['naver_verify']) . "\">\n";
        }

        // ── 구조화 데이터(JSON-LD) ──────────────────────────────────────────
        // 사이트 전역: Organization + WebSite(+SearchAction)
        $html .= $this->jsonLdScript($this->organizationSchema());
        $html .= $this->jsonLdScript($this->websiteSchema());

        // 페이지별 그래프(Product, BreadcrumbList 등)
        if (! empty($page['jsonld']) && is_array($page['jsonld'])) {
            foreach ($page['jsonld'] as $graph) {
                if (is_array($graph) && $graph !== []) {
                    $html .= $this->jsonLdScript($graph);
                }
            }
        }

        return $html;
    }

    /**
     * Google Analytics 스크립트
     */
    public function gaScript(): string
    {
        $gaId = $this->settings['ga_id'] ?? '';
        if (! $gaId) {
            return '';
        }

        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$gaId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$gaId}');
</script>
HTML;
    }

    // ─── 스키마 빌더 ────────────────────────────────────────────────────────

    /**
     * 사이트 대표 Organization 스키마.
     *
     * @return array<string, mixed>
     */
    public function organizationSchema(): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $this->settings['site_name'] ?? '',
            'url'      => rtrim(base_url(), '/') . '/',
        ];

        if (! empty($this->settings['site_logo'])) {
            $schema['logo'] = base_url($this->settings['site_logo']);
        }

        return $schema;
    }

    /**
     * WebSite + 사이트 내 검색(SearchAction) 스키마.
     *
     * @return array<string, mixed>
     */
    public function websiteSchema(): array
    {
        $base = rtrim(base_url(), '/');

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            'name'            => $this->settings['site_name'] ?? '',
            'url'             => $base . '/',
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $base . '/shop?keyword={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * 상품 상세용 Product 스키마.
     *
     * @param array<string, mixed>             $product products 행
     * @param array<int, array<string, mixed>> $images  ProductImageModel::getByProduct 결과(media_url 포함)
     * @param array{count: int, average: float}|null                              $rating  평균 평점·리뷰 수(없거나 0이면 생략)
     * @param array<int, array{author: string, rating: int, body: string, date: ?string}> $reviews 개별 리뷰(별점 매겨진 것만)
     *
     * @return array<string, mixed>
     */
    public static function productSchema(array $product, array $images = [], ?array $rating = null, array $reviews = []): array
    {
        $stock   = (int) ($product['stock'] ?? 0);
        $status  = (string) ($product['status'] ?? '');
        $inStock = $stock > 0 && $status === 'on_sale';

        $discount = (int) ($product['discount_price'] ?? 0);
        $price    = $discount > 0 ? $discount : (int) ($product['price'] ?? 0);

        $imageUrls = [];
        foreach ($images as $img) {
            if (! empty($img['media_url'])) {
                $imageUrls[] = $img['media_url'];
            }
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => (string) ($product['name'] ?? ''),
            'sku'         => (string) ($product['id'] ?? ''),
            'description' => trim(strip_tags((string) ($product['description'] ?? ''))),
            'offers'      => [
                '@type'         => 'Offer',
                'url'           => base_url('shop/' . ($product['slug'] ?? '')),
                'priceCurrency' => 'KRW',
                'price'         => (string) $price,
                'availability'  => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        if ($imageUrls !== []) {
            $schema['image'] = $imageUrls;
        }

        // 평균 평점 — 실제 별점이 매겨진 리뷰가 있을 때만 포함(빈 값 금지)
        if ($rating !== null && $rating['count'] > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating['average'],
                'reviewCount' => $rating['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        // 개별 리뷰(별점 포함) — 스니펫 신뢰도 보강
        $reviewGraph = [];
        foreach ($reviews as $review) {
            $stars = $review['rating'];
            if ($stars < 1) {
                continue;
            }
            $entry = [
                '@type'        => 'Review',
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $stars,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
                'author'     => ['@type' => 'Person', 'name' => $review['author']],
                'reviewBody' => trim(strip_tags($review['body'])),
            ];
            if (! empty($review['date'])) {
                $entry['datePublished'] = date('c', strtotime($review['date']));
            }
            $reviewGraph[] = $entry;
        }
        if ($reviewGraph !== []) {
            $schema['review'] = $reviewGraph;
        }

        return $schema;
    }

    /**
     * BreadcrumbList 스키마.
     *
     * @param array<int, array{name: string, url: string}> $items 순서대로 [이름, URL]
     *
     * @return array<string, mixed>
     */
    public static function breadcrumbSchema(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $i => $item) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => (string) $item['name'],
                'item'     => (string) $item['url'],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * FAQPage 스키마. 답변이 달린 공개 Q&A만 넘길 것.
     *
     * @param array<int, array{question: string, answer: string}> $qnas
     *
     * @return array<string, mixed>
     */
    public static function faqSchema(array $qnas): array
    {
        $entities = [];
        foreach ($qnas as $qna) {
            $question = trim(strip_tags($qna['question']));
            $answer   = trim(strip_tags($qna['answer']));
            if ($question === '') {
                continue;
            }
            if ($answer === '') {
                continue;
            }
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $answer,
                ],
            ];
        }

        if ($entities === []) {
            return [];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * 게시판 글용 Article 스키마.
     *
     * @param array<string, mixed> $post posts 행(user_nickname/author_name, created_at, updated_at 포함)
     *
     * @return array<string, mixed>
     */
    public static function articleSchema(array $post, string $url): array
    {
        $author = (string) ($post['user_nickname'] ?? ($post['author_name'] ?? ''));

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => (string) ($post['title'] ?? ''),
            'mainEntityOfPage' => $url,
        ];

        if ($author !== '') {
            $schema['author'] = ['@type' => 'Person', 'name' => $author];
        }
        if (! empty($post['created_at'])) {
            $schema['datePublished'] = date('c', strtotime((string) $post['created_at']));
        }
        if (! empty($post['updated_at'])) {
            $schema['dateModified'] = date('c', strtotime((string) $post['updated_at']));
        }

        return $schema;
    }

    // ─── 내부 헬퍼 ──────────────────────────────────────────────────────────

    /**
     * 스키마 배열을 <script type="application/ld+json"> 블록으로 직렬화.
     *
     * @param array<string, mixed> $data
     */
    private function jsonLdScript(array $data): string
    {
        $json = json_encode($data, self::JSON_LD_FLAGS);
        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . "</script>\n";
    }
}
