<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 매입처 / 원가(cost_price) 저장·조회 테스트
 */
final class SupplierCostTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'users'     => [],
        'suppliers' => [],
        'products'  => [],
        'orders'    => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            $db->table('order_status_logs')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['suppliers'] !== []) {
            $db->table('suppliers')->whereIn('id', $this->cleanup['suppliers'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertSupplier(array $extra = []): int
    {
        $db = db_connect();
        $db->table('suppliers')->insert(array_merge([
            'name'           => '테스트매입처_' . uniqid(),
            'contact_person' => '홍길동',
            'phone'          => '010-1234-5678',
            'email'          => 'test@example.com',
            'memo'           => null,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['suppliers'][] = $id;
        return $id;
    }

    private function insertProduct(array $extra = []): int
    {
        $db = db_connect();
        $db->table('products')->insert(array_merge([
            'name'           => '테스트상품_' . uniqid(),
            'slug'           => 'test-prod-' . uniqid(),
            'price'          => 20000,
            'cost_price'     => 10000,
            'supplier_id'    => null,
            'stock'          => 5,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    // ── 매입처 CRUD ───────────────────────────────────────────────────────────

    public function testSupplier_insert_canBeRetrieved(): void
    {
        $id       = $this->insertSupplier(['name' => '(주)테스트공급']);
        $supplier = db_connect()->table('suppliers')->where('id', $id)->get()->getRowArray();

        $this->assertNotNull($supplier);
        $this->assertSame('(주)테스트공급', $supplier['name']);
    }

    public function testSupplier_update_savesChanges(): void
    {
        $db = db_connect();
        $id = $this->insertSupplier();
        $db->table('suppliers')->where('id', $id)->update([
            'name'       => '변경된매입처',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $db->table('suppliers')->where('id', $id)->get()->getRowArray();
        $this->assertSame('변경된매입처', $row['name']);
    }

    public function testSupplier_delete_removesRecord(): void
    {
        $db = db_connect();
        $id = $this->insertSupplier();
        $db->table('suppliers')->where('id', $id)->delete();

        $row = $db->table('suppliers')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row);

        // cleanup 에서 이미 삭제됐으므로 목록에서 제거
        $this->cleanup['suppliers'] = array_filter($this->cleanup['suppliers'], fn ($v) => $v !== $id);
    }

    // ── 상품 cost_price / supplier_id ────────────────────────────────────────

    public function testProduct_costPrice_savedAndRetrieved(): void
    {
        $id      = $this->insertProduct(['cost_price' => 8500]);
        $product = (new ProductModel())->find($id);

        $this->assertNotNull($product);
        $this->assertEquals(8500, (float) $product['cost_price']);
    }

    public function testProduct_supplierId_linkedToSupplier(): void
    {
        $supplierId = $this->insertSupplier();
        $productId  = $this->insertProduct(['supplier_id' => $supplierId]);

        $product = (new ProductModel())->find($productId);
        $this->assertSame($supplierId, (int) $product['supplier_id']);
    }

    public function testProduct_supplierIdNull_allowed(): void
    {
        $id      = $this->insertProduct(['supplier_id' => null]);
        $product = (new ProductModel())->find($id);

        $this->assertNull($product['supplier_id']);
    }

    // ── order_items cost_price 컬럼 존재 확인 ─────────────────────────────────

    public function testOrderItems_hasCostPriceColumn(): void
    {
        $db      = db_connect();
        $columns = $db->getFieldNames('order_items');

        $this->assertContains('cost_price', $columns);
    }

    // ── createPending cost_price 스냅샷 ──────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'         => 'cost_' . uniqid() . '@test.local',
            'username'      => 'cost_' . uniqid(),
            'password'      => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'      => '원가테스터',
            'role'          => 'member',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function shippingData(): array
    {
        return [
            'receiver_name'  => '홍길동',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트로 1',
            'address2'       => '',
            'delivery_memo'  => null,
        ];
    }

    public function testCreatePending_snapshotsCostPrice(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(['cost_price' => 7500, 'price' => 20000]);
        $db        = db_connect();

        $cartItems = [[
            'product_id' => $productId,
            'name'       => '원가테스트상품',
            'price'      => 20000,
            'qty'        => 2,
        ]];

        $orderId = (new OrderModel())->createPending($userId, $this->shippingData(), $cartItems);
        $this->cleanup['orders'][] = $orderId;

        $item = $db->table('order_items')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame(7500.0, (float) $item['cost_price']);
    }

    public function testCreatePending_costPriceDefaultsToZeroWhenMissing(): void
    {
        $userId    = $this->insertUser();
        // cost_price = 0 (기본값)
        $productId = $this->insertProduct(['cost_price' => 0, 'price' => 15000]);
        $db        = db_connect();

        $cartItems = [[
            'product_id' => $productId,
            'name'       => '원가미입력상품',
            'price'      => 15000,
            'qty'        => 1,
        ]];

        $orderId = (new OrderModel())->createPending($userId, $this->shippingData(), $cartItems);
        $this->cleanup['orders'][] = $orderId;

        $item = $db->table('order_items')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame(0.0, (float) $item['cost_price']);
    }
}
