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
        // 방어 대상인 `$attempt['id'] ?? '?'` 는 디코드가 **실패했을 때만** 실행된다.
        // 유효한 JSON 을 넣으면 그 경로를 타지 않아 아무것도 검증하지 못하므로,
        // 반드시 깨진 스냅샷 + id 키 없음 조합이어야 한다.
        $attempt = ['items_snapshot' => '{bad json'];

        $result = $this->model->withItems($attempt);

        $this->assertSame([], $result['items']);
        // 로그가 '?' 로 대체돼 나갔다는 것이 곧 방어 코드가 실행됐다는 증거다.
        $this->assertLogged('critical', '[OrderAttempt] items_snapshot 디코드 실패 — attempt_id=?');
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

    /**
     * A-09: 수량 1장짜리 쿠폰은 두 번째 attempt 에서 선점에 실패한다
     *
     * 브리프 원안은 같은 사용자에게 같은 쿠폰의 user_coupon 을 두 장 발급하지만,
     * `user_coupons` 에는 `UNIQUE(user_id, coupon_id)` 제약(2026-06-10-000009
     * 마이그레이션)이 있어 그 조합은 애초에 INSERT 될 수 없다. 실제로 발생 가능한
     * 레이스는 "총 수량이 한정된 쿠폰을 서로 다른 사용자가 각자 issued 상태로
     * 보유"한 상황이므로, 두 번째 user_coupon 은 별도 사용자에게 발급한다 —
     * 검증 대상인 `coupons.used_count < total_qty` 가드는 동일하게 걸린다.
     */
    public function testCreateAttempt_exhaustedCoupon_returnsZero(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $otherUser = $this->insertUser();
        $product   = $this->insertProduct();
        $coupon    = $this->insertCoupon(['total_qty' => 1]);

        $firstUc  = $this->insertUserCoupon($userId, $coupon['id']);
        $secondUc = $this->insertUserCoupon($otherUser, $coupon['id']);

        $first  = $this->createAttempt($userId, $product, 1, $coupon['id'], $firstUc, 3000);
        $second = $this->createAttempt($otherUser, $product, 1, $coupon['id'], $secondUc, 3000);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '소진된 쿠폰으로 두 번째 시도가 만들어지면 안 된다');

        // 두 번째 시도가 롤백됐으므로 used_count 는 1 에서 멈춰야 한다.
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);
        $this->assertSame('issued', $db->table('user_coupons')->where('id', $secondUc)->get()->getRowArray()['status']);
    }

    /** A-10: 이미 사용된 user_coupon 은 재선점되지 않는다 */
    public function testCreateAttempt_alreadyUsedUserCoupon_returnsZero(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $first  = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);
        $second = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);
    }

    /**
     * A-11: 클레임은 한 번만 성공한다 (결제 멱등성의 핵심)
     *
     * claimForConversion() 의 UPDATE 는 status·converted_at·updated_at 을 매번
     * 같은 목표값으로 다시 쓴다. MySQL 의 affected_rows 는 "매치된 행 수"가
     * 아니라 "실제로 값이 바뀐 행 수"를 세기 때문에, WHERE status='pending'
     * 가드가 없어도 같은 값을 다시 쓰면 affectedRows 가 0 이 되어 두 번째
     * 호출이 우연히 null 을 반환할 수 있다 — sleep 으로 초를 흘려보내
     * updated_at 을 갱신시키는 방식은 이 우연에 기대는 것이라 회귀를 못
     * 잡을 수 있다.
     *
     * 대신 1차 클레임 뒤 converted_at 에 구분 가능한 센티넬 값을 직접
     * 심어두고, 2차 클레임이 그 값을 실제로 건드리지 않았는지까지
     * 단언한다. WHERE status='pending' 가드가 사라지면 2차 UPDATE 가
     * 센티넬을 덮어쓰므로, 이 단언은 가드 유무와 무관하게 항상 변이를
     * 탐지한다.
     */
    public function testClaimForConversion_isIdempotent(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $first = $this->model->claimForConversion($attemptId);

        // 센티넬: 2차 클레임이 행을 실제로 건드리면 값이 달라지도록 표식을 심는다.
        $sentinel = '2000-01-01 00:00:00';
        $db->table('order_attempts')->where('id', $attemptId)->update(['converted_at' => $sentinel]);

        $second = $this->model->claimForConversion($attemptId);

        $this->assertNotNull($first);
        $this->assertSame($attemptId, (int) $first['id']);
        $this->assertNull($second, '두 번째 클레임은 반드시 실패해야 한다');
        $this->assertSame(
            $sentinel,
            $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['converted_at'],
            '이미 클레임된 시도는 두 번째 호출에서 어떤 컬럼도 다시 쓰이면 안 된다'
        );
    }

    /** A-12: markFailed 는 쿠폰·포인트를 복구한다 */
    public function testMarkFailed_restoresCouponAndPoints(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser(5000);
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000, 5000);

        $this->assertTrue($this->model->markFailed($attemptId, '사용자 결제창 이탈'));

        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(0, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertNull($uc['order_attempt_id']);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('failed', $attempt['status']);
        $this->assertSame('사용자 결제창 이탈', $attempt['fail_reason']);
    }

    /** A-13: markFailed 를 두 번 불러도 포인트는 한 번만 환급된다 */
    public function testMarkFailed_twice_refundsPointsOnce(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertTrue($this->model->markFailed($attemptId, '1차'));
        $this->assertFalse($this->model->markFailed($attemptId, '2차'), '이미 처리된 시도는 다시 복구하면 안 된다');

        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(
            1,
            $db->table('point_logs')->where('order_attempt_id', $attemptId)->where('type', 'refund')->countAllResults()
        );
    }

    /** A-14: 클레임된 시도는 실패 처리되지 않는다 */
    public function testMarkFailed_afterClaim_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);
        $this->model->claimForConversion($attemptId);

        $this->assertFalse($this->model->markFailed($attemptId, '경합'));
        $this->assertSame(0, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
    }

    /** A-15: 30분 초과 pending 은 만료되고 복구된다. orders 는 생기지 않는다 */
    public function testExpireStale_expiresAndRestores(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);
        $db->table('order_attempts')->where('id', $attemptId)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime('-40 minutes')),
        ]);

        $count = $this->model->expireStale(30);

        // expireStale() 은 테이블 전체를 훑으므로 반환 개수를 정확히 1 로 단언하면
        // 앞선 실행이 남긴 pending 행 하나에도 깨진다. 이 테스트가 지켜야 할 것은
        // "내가 만든 시도가 만료되고 복구됐는가" 이므로 그 행을 직접 단언한다.
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame('expired', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());
    }

    /** A-16: 29분 지난 시도는 만료 대상이 아니다 */
    public function testExpireStale_recentAttempt_untouched(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);
        $db->table('order_attempts')->where('id', $attemptId)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime('-29 minutes')),
        ]);

        $this->model->expireStale(30);

        $this->assertSame('pending', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
    }

    /**
     * A-17: findPendingForUser() — 본인 소유의 pending 시도는 items 키가
     * 붙어 반환된다
     */
    public function testFindPendingForUser_ownPending_returnsAttemptWithItems(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $result = $this->model->findPendingForUser($attemptId, $userId);

        $this->assertNotNull($result);
        $this->assertSame($attemptId, (int) $result['id']);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
    }

    /**
     * A-18: findPendingForUser() — PG 콜백에서 소유권을 검증하는 관문이다.
     * 다른 사용자 id 로 조회하면 반드시 null 을 반환해야 한다(where('user_id', ...)
     * 가드 회귀 방지).
     */
    public function testFindPendingForUser_otherUser_returnsNull(): void
    {
        $userId    = $this->insertUser();
        $otherUser = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $result = $this->model->findPendingForUser($attemptId, $otherUser);

        $this->assertNull($result);
    }

    /** A-19: findPendingForUser() — 이미 클레임(converted)된 시도는 조회되지 않는다 */
    public function testFindPendingForUser_alreadyConverted_returnsNull(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $this->model->claimForConversion($attemptId);

        $result = $this->model->findPendingForUser($attemptId, $userId);

        $this->assertNull($result);
    }

    /**
     * A-20: restoreCoupon() 의 source='code' 삭제 분기.
     *
     * 기존 insertUserCoupon() 헬퍼는 항상 source='admin' 인 행을 만들어
     * update 로 되돌리는 분기만 탄다. userCouponId 를 null 로 넘기면
     * preemptCoupon() 이 코드 입력 경로를 타 source='code' 인 user_coupons
     * 행을 새로 INSERT 하므로, 그 행이 markFailed() 로 실제 DELETE 되는지
     * 검증한다.
     */
    public function testMarkFailed_restoresCodeSourceCoupon_byDeletingRow(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon();

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], null, 3000);

        $codeUc = $db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $coupon['id'])
            ->where('source', 'code')
            ->get()->getRowArray();
        $this->assertNotNull($codeUc, 'preemptCoupon() 이 source=code 행을 생성해야 한다');
        // 이 행은 insertUserCoupon() 을 거치지 않아 $this->cleanup 에 자동
        // 등록되지 않는다. markFailed() 가 정상적으로 지우면 아래 delete 는
        // no-op 이 되고, 테스트가 실패해도 tearDown() 에서 누수 없이 정리된다.
        $this->cleanup['user_coupons'][] = (int) $codeUc['id'];

        $this->assertTrue($this->model->markFailed($attemptId, '결제 실패'));

        $this->assertNull(
            $db->table('user_coupons')->where('id', $codeUc['id'])->get()->getRowArray(),
            'source=code 행은 update 가 아니라 delete 로 복구되어야 한다'
        );
    }

    /**
     * A-21: 무료배송 쿠폰(할인액 0원)으로 배송비가 이미 0원인 주문을 시도한 뒤
     * 실패하면, 선점된 쿠폰이 반드시 복구되어야 한다.
     *
     * restoreCoupon() 이 과거 `coupon_discount_amount !== 0` 을 추가 조건으로
     * 요구했을 때는, preemptCoupon() 이 금액과 무관하게 무조건 선점했던 것과
     * 비대칭이라 이 케이스에서 복구가 누락됐다 — coupons.used_count 가 영원히
     * 부풀고 user_coupons 가 'used' 로 영구히 묶이는 회귀(쿠폰 영구 잠김)였다.
     * couponDiscountAmount 를 0 으로 명시해 그 경로를 직접 재현한다.
     */
    public function testMarkFailed_freeShippingCouponWithZeroDiscount_stillRestoresCoupon(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        // couponDiscount = 0 — 무료배송 쿠폰을 배송비 0원 주문에 쓴 상황을 재현한다.
        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 0);

        $this->assertGreaterThan(0, $attemptId, '선점은 할인액과 무관하게 성공해야 한다');
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);

        $this->assertTrue($this->model->markFailed($attemptId, '결제 실패'));

        $this->assertSame(
            0,
            (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count'],
            'used_count 가 되돌아가지 않으면 쿠폰이 영구히 소모된 것으로 남는다'
        );

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status'], 'user_coupons 가 used 로 남으면 사용자가 쿠폰을 다시 쓸 수 없다');
        $this->assertNull($uc['order_attempt_id']);
    }

    /**
     * A-22: failPendingForUser() — 해당 회원의 pending 시도를 모두 걷어내고
     * 쿠폰·포인트를 복구한다. 다른 회원의 pending 시도는 건드리지 않는다.
     * (이슈 #214 — 나이스페이·브라우저 종료 안전망)
     */
    public function testFailPendingForUser_restoresOwnAttemptsOnlyNotOtherUsers(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser(5000);
        $otherUser = $this->insertUser(5000);
        $product   = $this->insertProduct();

        $mine  = $this->createAttempt($userId, $product, 1, null, null, 0, 2000);
        $other = $this->createAttempt($otherUser, $product, 1, null, null, 0, 3000);

        $count = $this->model->failPendingForUser($userId, '주문서 재진입');

        $this->assertSame(1, $count, '내 pending 시도 1건만 걷어내야 한다');

        $mineAttempt = $db->table('order_attempts')->where('id', $mine)->get()->getRowArray();
        $this->assertSame('failed', $mineAttempt['status']);
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'], '내 포인트는 복구돼야 한다');

        $otherAttempt = $db->table('order_attempts')->where('id', $other)->get()->getRowArray();
        $this->assertSame('pending', $otherAttempt['status'], '다른 회원의 pending 시도는 건드리면 안 된다');
        $this->assertSame(2000, (int) $db->table('users')->where('id', $otherUser)->get()->getRowArray()['point_balance'], '다른 회원의 포인트는 그대로 선점 상태여야 한다');
    }

    /** A-23: failPendingForUser() — 이미 converted 된 시도는 건드리지 않는다. */
    public function testFailPendingForUser_doesNotTouchAlreadyConvertedAttempt(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser(5000);
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 2000);

        $this->model->claimForConversion($attemptId);

        $count = $this->model->failPendingForUser($userId, '주문서 재진입');

        $this->assertSame(0, $count, '이미 전환된 시도는 걷어낼 대상이 아니다');
        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('converted', $attempt['status'], 'converted 상태가 유지되어야 한다');
        $this->assertSame(3000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'], '전환된 시도의 포인트는 환급되면 안 된다');
    }

    /** A-24: failPendingForUser() — pending 시도가 없으면 0을 반환하고 아무 것도 바꾸지 않는다. */
    public function testFailPendingForUser_noPendingAttempts_returnsZero(): void
    {
        $userId = $this->insertUser();

        $count = $this->model->failPendingForUser($userId, '주문서 재진입');

        $this->assertSame(0, $count);
    }

    /**
     * A-25: restoreCoupon() — 복구 시점에 쿠폰 유효기간이 이미 지났다면
     * 'issued' 가 아니라 'expired' 로 되돌아가야 한다(이슈 #214 후속).
     * used_count 는 유효기간과 무관하게 항상 복원된다.
     */
    public function testMarkFailed_expiredCoupon_restoresAsExpiredStatus(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon([
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertTrue($this->model->markFailed($attemptId, '결제 실패'));

        $this->assertSame(
            0,
            (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count'],
            '만료 여부와 무관하게 used_count 는 항상 복원되어야 한다'
        );

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('expired', $uc['status'], '유효기간이 지난 쿠폰은 다시 사용 가능한 상태로 복원되면 안 된다');
        $this->assertNull($uc['order_attempt_id']);
    }

    /** A-26: restoreCoupon() — 유효기간이 아직 남은 쿠폰은 기존과 동일하게 'issued' 로 복원된다(회귀 방지). */
    public function testMarkFailed_notYetExpiredCoupon_restoresAsIssuedStatus(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon([
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertTrue($this->model->markFailed($attemptId, '결제 실패'));

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertNull($uc['order_attempt_id']);
    }

    /** A-27: restoreCoupon() — expires_at 이 NULL(무기한)이면 기존과 동일하게 'issued' 로 복원된다(회귀 방지). */
    public function testMarkFailed_noExpiryCoupon_restoresAsIssuedStatus(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon(['expires_at' => null]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertTrue($this->model->markFailed($attemptId, '결제 실패'));

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertNull($uc['order_attempt_id']);
    }
}
