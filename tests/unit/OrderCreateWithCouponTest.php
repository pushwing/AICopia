<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 쿠폰을 적용해 /order/create 를 호출하는 종단(end-to-end) 회귀 테스트.
 *
 * CouponService::validate()/validateByUserCouponId() 가 MySQLi 가 문자열로
 * 돌려주는 coupon['id'] 를 그대로 넘기면, OrderController::create() 가 이를
 * OrderAttemptModel::createAttempt(?int $couponId) 에 넘기는 순간
 * declare(strict_types=1) 때문에 TypeError(500)가 난다. 모델 단위 테스트는
 * (int) $db->insertID() 로 만든 int 를 직접 넘겨 통과하므로 이 경로를 못 잡는다
 * — 컨트롤러를 실제로 호출하는 이 테스트가 그 틈을 메운다.
 */
final class OrderCreateWithCouponTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'shipping_addresses' => [],
        'payments'           => [],
        'order_items'        => [],
        'orders'             => [],
        'cart_items'         => [],
        'products'           => [],
        'user_coupons'       => [],
        'coupons'            => [],
        'users'              => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'OCWC' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (
            ['shipping_addresses', 'payments', 'order_items', 'orders', 'cart_items', 'user_coupons', 'coupons', 'products', 'users'] as $table
        ) {
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

    /** @return array{id: int, code: string} */
    private function insertCoupon(): array
    {
        $code = 'OCWC-' . strtoupper(uniqid());
        $db   = db_connect();
        $db->table('coupons')->insert([
            'code'                => $code,
            'name'                => '테스트쿠폰',
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

        return ['id' => $id, 'code' => $code];
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

    /** @param array<string, mixed> $extraPost @return array<string, mixed> */
    private function callCreate(array $extraPost): array
    {
        $post = array_merge([
            'receiver_name'  => '홍길동',
            'receiver_phone' => '010-1234-5678',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트로 ' . substr(uniqid(), -4),
            'address2'       => '',
            'save_address'   => '0',
            'pg_provider'    => 'bank_transfer',
        ], $extraPost);

        $request = service('request');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);

        $controller = new OrderController();
        $controller->initController($request, service('response'), service('logger'));
        $response = $controller->create();

        $request->setGlobal('post', []);
        $request->setGlobal('request', []);

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if (isset($body['orderId']) && (int) $body['orderId'] > 0) {
            $orderId                    = (int) $body['orderId'];
            $this->cleanup['orders'][] = $orderId;
            $db                         = db_connect();
            $this->cleanup['order_items'] = array_merge(
                $this->cleanup['order_items'],
                array_column($db->table('order_items')->select('id')->where('order_id', $orderId)->get()->getResultArray(), 'id'),
            );
            $this->cleanup['payments'] = array_merge(
                $this->cleanup['payments'],
                array_column($db->table('payments')->select('id')->where('order_id', $orderId)->get()->getResultArray(), 'id'),
            );
        }

        return $body;
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    /**
     * 쿠폰 코드(coupon_code)를 적용해 주문하면 500(TypeError)이 아니라 정상
     * 응답을 받아야 한다. 이슈: CouponService 가 MySQLi 문자열 id 를 그대로
     * 돌려주면 OrderAttemptModel::createAttempt(?int $couponId) 호출에서
     * TypeError 가 발생해 500 이 났었다.
     */
    public function testCreateOrderWithCouponCodeSucceeds(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $this->insertCartItem($userId, $product);
        $coupon = $this->insertCoupon();

        session()->set(['user_id' => $userId, 'user_role' => 'member']);
        session()->set(CartModel::CHECKOUT_SESSION_KEY, []);

        $result = $this->callCreate(['coupon_code' => $coupon['code']]);

        $this->assertTrue($result['success'] ?? false, '쿠폰 코드 적용 주문이 실패했다: ' . json_encode($result));

        $orderId = (int) $result['orderId'];
        $order   = db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame($coupon['id'], (int) $order['coupon_id']);
        $this->assertSame(3000, (int) $order['coupon_discount_amount']);
    }

    /**
     * 보유 쿠폰(user_coupon_id) 경로도 동일하게 500 없이 성공해야 한다 —
     * validateByUserCouponId() 가 재조립하는 coupon 배열도 정규화 대상이다.
     */
    public function testCreateOrderWithUserCouponIdSucceeds(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $this->insertCartItem($userId, $product);
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);
        session()->set(CartModel::CHECKOUT_SESSION_KEY, []);

        $result = $this->callCreate(['user_coupon_id' => (string) $userCouponId]);

        $this->assertTrue($result['success'] ?? false, '보유 쿠폰 적용 주문이 실패했다: ' . json_encode($result));

        $orderId = (int) $result['orderId'];
        $order   = db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame($coupon['id'], (int) $order['coupon_id']);
        $this->assertSame(3000, (int) $order['coupon_discount_amount']);
    }
}
