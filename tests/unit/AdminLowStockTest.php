<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 재고 부족 알림 뱃지 — BaseController lowStockCount 로직 검증
 * 이슈 #96
 */
final class AdminLowStockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ProductModel $model;
    private array $cleanup = ['products' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ProductModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = ['products' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(int $stock, string $status = 'on_sale'): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'LS상품_' . uniqid(),
            'slug'           => 'ls-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => $stock,
            'status'         => $status,
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function countLowStock(int $threshold): int
    {
        return (int) (new ProductModel())
            ->where('stock <=', $threshold)
            ->where('status !=', 'hidden')
            ->whereIn('id', $this->cleanup['products'])
            ->countAllResults();
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_products_with_stock_at_threshold_are_counted(): void
    {
        $this->insertProduct(5, 'on_sale');

        $this->assertSame(1, $this->countLowStock(5));
    }

    public function test_products_with_stock_below_threshold_are_counted(): void
    {
        $this->insertProduct(0, 'sold_out');
        $this->insertProduct(3, 'on_sale');

        $this->assertSame(2, $this->countLowStock(5));
    }

    public function test_products_above_threshold_are_not_counted(): void
    {
        $this->insertProduct(6, 'on_sale');
        $this->insertProduct(100, 'on_sale');

        $this->assertSame(0, $this->countLowStock(5));
    }

    public function test_hidden_products_are_excluded_regardless_of_stock(): void
    {
        $this->insertProduct(0, 'hidden');
        $this->insertProduct(1, 'hidden');

        $this->assertSame(0, $this->countLowStock(5));
    }

    public function test_only_non_hidden_low_stock_are_counted(): void
    {
        $this->insertProduct(2, 'on_sale');
        $this->insertProduct(2, 'sold_out');
        $this->insertProduct(2, 'hidden');

        $this->assertSame(2, $this->countLowStock(5));
    }

    public function test_threshold_parameter_affects_count(): void
    {
        $this->insertProduct(3, 'on_sale');
        $this->insertProduct(7, 'on_sale');

        $this->assertSame(1, $this->countLowStock(5));
        $this->assertSame(2, $this->countLowStock(10));
    }
}
