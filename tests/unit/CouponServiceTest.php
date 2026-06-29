<?php

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

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'cstest_' . $uid,
            'email'         => 'cs-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'CsTestUser',
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

    // ── V: validate (코드 경로) ───────────────────────────────────────────────

    /** V-01: 존재하지 않는 코드 → valid=false, 메시지 포함 */
    public function testValidate_invalidCode_returnsFail(): void
    {
        $userId = $this->insertUser();
        $result = $this->service->validate('INVALID-' . uniqid(), $userId, 10000);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['message']);
    }

    /** V-02: is_active=0 → findByCode에서 걸러짐 → valid=false */
    public function testValidate_inactiveCoupon_returnsFail(): void
    {
        $userId  = $this->insertUser();
        $coupon  = $this->insertCoupon(['is_active' => 0]);

        $result  = $this->service->validate($coupon['code'], $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-03: starts_at=내일 → valid=false */
    public function testValidate_startDateFuture_returnsFail(): void
    {
        $userId = $this->insertUser();
        $coupon = $this->insertCoupon(['starts_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $result = $this->service->validate($coupon['code'], $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-04: expires_at=어제 → valid=false */
    public function testValidate_expiredCoupon_returnsFail(): void
    {
        $userId = $this->insertUser();
        $coupon = $this->insertCoupon(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $result = $this->service->validate($coupon['code'], $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-05: total_qty=100, used_count=100 → 수량 소진 → valid=false */
    public function testValidate_quantityExhausted_returnsFail(): void
    {
        $userId = $this->insertUser();
        $coupon = $this->insertCoupon(['total_qty' => 100, 'used_count' => 100]);

        $result = $this->service->validate($coupon['code'], $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-06: min_order_amount=20000, orderAmount=15000 → valid=false, 메시지에 최소 금액 포함 */
    public function testValidate_belowMinOrderAmount_returnsFailWithAmountInMessage(): void
    {
        $userId = $this->insertUser();
        $coupon = $this->insertCoupon(['min_order_amount' => 20000]);

        $result = $this->service->validate($coupon['code'], $userId, 15000);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('20,000', $result['message']);
    }

    /** V-07: per_user_limit=1, 이미 사용한 기록 존재 → valid=false */
    public function testValidate_alreadyUsedBySameUser_returnsFail(): void
    {
        $userId   = $this->insertUser();
        $coupon   = $this->insertCoupon(['per_user_limit' => 1]);
        $this->insertUserCoupon($userId, $coupon['id'], 'used');

        $result = $this->service->validate($coupon['code'], $userId, 10000);
        $this->assertFalse($result['valid']);
    }

    /** V-08: 모든 조건 충족 — 정액 쿠폰 → valid=true, discount=3000 */
    public function testValidate_validFixedCoupon_returnsDiscountAmount(): void
    {
        $userId = $this->insertUser();
        $coupon = $this->insertCoupon(['discount_value' => 3000]);

        $result = $this->service->validate($coupon['code'], $userId, 10000);

        $this->assertTrue($result['valid']);
        $this->assertSame(3000, $result['discount']);
    }

    // ── V: validateByUserCouponId ─────────────────────────────────────────────

    /** V-09: user_coupon_id 경로 — issued 상태 → valid=true */
    public function testValidateByUserCouponId_issuedStatus_returnsValid(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'issued');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertTrue($result['valid']);
    }

    /** V-10: user_coupon_id 경로 — used 상태 → valid=false */
    public function testValidateByUserCouponId_usedStatus_returnsFail(): void
    {
        $userId       = $this->insertUser();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id'], 'used');

        $result = $this->service->validateByUserCouponId($userCouponId, $userId, 10000);
        $this->assertFalse($result['valid']);
    }
}
