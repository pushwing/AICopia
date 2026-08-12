<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\CouponService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * CouponService — 할인 계산(C) 및 유효성 검증(V) 테스트
 * 이슈 #12 · 1단계
 */
final class CouponServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private CouponService $service;

    private array $cleanup = [
        'user_coupons' => [],
        'coupons'      => [],
        'users'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CouponService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['user_coupons'] !== []) {
            $db->table('user_coupons')->whereIn('id', $this->cleanup['user_coupons'])->delete();
        }
        if ($this->cleanup['coupons'] !== []) {
            $db->table('coupons')->whereIn('id', $this->cleanup['coupons'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    /** calculateDiscount 용 — DB 불필요, 배열만 생성 */
    private function makeCoupon(array $extra = []): array
    {
        return array_merge([
            'type'                => 'fixed',
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'is_active'           => 1,
            'starts_at'           => null,
            'expires_at'          => null,
            'target_grade'        => null,
            'per_user_limit'      => 1,
        ], $extra);
    }

    private function insertCoupon(array $extra = []): array
    {
        $code = 'TEST-' . strtoupper(uniqid());
        $db   = db_connect();
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => '테스트쿠폰',
            'type'                => 'fixed',
            'target_grade'        => null,
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'starts_at'           => null,
            'expires_at'          => null,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $extra));
        $id                          = (int) $db->insertID();
        $this->cleanup['coupons'][]  = $id;
        $resolvedCode = $extra['code'] ?? $code;
        return ['id' => $id, 'code' => $resolvedCode];
    }

    private function insertUser(string $grade = 'bronze'): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'cstest_' . $uid,
            'email'         => 'cs-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'CsTestUser',
            'role'          => 'member',
            'grade'         => $grade,
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId, string $status = 'issued'): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => null,
            'source'     => 'admin',
            'status'     => $status,
            'issued_at'  => date('Y-m-d H:i:s'),
            'used_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;
        return $id;
    }

    // ── C: calculateDiscount ──────────────────────────────────────────────────

    /** C-01: 정액 쿠폰 — 일반 */
    public function testCalculateDiscount_fixed_normal(): void
    {
        $coupon = $this->makeCoupon(['type' => 'fixed', 'discount_value' => 3000]);
        $this->assertSame(3000, $this->service->calculateDiscount($coupon, 10000));
    }

    /** C-02: 정액 쿠폰 — 주문 금액 초과 방지 */
    public function testCalculateDiscount_fixed_cappedAtOrderAmount(): void
    {
        $coupon = $this->makeCoupon(['type' => 'fixed', 'discount_value' => 15000]);
        $this->assertSame(10000, $this->service->calculateDiscount($coupon, 10000));
    }

    /** C-03: 정률 쿠폰 — 일반 */
    public function testCalculateDiscount_percent_normal(): void
    {
        $coupon = $this->makeCoupon(['type' => 'percent', 'discount_value' => 10, 'max_discount_amount' => 0]);
        $this->assertSame(1000, $this->service->calculateDiscount($coupon, 10000));
    }

    /** C-04: 정률 쿠폰 — max_discount_amount 상한 적용 */
    public function testCalculateDiscount_percent_maxDiscountCapped(): void
    {
        $coupon = $this->makeCoupon(['type' => 'percent', 'discount_value' => 50, 'max_discount_amount' => 3000]);
        $this->assertSame(3000, $this->service->calculateDiscount($coupon, 10000));
    }

    /** C-05: 정률 쿠폰 — max_discount_amount=0 (무제한) */
    public function testCalculateDiscount_percent_maxDiscountZeroIsUnlimited(): void
    {
        $coupon = $this->makeCoupon(['type' => 'percent', 'discount_value' => 20, 'max_discount_amount' => 0]);
        $this->assertSame(2000, $this->service->calculateDiscount($coupon, 10000));
    }

    /** C-06: 정률 쿠폰 — floor 절사 */
    public function testCalculateDiscount_percent_floorTruncation(): void
    {
        $coupon = $this->makeCoupon(['type' => 'percent', 'discount_value' => 10, 'max_discount_amount' => 0]);
        $this->assertSame(99, $this->service->calculateDiscount($coupon, 999));
    }

    // ── V: validateByUserCouponId (보유 쿠폰 경로) ────────────────────────────
    //
    // 쿠폰 코드로 검증하던 validate() 는 발급받지 않은 쿠폰도 코드만 알면
    // 통과시켜 제거했다(이슈 #219). 이제 검증 경로는 보유 쿠폰 하나뿐이다.

    /** V-01: 존재하지 않는 user_coupon_id → valid=false, 메시지 포함 */
    public function testValidate_unknownUserCouponId_returnsFail(): void
    {
        $userId = $this->insertUser();
        $result = $this->service->validateByUserCouponId(0, $userId, 10000);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['message']);
    }

    /** V-01b: 남의 보유 쿠폰을 지정하면 → valid=false (소유자 확인) */
    public function testValidate_otherUsersCoupon_returnsFail(): void
    {
        $owner     = $this->insertUser();
        $attacker  = $this->insertUser();
        $coupon    = $this->insertCoupon();
        $ownersUcId = $this->insertUserCoupon($owner, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($ownersUcId, $attacker, 10000);

        $this->assertFalse($result['valid'], '남의 쿠폰을 id 로 지정해 쓸 수 있으면 안 된다');
    }

    /** V-02: is_active=0 → valid=false */
    public function testValidate_inactiveCoupon_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['is_active' => 0]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-03: starts_at=내일 → valid=false, 메시지에 시작 시각 노출 */
    public function testValidate_startDateFuture_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $startsAt     = date('Y-m-d H:i:s', strtotime('+1 day'));
        $coupon       = $this->insertCoupon(['starts_at' => $startsAt]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(
            date('Y-m-d H:i', strtotime($startsAt)),
            $result['message'],
            '시작 시각을 알려주지 않으면 "발급받았는데 왜 못 쓰냐"는 오해로 이어진다',
        );
    }

    /** V-04: expires_at=어제 → valid=false */
    public function testValidate_expiredCoupon_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-05: total_qty=100, used_count=100 → 수량 소진 → valid=false */
    public function testValidate_quantityExhausted_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['total_qty' => 100, 'used_count' => 100]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-06: min_order_amount=20000, orderAmount=15000 → valid=false, 메시지에 최소 금액 포함 */
    public function testValidate_belowMinOrderAmount_returnsFailWithAmountInMessage(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['min_order_amount' => 20000]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 15000);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('20,000', $result['message']);
    }

    /** V-08: 모든 조건 충족 — 정액 쿠폰 → valid=true, discount=3000 */
    public function testValidate_validFixedCoupon_returnsDiscountAmount(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['discount_value' => 3000]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertTrue($result['valid']);
        $this->assertSame(3000, $result['discount']);
    }

    /**
     * V-12: validateByUserCouponId() 가 돌려주는 coupon['id']·user_coupon_id 는
     * int 여야 한다.
     *
     * MySQLi 드라이버는 정수 컬럼도 문자열로 반환한다. OrderController::create() 가
     * 이 값을 그대로 OrderAttemptModel::createAttempt(?int $couponId) 에 넘기는데,
     * 컨트롤러가 declare(strict_types=1) 라 문자열이 새어나가면 TypeError(500)로
     * 이어진다 — 정규화가 깨지면 이 테스트가 먼저 잡아야 한다.
     */
    public function testValidateByUserCouponId_couponIdAndUserCouponIdAreInt(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertTrue($result['valid'], $result['message']);
        $this->assertIsInt($result['coupon']['id'], 'coupon[id]는 int여야 한다(MySQLi가 문자열로 반환하는 것을 정규화해야 함)');
        $this->assertIsInt($result['user_coupon_id'], 'user_coupon_id는 int여야 한다');
        $this->assertSame($userCouponId, $result['user_coupon_id']);
    }

    /**
     * V-13: total_qty=null(무제한)은 정규화 후에도 null 로 보존돼야 한다.
     * 0으로 캐스팅되면 "무제한"이 "소진됨"으로 둔갑해 쿠폰이 전부 막힌다.
     */
    public function testValidate_unlimitedQuantityCoupon_totalQtyStaysNull(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon(['total_qty' => null, 'used_count' => 0]);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertTrue($result['valid'], $result['message']);
        $this->assertNull($result['coupon']['total_qty'], 'total_qty=null(무제한)이 0으로 둔갑하면 안 된다');
    }

    /** V-09: issued 상태 → valid=true */
    public function testValidateByUserCouponId_issuedStatus_returnsValid(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertTrue($result['valid']);
    }

    /** V-10: used 상태(이미 사용) → valid=false */
    public function testValidateByUserCouponId_usedStatus_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'used');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    // ── G: 등급 전용 쿠폰 ─────────────────────────────────────────────────────

    /**
     * G-02: 등급 불일치 → valid=false
     *
     * validateByUserCouponId() 가 재조립하는 쿠폰 배열에 target_grade 를 빠뜨리면
     * 등급 검증이 통째로 건너뛰어져 타 등급 회원이 등급 전용 쿠폰을 쓸 수 있다.
     */
    public function testValidateByUserCouponId_gradeMismatch_returnsFail(): void
    {
        $userId       = $this->insertUser('gold');
        $coupon       = $this->insertCoupon(['target_grade' => 'bronze']);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('등급 전용', $result['message']);
    }

    /** G-03: 보유 쿠폰 경로 — 등급 일치(브론즈 회원 + 브론즈 전용) → valid=true */
    public function testValidateByUserCouponId_gradeMatch_returnsValid(): void
    {
        $userId       = $this->insertUser('bronze');
        $coupon       = $this->insertCoupon(['target_grade' => 'bronze']);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertTrue($result['valid'], $result['message']);
    }

    /** G-04: 다중 등급(콤마 구분) 중 하나와 일치 → valid=true */
    public function testValidateByUserCouponId_multiGradeIncludesUser_returnsValid(): void
    {
        $userId       = $this->insertUser('silver');
        $coupon       = $this->insertCoupon(['target_grade' => 'bronze,silver']);
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);

        $this->assertTrue($result['valid'], $result['message']);
    }

    /** G-05: 주문서 보유 쿠폰 목록 — 등급 불일치 쿠폰은 애초에 노출되지 않는다 */
    public function testGetAvailable_excludesGradeMismatchedCoupon(): void
    {
        $userId  = $this->insertUser('gold');
        $mine    = $this->insertCoupon(['target_grade' => 'gold']);
        $notMine = $this->insertCoupon(['target_grade' => 'bronze']);
        $anyone  = $this->insertCoupon(['target_grade' => null]);

        $this->insertUserCoupon($userId, $mine['id'], 'issued');
        $this->insertUserCoupon($userId, $notMine['id'], 'issued');
        $this->insertUserCoupon($userId, $anyone['id'], 'issued');

        $couponIds = array_map(
            intval(...),
            array_column(new \App\Models\UserCouponModel()->getAvailable($userId, 10000), 'id'),
        );

        $this->assertContains($mine['id'], $couponIds);
        $this->assertContains($anyone['id'], $couponIds);
        $this->assertNotContains($notMine['id'], $couponIds);
    }
}
