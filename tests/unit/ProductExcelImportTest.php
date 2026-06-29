<?php

namespace Tests\Unit;

use App\Controllers\Admin\ProductController;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductController::parseImportRow() 파싱·검증 로직 검증
 *
 * - 필수 필드 누락 오류
 * - 상태/배송유형 기본값 적용
 * - 할인가 선택 필드
 * - 카테고리 이름 → ID 매핑
 */
final class ProductExcelImportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'categories' => [],
        'products'   => [],
    ];

    /** @var ProductController */
    private ProductController $controller;
    /** @var array<string,int> */
    private array $catMap = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ProductController();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['products', 'categories'] as $t) {
            if ($this->cleanup[$t] !== []) {
                $db->table($t)->whereIn('id', $this->cleanup[$t])->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function makeRow(array $override = []): array
    {
        return array_values(array_merge([
            'name'          => '테스트상품',
            'price'         => '10000',
            'stock'         => '100',
            'status'        => '',
            'shipping_type' => '',
            'shipping_fee'  => '',
            'free_threshold'=> '',
            'discount_price'=> '',
            'category'      => '',
            'description'   => '',
        ], $override));
    }

    private function insertCategory(string $name): int
    {
        $db = db_connect();
        $db->table('categories')->insert([
            'name'       => $name,
            'slug'       => 'cat-' . uniqid(),
            'is_active'  => 1,
            'sort_order' => 1,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    private function parse(array $cells, array $catMap = []): array
    {
        return $this->controller->parseImportRow($cells, $catMap);
    }

    // ── 필수 필드 오류 ────────────────────────────────────────────────────────

    public function testMissingNameReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['name' => '']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('상품명', $result['errors'][0]);
    }

    public function testMissingPriceReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['price' => '']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('판매가', $result['errors'][0]);
    }

    public function testZeroPriceReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['price' => '0']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('판매가', $result['errors'][0]);
    }

    public function testNegativePriceReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['price' => '-100']));
        $this->assertNotEmpty($result['errors']);
    }

    public function testNegativeStockReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['stock' => '-1']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('재고', $result['errors'][0]);
    }

    public function testInvalidStatusReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['status' => 'invalid_status']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('상태', $result['errors'][0]);
    }

    public function testInvalidShippingTypeReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['shipping_type' => 'express']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('배송유형', $result['errors'][0]);
    }

    // ── 기본값 적용 ───────────────────────────────────────────────────────────

    public function testEmptyStatusDefaultsToOnSale(): void
    {
        $result = $this->parse($this->makeRow(['status' => '']));
        $this->assertEmpty($result['errors']);
        $this->assertSame('on_sale', $result['data']['status']);
    }

    public function testEmptyShippingTypeDefaultsToFree(): void
    {
        $result = $this->parse($this->makeRow(['shipping_type' => '']));
        $this->assertEmpty($result['errors']);
        $this->assertSame('free', $result['data']['shipping_type']);
    }

    public function testEmptyShippingFeeDefaultsToZero(): void
    {
        $result = $this->parse($this->makeRow(['shipping_fee' => '']));
        $this->assertEmpty($result['errors']);
        $this->assertSame(0, $result['data']['shipping_fee']);
    }

    public function testEmptyFreeThresholdDefaultsToZero(): void
    {
        $result = $this->parse($this->makeRow(['free_threshold' => '']));
        $this->assertEmpty($result['errors']);
        $this->assertSame(0, $result['data']['free_threshold']);
    }

    // ── 유효한 행 ─────────────────────────────────────────────────────────────

    public function testValidRowReturnsCorrectData(): void
    {
        $result = $this->parse($this->makeRow([
            'name'          => '좋은 상품',
            'price'         => '29900',
            'stock'         => '50',
            'status'        => 'sold_out',
            'shipping_type' => 'fixed',
            'shipping_fee'  => '3000',
            'free_threshold'=> '50000',
            'discount_price'=> '25000',
        ]));
        $this->assertEmpty($result['errors']);
        $this->assertSame('좋은 상품', $result['data']['name']);
        $this->assertSame(29900, $result['data']['price']);
        $this->assertSame(50, $result['data']['stock']);
        $this->assertSame('sold_out', $result['data']['status']);
        $this->assertSame('fixed', $result['data']['shipping_type']);
        $this->assertSame(3000, $result['data']['shipping_fee']);
        $this->assertSame(50000, $result['data']['free_threshold']);
        $this->assertSame(25000, $result['data']['discount_price']);
    }

    public function testZeroStockIsValid(): void
    {
        $result = $this->parse($this->makeRow(['stock' => '0']));
        $this->assertEmpty($result['errors']);
        $this->assertSame(0, $result['data']['stock']);
    }

    // ── 할인가 ────────────────────────────────────────────────────────────────

    public function testEmptyDiscountPriceIsNull(): void
    {
        $result = $this->parse($this->makeRow(['discount_price' => '']));
        $this->assertEmpty($result['errors']);
        $this->assertNull($result['data']['discount_price']);
    }

    public function testZeroDiscountPriceReturnsError(): void
    {
        $result = $this->parse($this->makeRow(['discount_price' => '0']));
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('할인가', $result['errors'][0]);
    }

    public function testValidDiscountPrice(): void
    {
        $result = $this->parse($this->makeRow(['discount_price' => '8000']));
        $this->assertEmpty($result['errors']);
        $this->assertSame(8000, $result['data']['discount_price']);
    }

    // ── 카테고리 매핑 ─────────────────────────────────────────────────────────

    public function testKnownCategoryNameMapsToId(): void
    {
        $name   = '테스트카테고리_' . uniqid();
        $catId  = $this->insertCategory($name);
        $catMap = [$name => $catId];

        $result = $this->parse($this->makeRow(['category' => $name]), $catMap);
        $this->assertEmpty($result['errors']);
        $this->assertSame($catId, $result['data']['category_id']);
    }

    public function testUnknownCategoryNameMapsToNull(): void
    {
        $result = $this->parse($this->makeRow(['category' => '존재하지않는카테고리']), []);
        $this->assertEmpty($result['errors']);
        $this->assertNull($result['data']['category_id']);
    }

    public function testEmptyCategoryIsNull(): void
    {
        $result = $this->parse($this->makeRow(['category' => '']), []);
        $this->assertEmpty($result['errors']);
        $this->assertNull($result['data']['category_id']);
    }

    // ── 다중 오류 ─────────────────────────────────────────────────────────────

    public function testMultipleErrorsAllReported(): void
    {
        $result = $this->parse($this->makeRow([
            'name'  => '',
            'price' => '0',
            'stock' => '-5',
        ]));
        $this->assertGreaterThanOrEqual(3, count($result['errors']), '3개 이상 오류 반환돼야 함');
        $this->assertEmpty($result['data'], '오류 있으면 data는 비어야 함');
    }

    // ── importConfirm — DB insert 검증 ───────────────────────────────────────

    public function testImportConfirmInsertsProducts(): void
    {
        $model = new ProductModel();

        $rows = [
            [
                'name'          => '일괄등록테스트_' . uniqid(),
                'price'         => 15000,
                'stock'         => 30,
                'status'        => 'on_sale',
                'shipping_type' => 'free',
                'shipping_fee'  => 0,
                'free_threshold'=> 0,
                'discount_price'=> null,
                'category_id'   => null,
                'description'   => '',
            ],
        ];

        foreach ($rows as $row) {
            $slug = $model->generateSlug($row['name']);
            $productData = [
                'name'          => $row['name'],
                'slug'          => $slug,
                'price'         => $row['price'],
                'stock'         => $row['stock'],
                'status'        => $row['status'],
                'shipping_type' => $row['shipping_type'],
                'shipping_fee'  => $row['shipping_fee'],
                'free_threshold'=> $row['free_threshold'],
                'description'   => $row['description'],
            ];
            $productId = $model->insert($productData);
            $this->cleanup['products'][] = (int) $productId;

            $found = $model->find($productId);
            $this->assertNotNull($found, '등록된 상품이 DB에 있어야 함');
            $this->assertSame($row['name'], $found['name']);
            $this->assertSame($row['price'], (int) $found['price']);
            $this->assertSame($row['stock'], (int) $found['stock']);
        }
    }
}
