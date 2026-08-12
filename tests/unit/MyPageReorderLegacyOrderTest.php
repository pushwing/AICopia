<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MyPageController::reorder() 의 레거시 주문 처리 회귀 테스트
 *
 * 목록에서 감춘 레거시 pending 주문도 id 만 알면 재주문 POST 가 성공해
 * 장바구니에 담겼다. pending 은 어느 목록 탭에도 노출되지 않으므로 막는다.
 * 반면 expired 는 "취소/환불" 탭에 노출되고 재주문 버튼도 함께 붙으므로
 * 계속 허용해야 한다. (이슈 #214)
 */
final class MyPageReorderLegacyOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'cart_items'  => [],
        'order_items' => [],
        'orders'      => [],
        'products'    => [],
        'users'       => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
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

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'mrl_' . $uid,
            'email'      => 'mrl-' . $uid . '@test.com',
            'password'   => password_hash('test', PASSWORD_DEFAULT),
            'nickname'   => 'MRLTestUser',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertProduct(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'           => 'MRL상품_' . $uid,
            'slug'           => 'mrl-prod-' . $uid,
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

    /** 특정 status 의 주문 1건 + 상품 라인 1건을 직접 INSERT */
    private function insertOrder(int $userId, int $productId, string $status): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'MRL-' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 15000,
            'shipping_fee'           => 0,
            'total_amount'           => 15000,
            'payable_amount'         => 15000,
            'coupon_id'              => null,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트로 1',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $orderId                   = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => 'MRL상품',
            'product_price' => 15000,
            'qty'           => 1,
            'subtotal'      => 15000,
            'created_at'    => $now,
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();

        return $orderId;
    }

    /** @return array<string, mixed> reorder() 의 JSON 응답 */
    private function reorder(int $userId, int $orderId): array
    {
        // 이 CI4 버전은 $_POST 를 Superglobals 서비스가 생성 시점에 스냅샷하므로
        // setGlobal() 로 넣어야 getPost() 에 반영된다(AdminProductAddonSaveTest 와 동일).
        $request = service('request');
        $request->setGlobal('post', ['order_id' => (string) $orderId]);
        $request->setGlobal('request', ['order_id' => (string) $orderId]);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $controller = new MyPageController();
        $controller->initController($request, service('response'), service('logger'));

        $body = (string) $controller->reorder()->getBody();

        $this->cleanup['cart_items'] = array_merge(
            $this->cleanup['cart_items'],
            array_map('intval', array_column(
                db_connect()->table('cart_items')->where('user_id', $userId)->get()->getResultArray(),
                'id'
            ))
        );

        return json_decode($body, true) ?? [];
    }

    private function cartCount(int $userId): int
    {
        return db_connect()->table('cart_items')->where('user_id', $userId)->countAllResults();
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testReorderRejectsLegacyPendingOrder(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, $productId, 'pending');

        $response = $this->reorder($userId, $orderId);

        $this->assertFalse($response['success'] ?? null, '레거시 pending 주문으로 재주문이 성공했다');
        $this->assertSame(0, $this->cartCount($userId), '레거시 pending 주문 상품이 장바구니에 담겼다');
    }

    public function testReorderAllowsExpiredOrder(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, $productId, 'expired');

        $response = $this->reorder($userId, $orderId);

        $this->assertTrue($response['success'] ?? null, '취소/환불 탭에 노출되는 만료 주문의 재주문까지 막혔다');
        $this->assertSame(1, $this->cartCount($userId), '만료 주문 상품이 장바구니에 담기지 않았다');
    }
}
