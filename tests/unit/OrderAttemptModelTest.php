<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderAttemptModel — 주문 시도 생명주기
 * 이슈 #214
 */
final class OrderAttemptModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderAttemptModel $model;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'user_coupons'   => [],
        'coupons'        => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        foreach (['user_coupons', 'coupons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    private function insertUser(int $pointBalance = 0): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'oatest_' . $uid,
            'email'         => 'oa-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OAUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
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
            'name'           => 'OAProd_' . uniqid(),
            'slug'           => 'oa-prod-' . uniqid(),
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

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertCoupon(array $extra = []): array
    {
        $db   = db_connect();
        $code = 'OAC-' . strtoupper(uniqid());
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => 'OA Coupon',
            'type'                => 'fixed',
            'discount_value'      => 3000,
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
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function makeCartItem(array $product, int $qty = 1): array
    {
        return [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => $qty,
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
        ];
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function createAttempt(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0
    ): int {
        $id = $this->model->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product, $qty)],
            $couponId,
            $userCouponId,
            $couponDiscount,
            $pointUsed,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    public function testOrderAttemptsTableExists(): void
    {
        $db = db_connect();

        $this->assertTrue($db->tableExists('order_attempts'));
        $this->assertTrue($db->fieldExists('items_snapshot', 'order_attempts'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'user_coupons'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'point_logs'));
    }

    /** A-01: attempt 생성 시 orders 에는 아무것도 만들지 않는다 */
    public function testCreateAttempt_doesNotTouchOrdersTable(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);

        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('pending', $attempt['status']);
        $this->assertSame(10000, (int) $attempt['payable_amount']);
        $this->assertStringStartsWith('ORD-', $attempt['order_number']);
    }

    /** A-02: items_snapshot 에 order_items 전환용 라인이 담긴다 */
    public function testCreateAttempt_storesItemsSnapshot(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 2);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $items   = json_decode((string) $attempt['items_snapshot'], true);

        $this->assertCount(1, $items);
        $this->assertSame((int) $product['id'], (int) $items[0]['product_id']);
        $this->assertSame(2, (int) $items[0]['qty']);
        $this->assertSame(20000, (int) $items[0]['subtotal']);
        $this->assertArrayHasKey('cost_price', $items[0]);
        $this->assertArrayNotHasKey('order_id', $items[0]);
    }

    /** A-03: 쿠폰 선점 — used_count 증가 + user_coupons 가 attempt 를 가리킨다 */
    public function testCreateAttempt_preemptsCouponAgainstAttempt(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
        $this->assertSame($attemptId, (int) $uc['order_attempt_id']);
        $this->assertNull($uc['order_id']);
    }

    /** A-04: 포인트 선점 — 잔액 차감 + point_logs 가 attempt 를 가리킨다 */
    public function testCreateAttempt_preemptsPointsAgainstAttempt(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertSame(0, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);

        $log = $db->table('point_logs')->where('order_attempt_id', $attemptId)->where('type', 'use')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(-5000, (int) $log['amount']);
        $this->assertNull($log['order_id']);
    }

    /** A-05: 포인트 잔액 부족이면 attempt 를 만들지 않는다 */
    public function testCreateAttempt_insufficientPoints_returnsZero(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(1000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertSame(0, $attemptId);
        $this->assertSame(0, $db->table('order_attempts')->where('user_id', $userId)->countAllResults());
        $this->assertSame(1000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
    }
}
