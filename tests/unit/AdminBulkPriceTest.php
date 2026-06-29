<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 일괄 가격 조정 — ProductController::bulk() price_discount 로직 검증
 * 이슈 #99
 */
final class AdminBulkPriceTest extends CIUnitTestCase
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

    private function insertProduct(int $price, ?int $discountPrice = null): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'BP상품_' . uniqid(),
            'slug'           => 'bp-prod-' . uniqid(),
            'price'          => $price,
            'discount_price' => $discountPrice,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
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

    private function applyBulkPrice(array $ids, string $discountType, int $discountValue = 0): void
    {
        $db = db_connect();

        if ($discountType === 'clear') {
            $db->table('products')
               ->whereIn('id', $ids)
               ->update(['discount_price' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            return;
        }

        foreach ($ids as $id) {
            $product = $this->model->find($id);
            if (! $product) continue;
            $price = (int) $product['price'];
            $discountPrice = $discountType === 'percent'
                ? (int) round($price * (1 - $discountValue / 100))
                : max(0, $price - $discountValue);
            $this->model->update($id, ['discount_price' => $discountPrice, 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_percent_discount_sets_correct_discount_price(): void
    {
        $id = $this->insertProduct(10000);

        $this->applyBulkPrice([$id], 'percent', 20);

        $product = $this->model->find($id);
        $this->assertSame(8000, (int) $product['discount_price']);
    }

    public function test_percent_discount_rounds_correctly(): void
    {
        $id = $this->insertProduct(9999);

        $this->applyBulkPrice([$id], 'percent', 10);

        $product = $this->model->find($id);
        $this->assertSame((int) round(9999 * 0.9), (int) $product['discount_price']);
    }

    public function test_fixed_discount_subtracts_amount(): void
    {
        $id = $this->insertProduct(20000);

        $this->applyBulkPrice([$id], 'fixed', 5000);

        $product = $this->model->find($id);
        $this->assertSame(15000, (int) $product['discount_price']);
    }

    public function test_fixed_discount_does_not_go_below_zero(): void
    {
        $id = $this->insertProduct(3000);

        $this->applyBulkPrice([$id], 'fixed', 5000);

        $product = $this->model->find($id);
        $this->assertSame(0, (int) $product['discount_price']);
    }

    public function test_clear_removes_discount_price(): void
    {
        $id = $this->insertProduct(10000, 8000);

        $this->applyBulkPrice([$id], 'clear');

        $product = $this->model->find($id);
        $this->assertNull($product['discount_price']);
    }

    public function test_bulk_applies_to_multiple_products(): void
    {
        $p1 = $this->insertProduct(10000);
        $p2 = $this->insertProduct(20000);

        $this->applyBulkPrice([$p1, $p2], 'percent', 50);

        $this->assertSame(5000,  (int) $this->model->find($p1)['discount_price']);
        $this->assertSame(10000, (int) $this->model->find($p2)['discount_price']);
    }

    public function test_clear_does_not_affect_other_products(): void
    {
        $p1 = $this->insertProduct(10000, 8000);
        $p2 = $this->insertProduct(10000, 7000);

        $this->applyBulkPrice([$p1], 'clear');

        $this->assertNull($this->model->find($p1)['discount_price']);
        $this->assertSame(7000, (int) $this->model->find($p2)['discount_price']);
    }
}
