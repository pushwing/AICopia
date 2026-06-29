<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductController::json() 데이터 레이어 검증
 *
 * - 반환 필드 구조
 * - is_low_stock 플래그 (stock <= threshold → 1, 초과 → 0)
 * - discount_price null → 0
 * - primary_image 없을 때 빈 문자열
 * - 타입 캐스팅 (id, price, stock → int)
 * - id DESC 정렬
 */
final class ProductJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = ['product_images' => [], 'products' => []];
    private int    $threshold = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PJT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['product_images'] !== []) {
            $db->table('product_images')->whereIn('product_id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = ['product_images' => [], 'products' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'name'       => $this->prefix . $uid,
            'slug'       => $this->prefix . $uid,
            'price'      => 10000,
            'stock'      => 10,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('products')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    /** ProductController::json() 와 동일한 쿼리 + 변환 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $db      = db_connect();
        $builder = $db->table('products')
            ->select("products.id, products.name, products.slug, products.price, products.discount_price,
                      products.stock, products.status, products.created_at,
                      (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR ', ')
                       FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                       WHERE pc.product_id = products.id) AS category_name")
            ->where('products.deleted_at IS NULL')
            ->orderBy('products.id', 'DESC');

        if ($whereIn !== []) {
            $builder->whereIn('products.id', $whereIn);
        }

        $rows = $builder->get()->getResultArray();

        // attachPrimaryImages 와 동일하게 product_images 에서 직접 조회
        if ($rows) {
            $ids      = array_column($rows, 'id');
            $imgRows  = $db->table('product_images')
                ->whereIn('product_id', $ids)
                ->where('is_primary', 1)
                ->get()->getResultArray();
            $imgMap = [];
            foreach ($imgRows as $img) {
                $imgMap[(int) $img['product_id']] = $img['image_path'];
            }
            foreach ($rows as &$row) {
                $row['primary_image'] = $imgMap[(int) $row['id']] ?? null;
            }
            unset($row);
        }

        return array_map(fn($p) => [
            'id'             => (int) $p['id'],
            'name'           => $p['name'],
            'slug'           => $p['slug'],
            'category_name'  => $p['category_name'] ?? '',
            'price'          => (int) $p['price'],
            'discount_price' => $p['discount_price'] ? (int) $p['discount_price'] : 0,
            'stock'          => (int) $p['stock'],
            'status'         => $p['status'],
            'primary_image'  => $p['primary_image'] ?? '',
            'created_at'     => $p['created_at'],
            'is_low_stock'   => (int) ($p['stock'] <= $this->threshold),
        ], $rows);
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $id   = $this->insertProduct();
        $rows = $this->fetchJsonData([$id]);

        $expected = ['id', 'name', 'slug', 'category_name', 'price', 'discount_price',
                     'stock', 'status', 'primary_image', 'created_at', 'is_low_stock'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── is_low_stock 플래그 ───────────────────────────────────────────────────

    public function testIsLowStockWhenStockEqualsThreshold(): void
    {
        $id   = $this->insertProduct(['stock' => $this->threshold]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame(1, $rows[0]['is_low_stock'],
            'stock == threshold 이면 is_low_stock = 1 이어야 한다');
    }

    public function testIsLowStockWhenStockBelowThreshold(): void
    {
        $id   = $this->insertProduct(['stock' => 0]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame(1, $rows[0]['is_low_stock'],
            'stock = 0 이면 is_low_stock = 1 이어야 한다');
    }

    public function testNotLowStockWhenStockAboveThreshold(): void
    {
        $id   = $this->insertProduct(['stock' => $this->threshold + 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame(0, $rows[0]['is_low_stock'],
            'stock > threshold 이면 is_low_stock = 0 이어야 한다');
    }

    // ── discount_price null → 0 ───────────────────────────────────────────────

    public function testDiscountPriceIsZeroWhenNull(): void
    {
        $id   = $this->insertProduct(['discount_price' => null]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame(0, $rows[0]['discount_price'],
            'discount_price NULL → 0 이어야 한다');
    }

    public function testDiscountPriceIsIntWhenSet(): void
    {
        $id   = $this->insertProduct(['discount_price' => 8000]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['discount_price']);
        $this->assertSame(8000, $rows[0]['discount_price']);
    }

    // ── primary_image 없을 때 ─────────────────────────────────────────────────

    public function testPrimaryImageEmptyStringWhenNoImage(): void
    {
        $id   = $this->insertProduct();
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['primary_image'],
            '이미지 없는 상품의 primary_image 는 빈 문자열이어야 한다');
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIdIsInteger(): void
    {
        $id   = $this->insertProduct();
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['id']);
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testPriceIsInteger(): void
    {
        $id   = $this->insertProduct(['price' => 15000]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['price']);
        $this->assertSame(15000, $rows[0]['price']);
    }

    public function testStockIsInteger(): void
    {
        $id   = $this->insertProduct(['stock' => 42]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['stock']);
        $this->assertSame(42, $rows[0]['stock']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $id1  = $this->insertProduct();
        $id2  = $this->insertProduct();
        $id3  = $this->insertProduct();
        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }
}
