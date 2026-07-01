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
}
