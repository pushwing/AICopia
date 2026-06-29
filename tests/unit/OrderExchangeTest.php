<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 교환 요청·승인·거부·완료 흐름 테스트
 * 재고 복구 확인 / 쿠폰·포인트는 복원하지 않음을 검증한다.
 */
final class OrderExchangeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    private array $cleanup = [
        'users'    => [],
        'products' => [],
        'orders'   => [],
        'coupons'  => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            $db->table('exchange_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('order_status_logs')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('payments')->whereIn('order_id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('user_coupons')->whereIn('user_id', $this->cleanup['users'])->delete();
            $db->table('point_logs')->whereIn('user_id', $this->cleanup['users'])->delete();
        }

        foreach (['orders', 'products', 'users', 'coupons'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'         => 'exc_' . uniqid() . '@test.local',
            'username'      => 'exc_' . uniqid(),
            'password'      => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'      => '교환테스터',
            'role'          => 'member',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(int $stock): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => '교환테스트상품',
            'slug'           => 'exc-prod-' . uniqid(),
            'price'          => 10000,
            'stock'          => $stock,
            'status'         => $stock > 0 ? 'on_sale' : 'sold_out',
            'shipping_type'  => 'fixed',
            'shipping_fee'   => 3000,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status, array $extra = []): int
    {
        $db = db_connect();
        $defaults = [
            'user_id'                => $userId,
            'order_number'           => 'EXC-' . uniqid(),
            'status'                 => $status,
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
        ];
        // delivered 상태 기본값: 1일 전 배송 완료 (7일 이내)
        if ($status === 'delivered' && ! isset($extra['delivered_at'])) {
            $defaults['delivered_at'] = date('Y-m-d H:i:s', strtotime('-1 day'));
        }
        $db->table('orders')->insert(array_merge($defaults, $extra));
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertOrderItem(int $orderId, int $productId, int $qty): void
    {
        db_connect()->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => '교환테스트상품',
            'product_price' => 10000,
            'qty'           => $qty,
            'subtotal'      => 10000 * $qty,
        ]);
    }

    private function insertCoupon(int $usedCount = 1): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'code'                => 'EXC-' . uniqid(),
            'name'                => '교환테스트쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 1000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => 0,
            'used_count'          => $usedCount,
            'per_user_limit'      => 0,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId, int $orderId): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => $orderId,
            'source'     => 'admin',
            'status'     => 'used',
            'issued_at'  => date('Y-m-d H:i:s'),
            'used_at'    => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    // ── requestExchange ───────────────────────────────────────────────────────

    public function testRequestExchangeSucceeds(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');
        $db      = db_connect();

        $this->assertTrue($this->model->requestExchange($orderId, $userId, 'wrong_size'));

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('exchange_requested', $order['status']);
        $this->assertSame('사이즈/색상 오선택', $order['exchange_reason']);
    }

    public function testRequestExchangeSavesNote(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');

        $this->model->requestExchange($orderId, $userId, 'wrong_size', 'L사이즈 블랙으로 교환 원합니다.');

        $order = db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('L사이즈 블랙으로 교환 원합니다.', $order['exchange_request_note']);
    }

    public function testRequestExchangeFailsForNonDeliveredOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'paid');

        $this->assertFalse($this->model->requestExchange($orderId, $userId, 'simple_change'));
        $this->assertSame('paid',
            db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRequestExchangeFailsForWrongUser(): void
    {
        $ownerId = $this->insertUser();
        $otherId = $this->insertUser();
        $orderId = $this->insertOrder($ownerId, 'delivered');

        $this->assertFalse($this->model->requestExchange($orderId, $otherId, 'simple_change'));
    }

    public function testRequestExchangeFailsAfter7Days(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered', [
            'delivered_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
        ]);

        $this->assertFalse($this->model->requestExchange($orderId, $userId, 'simple_change'));
        $this->assertSame('delivered',
            db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRequestExchangeSucceedsWithin7Days(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered', [
            'delivered_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
        ]);

        $this->assertTrue($this->model->requestExchange($orderId, $userId, 'wrong_size'));
    }

    // ── approveExchange ───────────────────────────────────────────────────────

    public function testApproveExchangeRestoresStock(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);
        $orderId   = $this->insertOrder($userId, 'exchange_requested');
        $this->insertOrderItem($orderId, $productId, 3);
        $db = db_connect();

        $this->assertTrue($this->model->approveExchange($orderId));

        $stock = (int) $db->table('products')->where('id', $productId)->get()->getRowArray()['stock'];
        $this->assertSame(13, $stock);

        $this->assertSame('exchange_approved',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testApproveExchangeDoesNotRestoreCoupon(): void
    {
        $userId    = $this->insertUser();
        $couponId  = $this->insertCoupon(1);
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'exchange_requested', [
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => 1000,
        ]);
        $this->insertOrderItem($orderId, $productId, 1);
        $ucId = $this->insertUserCoupon($userId, $couponId, $orderId);
        $db = db_connect();

        $this->model->approveExchange($orderId);

        // 교환은 환불이 아니므로 쿠폰 복원 없음
        $usedCount = (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'];
        $this->assertSame(1, $usedCount);

        $uc = $db->table('user_coupons')->where('id', $ucId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
    }

    public function testApproveExchangeDoesNotRestorePoints(): void
    {
        $userId    = $this->insertUser(500);
        $productId = $this->insertProduct(5);
        $orderId   = $this->insertOrder($userId, 'exchange_requested', [
            'point_used_amount' => 300,
        ]);
        $this->insertOrderItem($orderId, $productId, 1);
        $db = db_connect();

        $this->model->approveExchange($orderId);

        // 교환은 환불이 아니므로 포인트 복원 없음
        $balance = (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'];
        $this->assertSame(500, $balance);

        $refundLog = $db->table('point_logs')
            ->where('order_id', $orderId)->where('type', 'refund')->get()->getRowArray();
        $this->assertFalse((bool) $refundLog);
    }

    public function testApproveExchangeFailsForNonRequestedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');

        $this->assertFalse($this->model->approveExchange($orderId));
    }

    // ── rejectExchange ────────────────────────────────────────────────────────

    public function testRejectExchangeRestoresDeliveredStatus(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'exchange_requested');
        $db      = db_connect();

        $this->assertTrue($this->model->rejectExchange($orderId));

        $this->assertSame('delivered',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testRejectExchangeFailsForNonRequestedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'delivered');

        $this->assertFalse($this->model->rejectExchange($orderId));
    }

    // ── completeExchange ──────────────────────────────────────────────────────

    public function testCompleteExchangeSetsExchangeCompleted(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct(10);
        $orderId   = $this->insertOrder($userId, 'exchange_approved');
        $this->insertOrderItem($orderId, $productId, 1);
        $db        = db_connect();

        $exchangeItems = [[
            'product_id'       => $productId,
            'sku_id'           => null,
            'product_name'     => '교환테스트상품',
            'sku_option_label' => null,
            'product_price'    => 10000,
            'qty'              => 1,
        ]];

        $this->assertTrue($this->model->completeExchange($orderId, $exchangeItems));

        $this->assertSame('exchange_completed',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']
        );
    }

    public function testCompleteExchangeFailsForNonApprovedOrder(): void
    {
        $userId  = $this->insertUser();
        $orderId = $this->insertOrder($userId, 'exchange_requested');

        $this->assertFalse($this->model->completeExchange($orderId, []));
    }
}
