<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\OrderController;
use App\Models\CartModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문서(OrderController::create)에서 "배송지 저장" 체크박스로 저장되는 첫 주소가
 * 기본 배송지(is_default)로 지정되지 않는 회귀 테스트.
 *
 * MyPageController::addressStore() 는 사용자의 첫 주소를 저장할 때 setDefault() 를
 * 호출해 기본 배송지로 승격시키지만, OrderController::create() 는 saveAddress() 만
 * 호출하고 승격 로직이 없다. 그 결과 주문서에서 처음 배송지를 저장한 회원은
 * 다음 주문서 진입 시 ShippingAddressModel::getDefault() 가 null 을 돌려줘
 * 이름·연락처·우편번호·주소 입력란이 비어 보인다.
 */
final class OrderCreateFirstSavedAddressDefaultTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['shipping_addresses' => [], 'payments' => [], 'order_items' => [], 'orders' => [], 'cart_items' => [], 'products' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'OCFA' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['shipping_addresses', 'payments', 'order_items', 'orders', 'cart_items', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['shipping_addresses' => [], 'payments' => [], 'order_items' => [], 'orders' => [], 'cart_items' => [], 'products' => [], 'users' => []];

        service('request')->setGlobal('post', []);
        service('request')->setGlobal('request', []);
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

    /** @return array<string, mixed> */
    private function callCreate(): array
    {
        $post = [
            'receiver_name'  => '홍길동',
            'receiver_phone' => '010-1234-5678',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트로 ' . substr(uniqid(), -4),
            'address2'       => '',
            'save_address'   => '1',
            'pg_provider'    => 'bank_transfer',
        ];

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
            $orderId                     = (int) $body['orderId'];
            $this->cleanup['orders'][]   = $orderId;
            $db                          = db_connect();
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

    public function testFirstSavedAddressBecomesDefault(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $this->insertCartItem($userId, $product);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);
        session()->set(CartModel::CHECKOUT_SESSION_KEY, []);

        $result = $this->callCreate();
        $this->assertTrue($result['success'] ?? false, '주문 생성이 실패했다: ' . json_encode($result));

        $address = db_connect()->table('shipping_addresses')->where('user_id', $userId)->get()->getRowArray();
        $this->assertNotNull($address, '배송지가 저장되지 않았다');
        $this->cleanup['shipping_addresses'][] = (int) $address['id'];

        $this->assertSame(
            1,
            (int) $address['is_default'],
            '주문서에서 처음 저장한 배송지는 기본 배송지로 지정돼야 한다',
        );
    }
}
