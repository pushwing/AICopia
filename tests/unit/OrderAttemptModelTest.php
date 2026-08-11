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
        'orders'         => [],
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
        foreach (['orders', 'user_coupons', 'coupons', 'products', 'users'] as $table) {
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

    /** A-06: 빈 장바구니로 attempt 를 생성하려면 createAttempt() 가 0 을 반환한다 */
    public function testCreateAttempt_emptyCartItems_returnsZero(): void
    {
        $db     = db_connect();
        $userId = $this->insertUser();

        $attemptId = $this->model->createAttempt(
            $userId,
            $this->shippingData(),
            [],
            null,
            null,
            0,
            0,
            0,
            'toss'
        );

        $this->assertSame(0, $attemptId);
        $this->assertSame(0, $db->table('order_attempts')->where('user_id', $userId)->countAllResults());
    }

    /** A-07: items_snapshot 이 깨졌으면 withItems() 가 로깅 후 빈 배열을 반환한다 (코드 리뷰 지적 #2) */
    public function testWithItems_invalidJson_returnsEmptyItemsArray(): void
    {
        // items_snapshot 컬럼은 MySQL JSON 타입이라 DB 에 깨진 JSON 문자열을
        // 직접 저장할 수 없다(INSERT/UPDATE 단계에서 DB 가 이미 검증·거부한다).
        // withItems() 는 배열을 그대로 받아 디코드만 하므로, DB 를 거치지 않고
        // 깨진 스냅샷이 담긴 attempt 배열을 직접 구성해 호출한다.
        $attempt = ['id' => 999999, 'items_snapshot' => '{invalid json'];

        $result = $this->model->withItems($attempt);

        $this->assertSame([], $result['items']);
        // `json_decode(...) ?: []` 로 되돌리면 디코드 실패(false)와 정상 빈 배열([])을
        // 구분하지 못해도 items 는 여전히 [] 라 위 assertSame 만으로는 회귀를 못 잡는다
        // (재리뷰 지적 #1). 관측 가능한 유일한 차이인 critical 로그를 함께 검증한다.
        // CI4 는 ENVIRONMENT === 'testing' 이면 log_message() 를 TestLogger 로 보내므로
        // 별도 셋업 없이 assertLogged() 를 쓸 수 있다.
        $this->assertLogged('critical', '[OrderAttempt] items_snapshot 디코드 실패 — attempt_id=999999');
    }

    /** A-07b: id 키가 없는 배열을 넘겨도 withItems() 가 경고 없이 처리한다 */
    public function testWithItems_missingIdKey_logsWithoutWarning(): void
    {
        // id 키가 없으면 로그 문자열에서 $attempt['id'] ?? '?' 로 방어되어 '?' 를 사용한다.
        // 이 경우에도 items_snapshot 디코드는 정상 진행되고 items 배열이 붙는다.
        $attempt = ['items_snapshot' => '[]'];

        $result = $this->model->withItems($attempt);

        $this->assertSame([], $result['items']);
        $this->assertArrayHasKey('items', $result);
    }

    /**
     * 테스트가 후보 주문번호 시퀀스를 결정론적으로 주입할 수 있도록,
     * orderNumberCandidate() 를 오버라이드한 익명 서브클래스를 만든다.
     * 시퀀스가 소진되면 마지막 값을 계속 반환한다(10회 연속 충돌 테스트용).
     *
     * @param list<string> $candidates
     */
    private function modelWithCandidates(array $candidates): OrderAttemptModel
    {
        return new class ($candidates) extends OrderAttemptModel {
            private int $callCount = 0;

            /** @param list<string> $candidates */
            public function __construct(private readonly array $candidates)
            {
                parent::__construct();
            }

            protected function orderNumberCandidate(): string
            {
                $value = $this->candidates[$this->callCount] ?? $this->candidates[array_key_last($this->candidates)];
                $this->callCount++;

                return $value;
            }
        };
    }

    /** order_attempts 테이블에 최소 컬럼만 채워 채번 충돌용 행을 직접 심는다. */
    private function insertRawAttempt(string $orderNumber, int $userId): int
    {
        $db = db_connect();
        $db->table('order_attempts')->insert([
            'user_id'        => $userId,
            'order_number'   => $orderNumber,
            'status'         => 'pending',
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'items_snapshot' => '[]',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['order_attempts'][] = $id;

        return $id;
    }

    /** orders 테이블에 최소 컬럼만 채워 채번 충돌용 행을 직접 심는다. */
    private function insertRawOrder(string $orderNumber, int $userId): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'        => $userId,
            'order_number'   => $orderNumber,
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    /** A-08(a): 후보가 orders 에만 이미 있으면 다음 후보로 재시도한다 (재리뷰 지적 #2) */
    public function testCreateAttempt_orderNumberCandidate_retriesWhenTakenInOrders(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $taken = 'ORD-' . date('Ymd') . '-11111';
        $free  = 'ORD-' . date('Ymd') . '-22222';
        $this->insertRawOrder($taken, $userId);

        $model = $this->modelWithCandidates([$taken, $free]);
        $id    = $model->createAttempt($userId, $this->shippingData(), [$this->makeCartItem($product)], null, null, 0, 0, 0, 'toss');
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        $this->assertGreaterThan(0, $id);
        $attempt = $db->table('order_attempts')->where('id', $id)->get()->getRowArray();
        $this->assertSame($free, $attempt['order_number']);
    }

    /** A-08(b): 후보가 order_attempts 에만 이미 있으면 다음 후보로 재시도한다 (재리뷰 지적 #2) */
    public function testCreateAttempt_orderNumberCandidate_retriesWhenTakenInAttempts(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $taken = 'ORD-' . date('Ymd') . '-33333';
        $free  = 'ORD-' . date('Ymd') . '-44444';
        $this->insertRawAttempt($taken, $userId);

        $model = $this->modelWithCandidates([$taken, $free]);
        $id    = $model->createAttempt($userId, $this->shippingData(), [$this->makeCartItem($product)], null, null, 0, 0, 0, 'toss');
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        $this->assertGreaterThan(0, $id);
        $attempt = $db->table('order_attempts')->where('id', $id)->get()->getRowArray();
        $this->assertSame($free, $attempt['order_number']);
    }

    /**
     * A-08(c): 10회 연속 충돌하면 createAttempt() 가 0 을 반환하고 트랜잭션을 열지
     * 않는다(= order_attempts 에 행이 생기지 않는다) (재리뷰 지적 #2)
     */
    public function testCreateAttempt_orderNumberCandidate_allTenCollide_returnsZeroWithoutInserting(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $taken = 'ORD-' . date('Ymd') . '-55555';
        $this->insertRawAttempt($taken, $userId);
        $beforeCount = $db->table('order_attempts')->countAllResults();

        // 후보 시퀀스를 항상 같은(이미 선점된) 값만 반환하도록 해 10회 모두 충돌시킨다.
        $model = $this->modelWithCandidates([$taken]);
        $id    = $model->createAttempt($userId, $this->shippingData(), [$this->makeCartItem($product)], null, null, 0, 0, 0, 'toss');

        $this->assertSame(0, $id);
        $this->assertSame($beforeCount, $db->table('order_attempts')->countAllResults());
    }
}
