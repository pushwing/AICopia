<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel::createPending() 쿠폰 소비 원자성 검증 (이슈 #123)
 *
 * 쿠폰 검증은 트랜잭션 밖(컨트롤러)에서 일어나고 소비는 트랜잭션 안에서 일어난다.
 * 그 사이에 쿠폰 상태가 바뀌었으면 주문 생성 자체가 실패해야 한다 —
 * `UPDATE ... WHERE status = 'issued'` 의 결과를 버리면, 이미 소진된 쿠폰으로도
 * 할인된 payable_amount 를 가진 주문이 그대로 만들어진다.
 *
 * 바로 아래 포인트 차감이 FOR UPDATE + 조건부 UPDATE + affectedRows 검사로
 * 올바르게 구현돼 있으므로, 쿠폰도 같은 패턴을 따라야 한다.
 */
final class CouponDoubleSpendTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_items'  => [],
        'orders'       => [],
        'user_coupons' => [],
        'coupons'      => [],
        'products'     => [],
        'users'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['order_items', 'orders', 'user_coupons', 'coupons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = [
            'order_items'  => [], 'orders' => [], 'user_coupons' => [],
            'coupons'      => [], 'products' => [], 'users' => [],
        ];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $uid = 'CDS' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function insertProduct(): array
    {
        $name = 'CDS상품' . substr(uniqid(), -6);
        $db   = db_connect();
        $db->table('products')->insert([
            'name'          => $name,
            'slug'          => strtolower($name),
            'price'         => 20000,
            'stock'         => 100,
            'status'        => 'on_sale',
            'shipping_type' => 'free',
            'shipping_fee'  => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return ['id' => $id, 'name' => $name, 'price' => 20000];
    }

    /** @param array<string, mixed> $extra */
    private function insertCoupon(array $extra = []): int
    {
        $db = db_connect();
        $db->table('coupons')->insert(array_merge([
            'code'                => 'CDS-' . strtoupper(substr(uniqid(), -8)),
            'name'                => 'CDS쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 5000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId, string $status = 'issued'): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'source'     => 'admin',
            'status'     => $status,
            'issued_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function cartItem(array $product): array
    {
        return [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => 1,
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트 수령인',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function trackOrder(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
            $db  = db_connect();
            $ids = array_column(
                $db->table('order_items')->select('id')->where('order_id', $orderId)->get()->getResultArray(),
                'id',
            );
            $this->cleanup['order_items'] = array_merge($this->cleanup['order_items'], $ids);
        }

        return $orderId;
    }

    private function orderCount(int $userId): int
    {
        return db_connect()->table('orders')->where('user_id', $userId)->countAllResults();
    }

    // ── 차단되어야 하는 경우 ──────────────────────────────────────────────────

    public function testAlreadyUsedUserCouponIsRejected(): void
    {
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $couponId     = $this->insertCoupon();
        // 동시 요청 중 먼저 도착한 쪽이 이미 소진한 상태
        $userCouponId = $this->insertUserCoupon($userId, $couponId, 'used');

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
            $couponId,
            $userCouponId,
            5000,
        ));

        $this->assertSame(0, $orderId, '이미 사용한 쿠폰으로 주문이 생성됐다');
        $this->assertSame(0, $this->orderCount($userId), '롤백되지 않고 주문 행이 남았다');
    }

    public function testUserCouponBelongingToAnotherUserIsRejected(): void
    {
        $userId    = $this->insertUser();
        $otherUser = $this->insertUser();
        $product   = $this->insertProduct();
        $couponId  = $this->insertCoupon();
        // 쿠폰은 남의 것 — UPDATE 의 user_id 조건에 걸려 0행이 되어야 한다
        $userCouponId = $this->insertUserCoupon($otherUser, $couponId, 'issued');

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
            $couponId,
            $userCouponId,
            5000,
        ));

        $this->assertSame(0, $orderId, '남의 쿠폰으로 주문이 생성됐다');
        $this->assertSame(0, $this->orderCount($userId));
    }

    public function testCouponExhaustedByTotalQtyIsRejected(): void
    {
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        // 수량이 이미 소진된 쿠폰 (검증 시점엔 여유가 있었으나 그 사이 마감된 상황)
        $couponId     = $this->insertCoupon(['total_qty' => 1, 'used_count' => 1]);
        $userCouponId = $this->insertUserCoupon($userId, $couponId, 'issued');

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
            $couponId,
            $userCouponId,
            5000,
        ));

        $this->assertSame(0, $orderId, '수량이 소진된 쿠폰으로 주문이 생성됐다');
        $this->assertSame(0, $this->orderCount($userId));
    }

    // ── 허용되어야 하는 경우 (회귀 방지) ──────────────────────────────────────

    public function testIssuedUserCouponIsConsumedExactlyOnce(): void
    {
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $couponId     = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $couponId, 'issued');

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
            $couponId,
            $userCouponId,
            5000,
        ));

        $this->assertGreaterThan(0, $orderId, '정상 쿠폰으로 주문이 생성되지 않았다');

        $db = db_connect();
        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
        $this->assertSame($orderId, (int) $uc['order_id']);

        $coupon = $db->table('coupons')->where('id', $couponId)->get()->getRowArray();
        $this->assertSame(1, (int) $coupon['used_count'], 'used_count 가 정확히 1 증가하지 않았다');

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame(15000, (int) $order['payable_amount'], '20000 - 5000 할인이 반영되지 않았다');
    }

    public function testCouponWithRemainingQuantityIsAccepted(): void
    {
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $couponId     = $this->insertCoupon(['total_qty' => 2, 'used_count' => 1]);
        $userCouponId = $this->insertUserCoupon($userId, $couponId, 'issued');

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
            $couponId,
            $userCouponId,
            5000,
        ));

        $this->assertGreaterThan(0, $orderId, '재고가 남은 쿠폰인데 주문이 막혔다');
        $coupon = db_connect()->table('coupons')->where('id', $couponId)->get()->getRowArray();
        $this->assertSame(2, (int) $coupon['used_count']);
    }

    public function testOrderWithoutCouponStillWorks(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $orderId = $this->trackOrder($this->model->createPending(
            $userId,
            $this->shippingData(),
            [$this->cartItem($product)],
        ));

        $this->assertGreaterThan(0, $orderId, '쿠폰 없는 주문이 막혔다');
    }
}
