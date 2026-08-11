<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문 시도 → 주문 전환
 * 이슈 #214
 */
final class OrderAttemptConversionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel        $orderModel;
    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'orders'         => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'point_logs', 'payments', 'order_items'] as $table) {
                $db->table($table)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        foreach (['products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'octest_' . $uid,
            'email'         => 'oc-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OCUser',
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

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OCProd_' . uniqid(),
            'slug'           => 'oc-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /** @param array<string, mixed> $product */
    private function createAttempt(int $userId, array $product, int $qty = 1): int
    {
        $id = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
                'address2'       => null,
                'delivery_memo'  => null,
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => $qty,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            null,
            null,
            0,
            0,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    private function track(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
        }

        return $orderId;
    }

    /** C-01: 전환하면 orders + order_items 가 생기고 재고가 차감된다 */
    public function testConvertAttempt_createsOrderAndDeductsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 3);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $orderId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $order['status']);
        $this->assertSame(30000, (int) $order['total_amount']);
        $this->assertNotNull($order['paid_at']);

        $items = $db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
        $this->assertCount(1, $items);
        $this->assertSame(3, (int) $items[0]['qty']);
        $this->assertSame(30000, (int) $items[0]['subtotal']);

        $this->assertSame(7, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('converted', $attempt['status']);
        $this->assertSame($orderId, (int) $attempt['order_id']);
    }

    /** C-02: 같은 시도를 두 번 전환해도 주문은 1건만 생긴다 (결제 멱등성) */
    public function testConvertAttempt_twice_createsSingleOrder(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product);

        $first  = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-A-' . uniqid(), 'card', []));
        $second = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-B-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '두 번째 전환은 반드시 거부돼야 한다');
        $this->assertSame(1, $db->table('orders')->where('user_id', $userId)->countAllResults());
        // 재고도 한 번만 차감돼야 한다.
        $this->assertSame(9, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
    }

    /** C-03: 무통장은 awaiting_payment 로 전환되고 재고는 아직 차감하지 않는다 */
    public function testConvertAttempt_bankTransfer_keepsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 2);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'awaiting_payment', 'bank_transfer', null, '무통장입금', []));

        $this->assertGreaterThan(0, $orderId);
        $this->assertSame('awaiting_payment', $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']);
        $this->assertSame(10, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $payment['status']);
    }

    /** C-04: 재고가 모자라면 주문을 취소 상태로 남겨 환불 추적이 가능하게 한다 */
    public function testConvertAttempt_insufficientStock_leavesCancelledOrderWithCharge(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 1]);
        $attemptId = $this->createAttempt($userId, $product, 5);
        $tid       = 'TID-FAIL-' . uniqid();

        $orderId = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', $tid, 'card', []);

        $this->assertSame(0, $orderId, '전환은 실패로 보고돼야 한다');

        // 청구는 이미 일어났으므로 환불 추적용 흔적이 남아야 한다.
        $order = $db->table('orders')->where('user_id', $userId)->get()->getRowArray();
        $this->assertNotNull($order);
        $this->track((int) $order['id']);
        $this->assertSame('cancelled', $order['status']);

        $payment = $db->table('payments')->where('pg_tid', $tid)->get()->getRowArray();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment['status']);

        $this->assertSame(1, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
    }
}
