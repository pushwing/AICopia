<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\CartController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 장바구니 화면 — 애드온이 본품 바로 아래 들여쓰기 + '추가구성' 배지로 묶여 보이는지 검증한다.
 *
 * app/Views/shop/orders/detail.php(주문 상세)에서 이미 리뷰를 통과한
 * AddonGrouping::order() + 'ps-4 border-start border-3' + 배지 규칙을 장바구니에도
 * 그대로 적용했는지 확인한다. CartController::index() 를 실제로 호출해 렌더된 HTML을
 * 그대로 검증한다(로직 재구현 없음).
 */
final class CartAddonGroupingViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['cart_items' => [], 'products' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'CAGV' . substr(uniqid(), -6);
        session()->destroy();
        session()->remove(['user_id', 'user_role', 'cart', 'cart_addon_of']);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['cart_items', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['cart_items' => [], 'products' => [], 'users' => []];
        session()->destroy();
        session()->remove(['user_id', 'user_role', 'cart', 'cart_addon_of']);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix,
            'email'      => $this->prefix . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertProduct(string $suffix): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'       => $this->prefix . $suffix,
            'slug'       => strtolower($this->prefix . $suffix),
            'price'      => 10000,
            'stock'      => 10,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    private function insertCartItem(int $userId, int $productId, ?int $parentProductId, int $qty): void
    {
        $db = db_connect();
        $db->table('cart_items')->insert([
            'user_id'           => $userId,
            'product_id'        => $productId,
            'sku_id'            => null,
            'parent_product_id' => $parentProductId,
            'qty'               => $qty,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['cart_items'][] = (int) $db->insertID();
    }

    private function renderCart(int $userId): string
    {
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $controller = new CartController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->index();
    }

    private function renderGuestCart(): string
    {
        $controller = new CartController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->index();
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testAddonRendersIndentedAndBadgedAfterMainProduct(): void
    {
        $userId = $this->insertUser();
        $main   = $this->insertProduct('MAIN');
        $addon  = $this->insertProduct('ADDON');

        // 애드온을 먼저 담아 DB 정렬(id DESC)로는 애드온이 먼저 나오게 해도
        // 화면에는 AddonGrouping::order() 가 본품을 먼저로 재배열해야 한다.
        $this->insertCartItem($userId, $addon, $main, 2);
        $this->insertCartItem($userId, $main, null, 1);

        $html = $this->renderCart($userId);

        $this->assertStringContainsString('추가구성', $html, '애드온 행에 배지가 표시돼야 한다');
        $this->assertStringContainsString('ps-4 border-start border-3', $html, '애드온 행에 들여쓰기 클래스가 붙어야 한다');

        $mainPos  = strpos($html, $this->prefix . 'MAIN');
        $addonPos = strpos($html, $this->prefix . 'ADDON');
        $this->assertNotFalse($mainPos, '본품명이 렌더돼야 한다');
        $this->assertNotFalse($addonPos, '애드온명이 렌더돼야 한다');
        $this->assertLessThan($addonPos, $mainPos, '본품이 애드온보다 먼저(위에) 렌더돼야 한다');
    }

    public function testPlainCartHasNoAddonBadgeOrIndentClass(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct('SOLO');
        $this->insertCartItem($userId, $product, null, 1);

        $html = $this->renderCart($userId);

        $this->assertStringContainsString($this->prefix . 'SOLO', $html);
        $this->assertStringNotContainsString('추가구성', $html, '애드온이 없으면 배지가 없어야 한다');
        $this->assertStringNotContainsString('ps-4 border-start border-3', $html, '애드온이 없으면 들여쓰기 클래스가 없어야 한다');
    }

    public function testGuestCanViewSessionCartAndIsDirectedToLoginForCheckout(): void
    {
        $product = $this->insertProduct('GUEST');
        session()->set('cart', [\App\Models\CartModel::sessionKey($product) => 2]);

        $html = $this->renderGuestCart();

        $this->assertStringContainsString($this->prefix . 'GUEST', $html, '비회원도 세션 장바구니 상품을 확인할 수 있어야 한다');
        $this->assertStringContainsString('비회원 장바구니입니다.', $html);
        $this->assertStringContainsString('로그인 후 주문하기', $html);
        $this->assertStringNotContainsString('action="/cart/delete"', $html, '비회원에게 DB 장바구니 삭제 UI를 노출하면 안 된다');
    }

    public function testGuestCheckoutRedirectsToLoginThenOrder(): void
    {
        $controller = new CartController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $response = $controller->checkout();

        $this->assertStringContainsString('/auth/login', $response->getHeaderLine('Location'));
        $this->assertSame(site_url('order'), session()->getFlashdata('redirect_url'));
    }
}
