<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\OrderController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 관리자 주문 상세의 카드 영수증 팝업 렌더 테스트.
 *
 * 회원 주문 상세와 같은 PaymentReceipt 를 써서 카드사·승인번호까지 보여준다.
 */
final class AdminOrderReceiptTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['payments' => [], 'order_items' => [], 'orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'ADMRC' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['payments', 'order_items', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['payments' => [], 'order_items' => [], 'orders' => [], 'users' => []];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

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
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'ORD-' . $this->prefix,
            'status'                 => 'paid',
            'total_product_price'    => 10000,
            'shipping_fee'           => 3000,
            'total_amount'           => 13000,
            'payable_amount'         => 13000,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'coupon_id'              => null,
            'coupon_discount_amount' => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트로 1',
            'address2'               => '',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id                        = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    private function insertOrderItem(int $orderId): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => 0,
            'product_name'  => '관리자영수증테스트상품',
            'product_price' => 10000,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
    }

    /** 나이스페이 카드 결제 원응답을 담은 payments 행 */
    private function insertCardPayment(int $orderId): void
    {
        $db = db_connect();
        $db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => 'nicepay',
            'pg_tid'       => 'TID-' . $this->prefix,
            'method'       => 'card',
            'amount'       => 13000,
            'status'       => 'paid',
            'raw_response' => json_encode([
                'resultCode' => '0000',
                'approveNo'  => '77665544',
                'card'       => [
                    'cardName'  => '삼성카드',
                    'cardNum'   => '123456******1234',
                    'cardQuota' => 3,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'paid_at'    => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['payments'][] = (int) $db->insertID();
    }

    private function controller(): OrderController
    {
        $controller = new OrderController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testAdminOrderDetailShowsCardReceiptPopup(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId);
        $this->insertOrderItem($orderId);
        $this->insertCardPayment($orderId);

        session()->set(['user_id' => $userId, 'user_role' => 'admin']);

        $result = $this->controller()->detail($orderId);

        $this->assertIsString($result, '관리자 주문 상세가 HTML 대신 리다이렉트를 반환했다');
        $this->assertStringContainsString('receiptModal', $result, '영수증 팝업(모달)이 없다');
        $this->assertStringContainsString('삼성카드', $result, '카드사가 표시되지 않았다');
        $this->assertStringContainsString('123456******1234', $result, '카드번호가 표시되지 않았다');
        $this->assertStringContainsString('3개월 할부', $result, '할부 정보가 표시되지 않았다');
        $this->assertStringContainsString('77665544', $result, '승인번호가 표시되지 않았다');
    }

    public function testAdminOrderDetailHasNoReceiptPopupWithoutPayment(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId);
        $this->insertOrderItem($orderId);

        session()->set(['user_id' => $userId, 'user_role' => 'admin']);

        $result = $this->controller()->detail($orderId);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('receiptModal', $result, '결제 정보가 없는데 영수증 팝업이 떴다');
    }
}
