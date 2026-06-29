<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 생일 쿠폰 자동 발급 로직 검증
 * 이슈 #④ coupons:birthday 커맨드 핵심 로직
 */
final class BirthdayCouponTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'users'        => [],
        'coupons'      => [],
        'user_coupons' => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ([
            'user_coupons' => 'id',
            'coupons'      => 'id',
            'users'        => 'id',
        ] as $table => $col) {
            if (! empty($this->cleanup[$table])) {
                $db->table($table)->whereIn($col, $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(string $birthday): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'bc_' . $uid,
            'email'         => 'bc-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'BCUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'birthday'      => $birthday,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertCoupon(): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'name'             => 'BC쿠폰_' . uniqid(),
            'code'             => 'BC-' . strtoupper(uniqid()),
            'type'             => 'fixed',
            'discount_value'   => 2000,
            'min_order_amount' => 0,
            'total_qty'        => null,
            'used_count'       => 0,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return $id;
    }

    /** 커맨드 핵심 로직 추출 — 특정 날짜 기준으로 발급 */
    private function runBirthdayIssue(int $couponId, string $targetDate): array
    {
        $db        = db_connect();
        $coupon    = $db->table('coupons')->where('id', $couponId)->where('is_active', 1)->get()->getRowArray();
        if (! $coupon) return ['issued' => 0, 'skipped' => 0];

        $monthDay = date('m-d', strtotime($targetDate));
        $now      = date('Y-m-d H:i:s');
        $todayYmd = $targetDate;

        $users = $db->query(
            "SELECT id FROM users WHERE is_active = 1 AND birthday IS NOT NULL AND DATE_FORMAT(birthday, '%m-%d') = ?",
            [$monthDay]
        )->getResultArray();

        $issued = 0; $skipped = 0;

        foreach ($users as $user) {
            // 내 테스트 데이터만 대상 (cleanup 목록 내)
            if (! in_array((int) $user['id'], $this->cleanup['users'])) continue;

            $alreadyToday = $db->table('user_coupons')
                ->where('user_id', (int) $user['id'])
                ->where('coupon_id', $couponId)
                ->where('DATE(issued_at)', $todayYmd)
                ->countAllResults();

            if ($alreadyToday > 0) { $skipped++; continue; }

            $db->table('user_coupons')->insert([
                'user_id'    => (int) $user['id'],
                'coupon_id'  => $couponId,
                'source'     => 'admin',
                'status'     => 'issued',
                'issued_at'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->cleanup['user_coupons'][] = (int) $db->insertID();
            $issued++;
        }

        return compact('issued', 'skipped');
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_birthday_coupon_issued_to_user_with_matching_birthday(): void
    {
        $today    = date('Y-m-d');
        $birthday = date('Y') . '-' . date('m-d');  // 올해 오늘 생일
        $userId   = $this->insertUser($birthday);
        $couponId = $this->insertCoupon();

        $result = $this->runBirthdayIssue($couponId, $today);

        $this->assertSame(1, $result['issued']);

        $issued = db_connect()->table('user_coupons')
            ->where('user_id', $userId)->where('coupon_id', $couponId)
            ->countAllResults();
        $this->assertSame(1, $issued);
    }

    public function test_birthday_coupon_not_issued_to_non_birthday_user(): void
    {
        $today      = date('Y-m-d');
        $yesterday  = date('Y-m-d', strtotime('-1 day'));
        $userId     = $this->insertUser($yesterday);
        $couponId   = $this->insertCoupon();

        $result = $this->runBirthdayIssue($couponId, $today);

        $this->assertSame(0, $result['issued']);
    }

    public function test_birthday_coupon_not_issued_twice_same_day(): void
    {
        $today    = date('Y-m-d');
        $birthday = date('Y') . '-' . date('m-d');
        $userId   = $this->insertUser($birthday);
        $couponId = $this->insertCoupon();

        $this->runBirthdayIssue($couponId, $today);
        $result = $this->runBirthdayIssue($couponId, $today);

        $this->assertSame(0, $result['issued']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_multiple_birthday_users_all_receive_coupon(): void
    {
        $today    = date('Y-m-d');
        $birthday = date('Y') . '-' . date('m-d');
        $u1       = $this->insertUser($birthday);
        $u2       = $this->insertUser($birthday);
        $couponId = $this->insertCoupon();

        $result = $this->runBirthdayIssue($couponId, $today);

        $this->assertSame(2, $result['issued']);
    }

    public function test_inactive_user_does_not_receive_birthday_coupon(): void
    {
        $today    = date('Y-m-d');
        $birthday = date('Y') . '-' . date('m-d');
        $couponId = $this->insertCoupon();

        // inactive user 삽입
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'bc_inactive_' . $uid,
            'email'         => 'bc-inactive-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'Inactive',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 0,
            'point_balance' => 0,
            'birthday'      => $birthday,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $inactiveId = (int) $db->insertID();
        $this->cleanup['users'][] = $inactiveId;

        $result = $this->runBirthdayIssue($couponId, $today);

        $this->assertSame(0, $result['issued']);

        $issued = $db->table('user_coupons')
            ->where('user_id', $inactiveId)->where('coupon_id', $couponId)
            ->countAllResults();
        $this->assertSame(0, $issued);
    }
}
