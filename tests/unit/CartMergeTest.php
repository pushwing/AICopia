<?php

namespace Tests\Unit;

use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 비로그인 세션 카트 → 로그인 후 DB 카트 병합 (mergeAndClear)
 * 이슈 ⑤ 비로그인 장바구니 로그인 후 병합
 */
final class CartMergeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private CartModel $model;
    private array $cleanup = [
        'users'      => [],
        'products'   => [],
        'cart_items' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new CartModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['cart_items']) {
            $db->table('cart_items')->whereIn('id', $this->cleanup['cart_items'])->delete();
        }
        if ($this->cleanup['products']) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['users']) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'cm_' . $uid,
            'email'         => 'cm-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'CMUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(int $stock = 10): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'CM상품_' . uniqid(),
            'slug'           => 'cm-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => $stock,
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

    private function setSessionCart(array $cart): void
    {
        session()->set('cart', $cart);
    }

    private function getDbCart(int $userId): array
    {
        $rows = db_connect()->table('cart_items')
            ->where('user_id', $userId)->get()->getResultArray();
        foreach ($rows as $r) {
            $this->cleanup['cart_items'][] = (int) $r['id'];
        }
        return $rows;
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_session_cart_merged_to_db_on_login(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);
        $sessKey   = CartModel::sessionKey($productId);

        $this->setSessionCart([$sessKey => 2]);
        $this->model->mergeAndClear($userId);

        $rows = $this->getDbCart($userId);
        $this->assertCount(1, $rows);
        $this->assertSame($productId, (int) $rows[0]['product_id']);
        $this->assertSame(2, (int) $rows[0]['qty']);
    }

    public function test_session_cart_cleared_after_merge(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);

        $this->setSessionCart([CartModel::sessionKey($productId) => 1]);
        $this->model->mergeAndClear($userId);

        $this->getDbCart($userId);
        $this->assertNull(session()->get('cart'));
    }

    public function test_session_qty_accumulates_with_existing_db_cart(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);

        // DB에 미리 1개 담겨 있음
        $this->model->upsert($userId, $productId, 1);
        $this->cleanup['cart_items'][] = (int) db_connect()->table('cart_items')
            ->where('user_id', $userId)->where('product_id', $productId)->get()->getRowArray()['id'];

        $this->setSessionCart([CartModel::sessionKey($productId) => 3]);
        $this->model->mergeAndClear($userId);

        $rows = $this->getDbCart($userId);
        // 기존 1 + 세션 3 = 4
        $this->assertSame(4, (int) $rows[0]['qty']);
    }

    public function test_qty_clipped_to_available_stock(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(2);  // 재고 2

        $this->setSessionCart([CartModel::sessionKey($productId) => 5]);
        $this->model->mergeAndClear($userId);

        $rows = $this->getDbCart($userId);
        $this->assertSame(2, (int) $rows[0]['qty']);
    }

    public function test_zero_stock_product_skipped(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(0);  // 품절

        $this->setSessionCart([CartModel::sessionKey($productId) => 2]);
        $this->model->mergeAndClear($userId);

        $rows = $this->getDbCart($userId);
        $this->assertCount(0, $rows);
    }

    public function test_empty_session_cart_does_nothing(): void
    {
        $userId = $this->insertUser();

        session()->remove('cart');
        $this->model->mergeAndClear($userId);

        $rows = $this->getDbCart($userId);
        $this->assertCount(0, $rows);
    }

    public function test_multiple_products_merged(): void
    {
        $userId = $this->insertUser();
        $p1     = $this->insertProduct(10);
        $p2     = $this->insertProduct(10);

        $this->setSessionCart([
            CartModel::sessionKey($p1) => 2,
            CartModel::sessionKey($p2) => 3,
        ]);
        $this->model->mergeAndClear($userId);

        $rows   = $this->getDbCart($userId);
        $qtyMap = array_column($rows, 'qty', 'product_id');

        $this->assertSame(2, (int) $qtyMap[$p1]);
        $this->assertSame(3, (int) $qtyMap[$p2]);
    }
}
