<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MyPageController::orderDetail() 회귀 테스트
 *
 * MySQLi 드라이버는 조회 결과를 모두 문자열로 돌려준다. 그 값을 int 파라미터로
 * 선언된 OrderModel::getWithItems() 에 그대로 넘기면 strict_types 아래에서
 * TypeError 가 나 주문 상세 페이지 전체가 500 이 된다.
 */
final class MyPageOrderDetailTest extends CIUnitTestCase
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
        $this->prefix = 'MPOD' . substr(uniqid(), -6);
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
        $id                        = (int) $db->insertID();
        $this->cleanup['users'][]  = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $orderNumber): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
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
            'product_name'  => '주문상세테스트상품',
            'product_price' => 10000,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
    }

    /** 토스페이먼츠 카드 결제 원응답을 담은 payments 행 */
    private function insertCardPayment(int $orderId): void
    {
        $db = db_connect();
        $db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => 'toss',
            'pg_tid'       => 'TID-' . $this->prefix,
            'method'       => 'card',
            'amount'       => 13000,
            'status'       => 'paid',
            'raw_response' => json_encode([
                'card' => [
                    'issuerCode'            => '41',
                    'number'                => '433012******1234',
                    'installmentPlanMonths' => 0,
                    'approveNo'             => '00012345',
                ],
                'receipt' => ['url' => 'https://dashboard.tosspayments.com/receipt/x'],
            ], JSON_UNESCAPED_UNICODE),
            'paid_at'    => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['payments'][] = (int) $db->insertID();
    }

    private function controller(): MyPageController
    {
        $controller = new MyPageController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testOrderDetailRendersWithoutTypeError(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertOrderItem($orderId);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->orderDetail($orderNumber);

        $this->assertIsString($result, '주문 상세가 HTML 대신 리다이렉트를 반환했다');
        $this->assertStringContainsString($orderNumber, $result, '렌더된 화면에 주문번호가 없다');
        $this->assertStringContainsString('주문상세테스트상품', $result, '렌더된 화면에 주문 상품이 없다');
    }

    public function testOrderDetailShowsCardReceiptPopup(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertOrderItem($orderId);
        $this->insertCardPayment($orderId);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->orderDetail($orderNumber);

        $this->assertIsString($result);
        $this->assertStringContainsString('receiptModal', $result, '영수증 팝업(모달)이 없다');
        $this->assertStringContainsString('영수증', $result, '영수증 버튼이 없다');
        $this->assertStringContainsString('신한카드', $result, '카드사가 표시되지 않았다');
        $this->assertStringContainsString('433012******1234', $result, '카드번호가 표시되지 않았다');
        $this->assertStringContainsString('일시불', $result, '할부 정보가 표시되지 않았다');
        $this->assertStringContainsString('00012345', $result, '승인번호가 표시되지 않았다');
        $this->assertStringContainsString('https://dashboard.tosspayments.com/receipt/x', $result, 'PG 영수증 원본 링크가 없다');
    }

    public function testOrderDetailHasNoReceiptPopupWithoutPayment(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertOrderItem($orderId);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->orderDetail($orderNumber);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('receiptModal', $result, '결제 정보가 없는데 영수증 팝업이 떴다');
    }

    public function testOrderDetailRedirectsWhenOrderBelongsToAnotherUser(): void
    {
        $ownerId     = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($ownerId, $orderNumber);
        $this->insertOrderItem($orderId);

        session()->set(['user_id' => $ownerId + 999_999, 'user_role' => 'member']);

        $result = $this->controller()->orderDetail($orderNumber);

        $this->assertIsNotString($result, '남의 주문 상세가 그대로 렌더됐다');
    }
}
