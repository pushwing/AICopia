<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\SeoHelper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * SeoHelper 구조화 데이터(JSON-LD) 빌더 검증 — 이슈 #27 (SEO/GEO Phase 0)
 */
final class SeoHelperTest extends CIUnitTestCase
{
    public function testProductSchemaInStock(): void
    {
        $schema = SeoHelper::productSchema([
            'id'             => 5,
            'name'           => '기본 티셔츠',
            'slug'           => 'basic-tshirt',
            'price'          => 19000,
            'discount_price' => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'description'    => '<p>편한 티셔츠</p>',
        ]);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('기본 티셔츠', $schema['name']);
        $this->assertSame('5', $schema['sku']);
        $this->assertSame('편한 티셔츠', $schema['description']);
        $this->assertSame('KRW', $schema['offers']['priceCurrency']);
        $this->assertSame('19000', $schema['offers']['price']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    public function testProductSchemaUsesDiscountPriceAndOutOfStock(): void
    {
        $schema = SeoHelper::productSchema([
            'id'             => 7,
            'name'           => '할인 상품',
            'slug'           => 'sale-item',
            'price'          => 30000,
            'discount_price' => 21000,
            'stock'          => 0,
            'status'         => 'sold_out',
        ]);

        // 할인가가 있으면 할인가를 노출
        $this->assertSame('21000', $schema['offers']['price']);
        // 재고 0 · 품절이면 OutOfStock
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testProductSchemaAddsAggregateRatingAndReviews(): void
    {
        $schema = SeoHelper::productSchema(
            ['id' => 3, 'name' => 'p', 'slug' => 'p', 'price' => 1000, 'stock' => 5, 'status' => 'on_sale'],
            [],
            ['count' => 12, 'average' => 4.5],
            [
                ['author' => '김**', 'rating' => 5, 'body' => '좋아요', 'date' => '2026-06-01 09:00:00'],
                ['author' => '이**', 'rating' => 0, 'body' => '별점없음', 'date' => null], // rating 0 → 제외
            ]
        );

        $this->assertSame('AggregateRating', $schema['aggregateRating']['@type']);
        $this->assertSame('4.5', $schema['aggregateRating']['ratingValue']);
        $this->assertSame(12, $schema['aggregateRating']['reviewCount']);

        // rating 0인 리뷰는 제외되어 1건만 남음
        $this->assertCount(1, $schema['review']);
        $this->assertSame('5', $schema['review'][0]['reviewRating']['ratingValue']);
        $this->assertSame('김**', $schema['review'][0]['author']['name']);
        $this->assertArrayHasKey('datePublished', $schema['review'][0]);
    }

    public function testProductSchemaOmitsRatingWhenNoRatedReviews(): void
    {
        $schema = SeoHelper::productSchema(
            ['id' => 3, 'name' => 'p', 'slug' => 'p', 'price' => 1000, 'stock' => 5, 'status' => 'on_sale'],
            [],
            ['count' => 0, 'average' => 0.0],
            []
        );

        // 별점 데이터가 없으면 aggregateRating·review 키 자체가 없어야 함(빈 값 금지)
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
    }

    public function testProductSchemaAttachesImages(): void
    {
        $schema = SeoHelper::productSchema(
            ['id' => 1, 'name' => 'p', 'slug' => 'p', 'price' => 1000, 'stock' => 1, 'status' => 'on_sale'],
            [
                ['media_url' => 'https://cdn.test/a.jpg'],
                ['media_url' => 'https://cdn.test/b.jpg'],
                ['media_url' => ''], // 빈 값은 제외
            ]
        );

        $this->assertSame(['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'], $schema['image']);
    }

    public function testBreadcrumbSchemaPositionsAreSequential(): void
    {
        $schema = SeoHelper::breadcrumbSchema([
            ['name' => '홈', 'url' => 'https://test/'],
            ['name' => '상품', 'url' => 'https://test/shop'],
            ['name' => '티셔츠', 'url' => 'https://test/shop/tee'],
        ]);

        $this->assertSame('BreadcrumbList', $schema['@type']);
        $this->assertCount(3, $schema['itemListElement']);
        $this->assertSame(1, $schema['itemListElement'][0]['position']);
        $this->assertSame(3, $schema['itemListElement'][2]['position']);
        $this->assertSame('티셔츠', $schema['itemListElement'][2]['name']);
    }

    public function testFaqSchemaSkipsIncompleteEntries(): void
    {
        $schema = SeoHelper::faqSchema([
            ['question' => '배송 얼마나 걸리나요?', 'answer' => '2~3일 소요됩니다.'],
            ['question' => '교환 되나요?', 'answer' => ''],   // 답변 없음 → 제외
            ['question' => '', 'answer' => '질문 없음'],       // 질문 없음 → 제외
        ]);

        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(1, $schema['mainEntity']);
        $this->assertSame('배송 얼마나 걸리나요?', $schema['mainEntity'][0]['name']);
        $this->assertSame('2~3일 소요됩니다.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testFaqSchemaReturnsEmptyWhenNoValidEntries(): void
    {
        $this->assertSame([], SeoHelper::faqSchema([]));
        $this->assertSame([], SeoHelper::faqSchema([['question' => 'q', 'answer' => '']]));
    }

    public function testArticleSchemaIncludesAuthorAndDates(): void
    {
        $schema = SeoHelper::articleSchema([
            'title'         => '공지사항',
            'user_nickname' => '관리자',
            'created_at'    => '2026-06-01 10:00:00',
            'updated_at'    => '2026-06-02 11:00:00',
        ], 'https://test/board/notice/3');

        $this->assertSame('Article', $schema['@type']);
        $this->assertSame('공지사항', $schema['headline']);
        $this->assertSame('관리자', $schema['author']['name']);
        $this->assertSame('https://test/board/notice/3', $schema['mainEntityOfPage']);
        $this->assertArrayHasKey('datePublished', $schema);
        $this->assertArrayHasKey('dateModified', $schema);
    }
}
