<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductModel::getList() — 가격 범위·할인 필터 검증
 */
final class ProductSearchFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ProductModel $model;

    private array $cleanup = [
        'categories' => [],
        'products'   => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ProductModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['products'] !== []) {
            $db->table('product_categories')->whereIn('product_id', $this->cleanup['products'])->delete();
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['categories'] !== []) {
            $db->table('categories')->whereIn('id', $this->cleanup['categories'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertCategory(): int
    {
        $db = db_connect();
        $db->table('categories')->insert([
            'name'       => 'SF테스트',
            'slug'       => 'sf-cat-' . uniqid(),
            'parent_id'  => null,
            'sort_order' => 99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    private function insertProduct(int $catId, int $price, ?int $discountPrice = null, string $name = ''): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => $name ?: 'SF상품-' . uniqid(),
            'slug'           => 'sf-prod-' . uniqid(),
            'price'          => $price,
            'discount_price' => $discountPrice,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'fixed',
            'shipping_fee'   => 3000,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $db->table('product_categories')->insert(['product_id' => $id, 'category_id' => $catId]);
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function idsFromResult(array $result): array
    {
        return array_map('intval', array_column($result['items'], 'id'));
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_price_min_filters_out_cheaper_products(): void
    {
        $catId = $this->insertCategory();
        $cheap  = $this->insertProduct($catId, 5000);
        $mid    = $this->insertProduct($catId, 10000);
        $pricy  = $this->insertProduct($catId, 20000);

        $result = $this->model->getList(['category_id' => $catId, 'price_min' => 10000]);
        $ids = $this->idsFromResult($result);

        $this->assertContains($mid,   $ids);
        $this->assertContains($pricy, $ids);
        $this->assertNotContains($cheap, $ids);
    }

    public function test_price_max_filters_out_more_expensive_products(): void
    {
        $catId = $this->insertCategory();
        $cheap  = $this->insertProduct($catId, 5000);
        $mid    = $this->insertProduct($catId, 10000);
        $pricy  = $this->insertProduct($catId, 20000);

        $result = $this->model->getList(['category_id' => $catId, 'price_max' => 10000]);
        $ids = $this->idsFromResult($result);

        $this->assertContains($cheap, $ids);
        $this->assertContains($mid,   $ids);
        $this->assertNotContains($pricy, $ids);
    }

    public function test_price_range_returns_only_products_within_range(): void
    {
        $catId = $this->insertCategory();
        $low    = $this->insertProduct($catId, 3000);
        $mid    = $this->insertProduct($catId, 8000);
        $high   = $this->insertProduct($catId, 50000);

        $result = $this->model->getList(['category_id' => $catId, 'price_min' => 5000, 'price_max' => 20000]);
        $ids = $this->idsFromResult($result);

        $this->assertContains($mid, $ids);
        $this->assertNotContains($low,  $ids);
        $this->assertNotContains($high, $ids);
    }

    public function test_price_filter_uses_discount_price_when_set(): void
    {
        $catId = $this->insertCategory();
        $discounted = $this->insertProduct($catId, 30000, 8000);
        $normal     = $this->insertProduct($catId, 8000);

        $result = $this->model->getList(['category_id' => $catId, 'price_min' => 10000]);
        $ids = $this->idsFromResult($result);

        $this->assertNotContains($discounted, $ids, '할인가 8000은 price_min=10000에 걸려야 함');
        $this->assertNotContains($normal,     $ids, '정가 8000도 price_min=10000에 걸려야 함');
    }

    public function test_discount_price_included_when_below_max(): void
    {
        $catId = $this->insertCategory();
        $discounted = $this->insertProduct($catId, 50000, 5000);

        $result = $this->model->getList(['category_id' => $catId, 'price_max' => 10000]);
        $ids = $this->idsFromResult($result);

        $this->assertContains($discounted, $ids, '할인가 5000은 price_max=10000 안에 포함되어야 함');
    }

    public function test_only_discount_returns_only_discounted_products(): void
    {
        $catId = $this->insertCategory();
        $regular    = $this->insertProduct($catId, 10000);
        $discounted = $this->insertProduct($catId, 10000, 8000);

        $result = $this->model->getList(['category_id' => $catId, 'only_discount' => '1']);
        $ids = $this->idsFromResult($result);

        $this->assertContains($discounted, $ids);
        $this->assertNotContains($regular,   $ids);
    }

    public function test_keyword_and_price_min_combined(): void
    {
        $catId = $this->insertCategory();
        $match     = $this->insertProduct($catId, 15000, null, 'SF검색타겟상품');
        $tooChap   = $this->insertProduct($catId, 3000,  null, 'SF검색타겟상품');
        $noKeyword = $this->insertProduct($catId, 15000);

        $result = $this->model->getList([
            'category_id' => $catId,
            'keyword'     => 'SF검색타겟상품',
            'price_min'   => 10000,
        ]);
        $ids = $this->idsFromResult($result);

        $this->assertContains($match,     $ids);
        $this->assertNotContains($tooChap,   $ids);
        $this->assertNotContains($noKeyword, $ids);
    }

    public function test_empty_price_params_return_all_products(): void
    {
        $catId = $this->insertCategory();
        $a = $this->insertProduct($catId, 1000);
        $b = $this->insertProduct($catId, 999000);

        $result = $this->model->getList(['category_id' => $catId, 'price_min' => '', 'price_max' => '']);
        $ids = $this->idsFromResult($result);

        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);
    }
}
