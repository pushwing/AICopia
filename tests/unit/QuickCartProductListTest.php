<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 목록 빠른 장바구니 — has_options 필드 검증
 */
final class QuickCartProductListTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ProductModel $model;

    private array $cleanup = [
        'categories'      => [],
        'products'        => [],
        'product_options' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ProductModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['product_options'] !== []) {
            $db->table('product_options')->whereIn('id', $this->cleanup['product_options'])->delete();
        }
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

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertCategory(): int
    {
        $db = db_connect();
        $db->table('categories')->insert([
            'name'       => 'QL테스트',
            'slug'       => 'ql-cat-' . uniqid(),
            'parent_id'  => null,
            'sort_order' => 99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    private function insertProduct(int $categoryId, string $status = 'on_sale'): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'QL상품-' . uniqid(),
            'slug'           => 'ql-prod-' . uniqid(),
            'price'          => 10000,
            'stock'          => $status === 'sold_out' ? 0 : 5,
            'status'         => $status,
            'shipping_type'  => 'fixed',
            'shipping_fee'   => 3000,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $db->table('product_categories')->insert(['product_id' => $id, 'category_id' => $categoryId]);
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOption(int $productId): int
    {
        $db = db_connect();
        $db->table('product_options')->insert([
            'product_id' => $productId,
            'name'       => '사이즈',
            'sort_order' => 1,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['product_options'][] = $id;
        return $id;
    }

    private function getListByCategory(int $catId): array
    {
        $result = $this->model->getList(['category_id' => $catId, 'per_page' => 100]);
        return array_column($result['items'], null, 'id');
    }

    // ── has_options 필드 ───────────────────────────────────────────────────────

    /** 옵션 없는 상품은 has_options = 0 */
    public function testProductWithoutOptionsHasOptionsZero(): void
    {
        $catId = $this->insertCategory();
        $pid   = $this->insertProduct($catId);

        $products = $this->getListByCategory($catId);

        $this->assertArrayHasKey($pid, $products);
        $this->assertSame(0, (int) $products[$pid]['has_options']);
    }

    /** 옵션 있는 상품은 has_options = 1 */
    public function testProductWithOptionsHasOptionsOne(): void
    {
        $catId = $this->insertCategory();
        $pid   = $this->insertProduct($catId);
        $this->insertOption($pid);

        $products = $this->getListByCategory($catId);

        $this->assertArrayHasKey($pid, $products);
        $this->assertSame(1, (int) $products[$pid]['has_options']);
    }

    /** 옵션 추가 전후 has_options 값 변화 확인 */
    public function testHasOptionsChangesAfterOptionAdded(): void
    {
        $catId = $this->insertCategory();
        $pid   = $this->insertProduct($catId);

        $before = $this->getListByCategory($catId);
        $this->assertSame(0, (int) $before[$pid]['has_options'], '옵션 추가 전 0');

        $this->insertOption($pid);

        $after = $this->getListByCategory($catId);
        $this->assertSame(1, (int) $after[$pid]['has_options'], '옵션 추가 후 1');
    }

    /** 한 카테고리에 옵션 있는 상품과 없는 상품이 공존할 때 각각 올바른 값 */
    public function testMixedProductsInSameCategory(): void
    {
        $catId    = $this->insertCategory();
        $noOptPid = $this->insertProduct($catId);
        $optPid   = $this->insertProduct($catId);
        $this->insertOption($optPid);

        $products = $this->getListByCategory($catId);

        $this->assertSame(0, (int) $products[$noOptPid]['has_options'], '옵션 없는 상품');
        $this->assertSame(1, (int) $products[$optPid]['has_options'],   '옵션 있는 상품');
    }

    // ── 목록 포함/제외 ─────────────────────────────────────────────────────────

    /** sold_out 상품은 목록에 포함 */
    public function testSoldOutProductAppearsInList(): void
    {
        $catId = $this->insertCategory();
        $pid   = $this->insertProduct($catId, 'sold_out');

        $products = $this->getListByCategory($catId);

        $this->assertArrayHasKey($pid, $products);
        $this->assertSame('sold_out', $products[$pid]['status']);
    }

    /** hidden 상품은 목록에서 제외 */
    public function testHiddenProductNotInList(): void
    {
        $catId = $this->insertCategory();
        $pid   = $this->insertProduct($catId, 'hidden');

        $products = $this->getListByCategory($catId);

        $this->assertArrayNotHasKey($pid, $products);
    }
}
