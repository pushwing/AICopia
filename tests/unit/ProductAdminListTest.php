<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductModel::getAdminList() — stock=low 필터 및 일반 필터 검증
 * 이슈 #62
 */
final class ProductAdminListTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ProductModel $model;

    /**
     * 테스트 인스턴스마다 고유한 prefix를 사용해 다른 테스트/이전 실행과 완전히 격리한다.
     * getAdminList는 perPage=20으로 페이징하므로 공용 prefix를 쓰면 이전 미정리 데이터에 묻힐 수 있다.
     */
    private string $prefix;

    private array $cleanup = [
        'products' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model  = new ProductModel();
        $this->prefix = 'PALTEST_' . uniqid() . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup['products'] = [];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => $this->prefix . uniqid(),
            'slug'           => 'pal-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return array_merge(['id' => $id], $data);
    }

    private function query(array $extra = []): array
    {
        return $this->model->getAdminList(array_merge(['keyword' => $this->prefix], $extra));
    }

    // ── low_stock 필터 ────────────────────────────────────────────────────────

    public function testLowStockFilterReturnsOnlyBelowThreshold(): void
    {
        $low   = $this->insertProduct(['stock' => 3]);
        $exact = $this->insertProduct(['stock' => 5]);
        $above = $this->insertProduct(['stock' => 6]);

        $ids = array_map('intval', array_column($this->query(['stock' => 'low', 'low_stock_threshold' => 5])['items'], 'id'));

        $this->assertContains($low['id'],      $ids, '재고 3 (< 임계값 5) 상품이 포함되어야 한다');
        $this->assertContains($exact['id'],    $ids, '재고 5 (= 임계값 5) 상품이 포함되어야 한다');
        $this->assertNotContains($above['id'], $ids, '재고 6 (> 임계값 5) 상품이 포함되면 안 된다');
    }

    public function testLowStockFilterIncludesZeroStock(): void
    {
        $zero = $this->insertProduct(['stock' => 0]);

        $ids = array_map('intval', array_column($this->query(['stock' => 'low', 'low_stock_threshold' => 5])['items'], 'id'));

        $this->assertContains($zero['id'], $ids, '품절(재고 0) 상품이 재고 부족 목록에 포함되어야 한다');
    }

    public function testNoStockFilterReturnsAllProducts(): void
    {
        $low  = $this->insertProduct(['stock' => 2]);
        $high = $this->insertProduct(['stock' => 100]);

        $ids = array_map('intval', array_column($this->query()['items'], 'id'));

        $this->assertContains($low['id'],  $ids);
        $this->assertContains($high['id'], $ids);
    }

    public function testLowStockThresholdZeroReturnsOnlyZeroStock(): void
    {
        $zero    = $this->insertProduct(['stock' => 0]);
        $nonZero = $this->insertProduct(['stock' => 1]);

        $ids = array_map('intval', array_column($this->query(['stock' => 'low', 'low_stock_threshold' => 0])['items'], 'id'));

        $this->assertContains($zero['id'],       $ids, '재고 0만 포함되어야 한다');
        $this->assertNotContains($nonZero['id'], $ids, '재고 1은 포함되면 안 된다');
    }

    public function testLowStockResultsOrderedByStockAscending(): void
    {
        $this->insertProduct(['stock' => 3]);
        $this->insertProduct(['stock' => 1]);
        $this->insertProduct(['stock' => 5]);

        $items = $this->query(['stock' => 'low', 'low_stock_threshold' => 5])['items'];

        for ($i = 0; $i < count($items) - 1; $i++) {
            $this->assertLessThanOrEqual(
                (int) $items[$i + 1]['stock'],
                (int) $items[$i]['stock'],
                '재고 오름차순 정렬이어야 한다'
            );
        }
    }

    // ── 키워드 필터 (기존 동작 회귀) ─────────────────────────────────────────

    public function testKeywordFilterWorksCombinedWithLowStock(): void
    {
        $target = $this->insertProduct(['name' => $this->prefix . 'SPECIAL_' . uniqid(), 'stock' => 2]);
        $other  = $this->insertProduct(['stock' => 2]);

        $ids = array_map('intval', array_column(
            $this->model->getAdminList([
                'keyword'             => $this->prefix . 'SPECIAL_',
                'stock'               => 'low',
                'low_stock_threshold' => 5,
            ])['items'],
            'id'
        ));

        $this->assertContains($target['id'],   $ids);
        $this->assertNotContains($other['id'], $ids);
    }
}
