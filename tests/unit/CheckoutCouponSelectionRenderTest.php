<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문서(/order/checkout)의 쿠폰 영역 렌더링.
 *
 * 쿠폰은 보유한 것 중에서 고르는 방식이어야 한다(이슈 #219). 코드 입력란이
 * 다시 살아나면 발급받지 않은 쿠폰을 코드만으로 쓰려는 요청이 되살아나므로,
 * 화면에서 그 입력 경로가 사라졌다는 것을 여기서 고정한다.
 */
final class CheckoutCouponSelectionRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'cart_items'   => [],
        'products'     => [],
        'user_coupons' => [],
        'coupons'      => [],
        'users'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'CKCS' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        foreach (['cart_items', 'user_coupons', 'coupons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $this->prefix,
            'email'         => $this->prefix . '@example.test',
            'password'      => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'      => $this->prefix,
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

    private function insertProduct(): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => $this->prefix . 'PRODUCT',
            'slug'           => strtolower($this->prefix) . '-product',
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
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    private function insertCartItem(int $userId, int $productId): void
    {
        $db = db_connect();
        $db->table('cart_items')->insert([
            'user_id'    => $userId,
            'product_id' => $productId,
            'qty'        => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['cart_items'][] = (int) $db->insertID();
    }

    private function insertCoupon(string $name): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'code'                => 'CKCS-' . strtoupper(uniqid()),
            'name'                => $name,
            'type'                => 'fixed',
            'target_grade'        => null,
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'starts_at'           => null,
            'expires_at'          => null,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id                         = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => null,
            'source'     => 'admin',
            'status'     => 'issued',
            'issued_at'  => date('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                              = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;

        return $id;
    }

    private function renderCheckout(int $userId): string
    {
        session()->set(['user_id' => $userId, 'user_role' => 'member']);
        session()->set(CartModel::CHECKOUT_SESSION_KEY, []);

        $controller = new OrderController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $html = $controller->index();
        $this->assertIsString($html, '주문서가 HTML 대신 리다이렉트를 반환했다 — 테스트 전제가 깨졌다');

        return $html;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    /** 보유 쿠폰은 선택 목록으로 뜨고, 코드 입력란은 없어야 한다 */
    public function testCheckoutRendersOwnedCouponSelectWithoutCodeInput(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $this->insertCartItem($userId, $product);
        $couponId = $this->insertCoupon($this->prefix . '보유쿠폰');
        $this->insertUserCoupon($userId, $couponId);

        $html = $this->renderCheckout($userId);

        $this->assertStringContainsString('id="couponSelect"', $html, '보유 쿠폰 선택 목록이 없다');
        $this->assertStringContainsString($this->prefix . '보유쿠폰', $html, '보유 쿠폰이 목록에 뜨지 않는다');
        $this->assertStringNotContainsString('couponCodeInput', $html, '쿠폰 코드 입력란이 되살아났다');
        $this->assertStringNotContainsString('coupon/check', $html, '쿠폰 코드 검증 요청이 남아 있다');
        $this->assertStringNotContainsString('name="coupon_code"', $html, 'coupon_code 필드가 남아 있다');
    }

    /** 쓸 수 있는 쿠폰이 하나도 없으면 안내 문구만 보여준다 */
    public function testCheckoutWithoutCouponsShowsEmptyNotice(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $this->insertCartItem($userId, $product);

        $html = $this->renderCheckout($userId);

        $this->assertStringNotContainsString('id="couponSelect"', $html, '보유 쿠폰이 없는데 선택 목록이 떴다');
        $this->assertStringContainsString('사용할 수 있는 쿠폰이 없습니다', $html, '빈 상태 안내가 없다');
    }
}
