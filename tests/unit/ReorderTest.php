<?php

namespace Tests\Unit;

use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 간편 재주문 — CartModel::upsert() + getCount() 검증
 */
final class ReorderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private CartModel $cart;

    private array $cleanup = [
        'cart_items' => [],
        'products'   => [],
        'users'      => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cart = new CartModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
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
            'username'      => 'ro_' . $uid,
            'email'         => 'ro-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'ROTestUser',
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

    private function insertProduct(): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'RO상품_' . uniqid(),
            'slug'           => 'ro-prod-' . uniqid(),
            'price'          => 15000,
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

    private function cartItemIds(int $userId): array
    {
        return array_map('intval', array_column(
            db_connect()->table('cart_items')->where('user_id', $userId)->get()->getResultArray(),
            'id'
        ));
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_upsert_adds_item_to_cart(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $this->cart->upsert($userId, $productId, 2);

        $ids = $this->cartItemIds($userId);
        $this->cleanup['cart_items'] = array_merge($this->cleanup['cart_items'], $ids);

        $this->assertSame(1, $this->cart->getCount($userId));
    }

    public function test_upsert_accumulates_qty_for_same_product(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $this->cart->upsert($userId, $productId, 2);
        $this->cart->upsert($userId, $productId, 3);

        $ids = $this->cartItemIds($userId);
        $this->cleanup['cart_items'] = array_merge($this->cleanup['cart_items'], $ids);

        $row = db_connect()->table('cart_items')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->get()->getRowArray();

        $this->assertSame(5, (int) $row['qty']);
    }

    public function test_reorder_multiple_products_all_added_to_cart(): void
    {
        $userId = $this->insertUser();
        $p1     = $this->insertProduct();
        $p2     = $this->insertProduct();
        $p3     = $this->insertProduct();

        // 재주문 시뮬레이션: 이전 주문의 3가지 상품 담기
        $this->cart->upsert($userId, $p1, 1);
        $this->cart->upsert($userId, $p2, 2);
        $this->cart->upsert($userId, $p3, 1);

        $ids = $this->cartItemIds($userId);
        $this->cleanup['cart_items'] = array_merge($this->cleanup['cart_items'], $ids);

        $this->assertSame(3, $this->cart->getCount($userId));
    }

    public function test_reorder_on_non_empty_cart_accumulates(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        // 기존 장바구니에 1개
        $this->cart->upsert($userId, $productId, 1);
        // 재주문으로 2개 추가
        $this->cart->upsert($userId, $productId, 2);

        $ids = $this->cartItemIds($userId);
        $this->cleanup['cart_items'] = array_merge($this->cleanup['cart_items'], $ids);

        $row = db_connect()->table('cart_items')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->get()->getRowArray();

        $this->assertSame(3, (int) $row['qty']);
        $this->assertSame(1, $this->cart->getCount($userId));
    }

    public function test_cart_count_returns_distinct_product_count(): void
    {
        $userId = $this->insertUser();
        $p1     = $this->insertProduct();
        $p2     = $this->insertProduct();

        $this->cart->upsert($userId, $p1, 5);
        $this->cart->upsert($userId, $p2, 3);

        $ids = $this->cartItemIds($userId);
        $this->cleanup['cart_items'] = array_merge($this->cleanup['cart_items'], $ids);

        $this->assertSame(2, $this->cart->getCount($userId));
    }
}
