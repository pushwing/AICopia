<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장바구니에서 선택한 항목만 주문서로 넘기기 (이슈 #101)
 * - getByUser()에 선택 cart_item id를 넘기면 그 항목만 반환한다.
 * - removeByIds()는 주문에 포함된 항목만 정확히 삭제한다.
 */
final class CartSelectedCheckoutTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private CartModel $model;

    /** @var array<string, list<int>> */
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
            'username'      => 'cs_' . $uid,
            'email'         => 'cs-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'CSUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertProduct(int $stock = 10): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'CS상품_' . uniqid(),
            'slug'           => 'cs-prod-' . uniqid(),
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
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    /** 장바구니에 담고 생성된 cart_items.id 를 돌려준다. */
    private function addToCart(int $userId, int $productId, int $qty = 1): int
    {
        $this->model->upsert($userId, $productId, $qty);

        $row = db_connect()->table('cart_items')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->get()->getRowArray();

        $id                            = (int) $row['id'];
        $this->cleanup['cart_items'][] = $id;

        return $id;
    }

    // ── 선택 항목 조회 ─────────────────────────────────────────────────────────

    public function test_selected_ids_return_only_those_items(): void
    {
        $userId = $this->insertUser();
        $p1     = $this->insertProduct();
        $p2     = $this->insertProduct();
        $cart1  = $this->addToCart($userId, $p1);
        $this->addToCart($userId, $p2);

        $items = $this->model->getByUser($userId, [$cart1]);

        $this->assertCount(1, $items);
        $this->assertSame($p1, (int) $items[0]['product_id']);
    }

    public function test_no_selection_returns_entire_cart(): void
    {
        $userId = $this->insertUser();
        $this->addToCart($userId, $this->insertProduct());
        $this->addToCart($userId, $this->insertProduct());

        $this->assertCount(2, $this->model->getByUser($userId));
    }

    public function test_selected_ids_of_other_user_are_ignored(): void
    {
        $owner  = $this->insertUser();
        $other  = $this->insertUser();
        $ownersCartId = $this->addToCart($owner, $this->insertProduct());

        // 남의 장바구니 id 를 넘겨도 자기 항목만 대상이 된다.
        $this->assertSame([], $this->model->getByUser($other, [$ownersCartId]));
    }

    public function test_unknown_selected_id_returns_empty(): void
    {
        $userId = $this->insertUser();
        $this->addToCart($userId, $this->insertProduct());

        $this->assertSame([], $this->model->getByUser($userId, [99999999]));
    }

    // ── 선택 항목 삭제 ─────────────────────────────────────────────────────────

    public function test_remove_by_ids_deletes_only_selected(): void
    {
        $userId = $this->insertUser();
        $cart1  = $this->addToCart($userId, $this->insertProduct());
        $cart2  = $this->addToCart($userId, $this->insertProduct());

        $this->model->removeByIds($userId, [$cart1]);

        $remaining = $this->model->getByUser($userId);
        $this->assertCount(1, $remaining);
        $this->assertSame($cart2, (int) $remaining[0]['id']);
    }

    public function test_remove_by_ids_does_not_touch_other_user(): void
    {
        $owner        = $this->insertUser();
        $other        = $this->insertUser();
        $ownersCartId = $this->addToCart($owner, $this->insertProduct());

        $this->model->removeByIds($other, [$ownersCartId]);

        $this->assertCount(1, $this->model->getByUser($owner));
    }

    public function test_remove_by_ids_with_empty_list_does_nothing(): void
    {
        $userId = $this->insertUser();
        $this->addToCart($userId, $this->insertProduct());

        $this->model->removeByIds($userId, []);

        $this->assertCount(1, $this->model->getByUser($userId));
    }
}
