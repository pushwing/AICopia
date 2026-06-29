<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 미분류 상품 카테고리 배정 기능 검증
 *
 * - unassigned 쿼리: product_categories 미배정 상품만 반환
 * - deleted_at 있는 상품 제외
 * - 카테고리 있는 상품은 목록에서 제외
 * - assignCategory 머지 동작: 기존 카테고리 유지 + 신규 추가
 * - 중복 배정 방지 (array_unique)
 * - 배정 후 미분류 목록에서 사라짐
 */
final class UnassignedProductTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private ProductModel $productModel;

    private array $cleanup = [
        'product_categories' => [],
        'categories'         => [],
        'products'           => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix       = 'UAT' . substr(uniqid(), -6) . '_';
        $this->productModel = new ProductModel();
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

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'name'          => $this->prefix . $uid,
            'slug'          => $this->prefix . $uid,
            'price'         => 10000,
            'stock'         => 5,
            'status'        => 'on_sale',
            'shipping_type' => 'free',
            'shipping_fee'  => 0,
            'free_threshold'=> 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('products')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertCategory(): int
    {
        $db = db_connect();
        $db->table('categories')->insert([
            'name'       => $this->prefix . uniqid(),
            'slug'       => $this->prefix . uniqid(),
            'parent_id'  => null,
            'sort_order' => 99,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    private function assignCategoryToProduct(int $productId, int $categoryId): void
    {
        $this->productModel->setCategories($productId, [$categoryId]);
    }

    /**
     * ProductController::unassigned() 와 동일한 쿼리
     * 테스트용 prefix로 필터링해 격리
     */
    private function fetchUnassignedIds(): array
    {
        $db   = db_connect();
        $rows = $db->table('products')
            ->select('products.id')
            ->like('products.name', $this->prefix, 'after')
            ->where('products.deleted_at IS NULL', null, false)
            ->where("NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = products.id)", null, false)
            ->orderBy('products.id', 'DESC')
            ->get()->getResultArray();
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * ProductController::unassigned() 전체 조회 (only_unassigned=false)
     */
    private function fetchAllIds(string $keyword = ''): array
    {
        $db      = db_connect();
        $builder = $db->table('products')
            ->select('products.id')
            ->like('products.name', $this->prefix, 'after')
            ->where('products.deleted_at IS NULL', null, false)
            ->orderBy('products.id', 'DESC');
        if ($keyword !== '') {
            $builder->like('products.name', $keyword);
        }
        return array_map(fn($r) => (int) $r['id'], $builder->get()->getResultArray());
    }

    /**
     * category_names 포함 전체 조회
     */
    private function fetchWithCategoryNames(array $ids): array
    {
        $db = db_connect();
        return $db->table('products')
            ->select("products.id,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR ', ')
                 FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                 WHERE pc.product_id = products.id) AS category_names")
            ->whereIn('products.id', $ids)
            ->get()->getResultArray();
    }

    /**
     * ProductController::assignCategory() 와 동일한 머지 로직
     */
    private function runAssignCategory(array $productIds, array $categoryIds): void
    {
        foreach ($productIds as $pid) {
            $existing = $this->productModel->getCategories($pid);
            $merged   = array_unique(array_merge($existing, $categoryIds));
            $this->productModel->setCategories($pid, $merged);
        }
    }

    // ── unassigned 쿼리 ───────────────────────────────────────────────────────

    public function testUnassignedProductAppearsInList(): void
    {
        $id = $this->insertProduct();

        $ids = $this->fetchUnassignedIds();

        $this->assertContains($id, $ids, '카테고리 없는 상품은 미분류 목록에 나타나야 한다');
    }

    public function testProductWithCategoryExcludedFromUnassigned(): void
    {
        $id    = $this->insertProduct();
        $catId = $this->insertCategory();
        $this->assignCategoryToProduct($id, $catId);

        $ids = $this->fetchUnassignedIds();

        $this->assertNotContains($id, $ids, '카테고리 있는 상품은 미분류 목록에 나타나지 않아야 한다');
    }

    public function testDeletedProductExcludedFromUnassigned(): void
    {
        $id = $this->insertProduct(['deleted_at' => date('Y-m-d H:i:s')]);

        $ids = $this->fetchUnassignedIds();

        $this->assertNotContains($id, $ids, 'soft-delete 상품은 미분류 목록에 나타나지 않아야 한다');
    }

    public function testMultipleUnassignedProductsAllAppear(): void
    {
        $id1 = $this->insertProduct();
        $id2 = $this->insertProduct();
        $id3 = $this->insertProduct();

        $ids = $this->fetchUnassignedIds();

        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertContains($id3, $ids);
    }

    public function testMixedProductsOnlyUnassignedReturned(): void
    {
        $unassigned = $this->insertProduct();
        $assigned   = $this->insertProduct();
        $catId      = $this->insertCategory();
        $this->assignCategoryToProduct($assigned, $catId);

        $ids = $this->fetchUnassignedIds();

        $this->assertContains($unassigned, $ids);
        $this->assertNotContains($assigned, $ids);
    }

    // ── assignCategory 머지 동작 ──────────────────────────────────────────────

    public function testAssignAddsCategoriesToProduct(): void
    {
        $id    = $this->insertProduct();
        $catId = $this->insertCategory();

        $this->runAssignCategory([$id], [$catId]);

        $cats = $this->productModel->getCategories($id);
        $this->assertContains($catId, $cats, '배정 후 카테고리 ID가 포함되어야 한다');
    }

    public function testAssignMergesWithExistingCategories(): void
    {
        $id      = $this->insertProduct();
        $catId1  = $this->insertCategory();
        $catId2  = $this->insertCategory();
        $this->productModel->setCategories($id, [$catId1]);

        $this->runAssignCategory([$id], [$catId2]);

        $cats = $this->productModel->getCategories($id);
        $this->assertContains($catId1, $cats, '기존 카테고리가 유지되어야 한다');
        $this->assertContains($catId2, $cats, '신규 카테고리가 추가되어야 한다');
    }

    public function testAssignDoesNotDuplicateCategory(): void
    {
        $id    = $this->insertProduct();
        $catId = $this->insertCategory();
        $this->productModel->setCategories($id, [$catId]);

        $this->runAssignCategory([$id], [$catId]);

        $cats = $this->productModel->getCategories($id);
        $this->assertCount(1, $cats, '같은 카테고리 중복 배정 시 1개여야 한다');
    }

    public function testAssignMultipleProductsAtOnce(): void
    {
        $id1   = $this->insertProduct();
        $id2   = $this->insertProduct();
        $catId = $this->insertCategory();

        $this->runAssignCategory([$id1, $id2], [$catId]);

        $cats1 = $this->productModel->getCategories($id1);
        $cats2 = $this->productModel->getCategories($id2);
        $this->assertContains($catId, $cats1);
        $this->assertContains($catId, $cats2);
    }

    public function testAssignMultipleCategoriesToOneProduct(): void
    {
        $id    = $this->insertProduct();
        $cat1  = $this->insertCategory();
        $cat2  = $this->insertCategory();

        $this->runAssignCategory([$id], [$cat1, $cat2]);

        $cats = $this->productModel->getCategories($id);
        $this->assertContains($cat1, $cats);
        $this->assertContains($cat2, $cats);
    }

    // ── 배정 후 미분류 목록에서 사라짐 ───────────────────────────────────────

    public function testProductDisappearsFromUnassignedAfterAssignment(): void
    {
        $id    = $this->insertProduct();
        $catId = $this->insertCategory();

        $before = $this->fetchUnassignedIds();
        $this->assertContains($id, $before, '배정 전에는 미분류 목록에 있어야 한다');

        $this->runAssignCategory([$id], [$catId]);

        $after = $this->fetchUnassignedIds();
        $this->assertNotContains($id, $after, '배정 후에는 미분류 목록에서 사라져야 한다');
    }

    public function testOnlyAssignedProductDisappearsNotOthers(): void
    {
        $id1   = $this->insertProduct();
        $id2   = $this->insertProduct();
        $catId = $this->insertCategory();

        $this->runAssignCategory([$id1], [$catId]);

        $after = $this->fetchUnassignedIds();
        $this->assertNotContains($id1, $after, '배정된 상품은 사라져야 한다');
        $this->assertContains($id2, $after, '배정 안 된 상품은 여전히 미분류 목록에 있어야 한다');
    }

    // ── 전체 조회 (only_unassigned=false) ────────────────────────────────────

    public function testAllProductsReturnedWithoutFilter(): void
    {
        $idUnassigned = $this->insertProduct();
        $idAssigned   = $this->insertProduct();
        $catId        = $this->insertCategory();
        $this->productModel->setCategories($idAssigned, [$catId]);

        $ids = $this->fetchAllIds();

        $this->assertContains($idUnassigned, $ids, '미분류 상품도 전체 목록에 나타나야 한다');
        $this->assertContains($idAssigned,   $ids, '배정된 상품도 전체 목록에 나타나야 한다');
    }

    public function testKeywordFilterNarrowsResults(): void
    {
        $match    = $this->insertProduct(['name' => $this->prefix . 'FINDME_' . uniqid()]);
        $noMatch  = $this->insertProduct(['name' => $this->prefix . 'OTHER_'  . uniqid()]);

        $ids = $this->fetchAllIds('FINDME_');

        $this->assertContains($match,   $ids, '키워드에 일치하는 상품이 포함되어야 한다');
        $this->assertNotContains($noMatch, $ids, '키워드에 맞지 않는 상품은 제외되어야 한다');
    }

    // ── category_names 필드 ───────────────────────────────────────────────────

    public function testCategoryNamesPopulatedAfterAssignment(): void
    {
        $id    = $this->insertProduct();
        $catId = $this->insertCategory();
        $catName = db_connect()->table('categories')->where('id', $catId)->get()->getRowArray()['name'];

        $this->productModel->setCategories($id, [$catId]);

        $rows = $this->fetchWithCategoryNames([$id]);
        $this->assertSame($catName, $rows[0]['category_names'],
            '배정 후 category_names 에 카테고리명이 표시되어야 한다');
    }

    public function testCategoryNamesNullWhenNoCategory(): void
    {
        $id   = $this->insertProduct();
        $rows = $this->fetchWithCategoryNames([$id]);

        $this->assertNull($rows[0]['category_names'],
            '카테고리 없는 상품의 category_names 는 NULL 이어야 한다');
    }

    public function testCategoryNamesContainsMultipleCategories(): void
    {
        $id    = $this->insertProduct();
        $cat1  = $this->insertCategory();
        $cat2  = $this->insertCategory();
        $this->productModel->setCategories($id, [$cat1, $cat2]);

        $rows  = $this->fetchWithCategoryNames([$id]);
        $names = $rows[0]['category_names'];

        $this->assertNotEmpty($names);
        $this->assertStringContainsString(',', $names,
            '여러 카테고리가 있으면 GROUP_CONCAT 으로 쉼표 구분되어야 한다');
    }
}
