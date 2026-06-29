<?php

namespace Tests\Unit;

use App\Libraries\GradeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 회원 등급 자동 업그레이드 + 승급 쿠폰 발급 검증
 * 이슈 #② grades:upgrade / GradeService::checkAndUpgrade()
 */
final class GradeAutoUpgradeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'users'        => [],
        'orders'       => [],
        'point_logs'   => [],
        'user_coupons' => [],
        'coupons'      => [],
    ];

    private GradeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GradeService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ([
            'user_coupons' => 'id',
            'point_logs'   => 'id',
            'orders'       => 'id',
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

    private function insertUser(string $grade = 'bronze'): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'au_' . $uid,
            'email'         => 'au-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'AUUser',
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

    private function insertPaidOrder(int $userId, int $amount): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'AU-' . uniqid(),
            'status'                 => 'delivered',
            'total_product_price'    => $amount,
            'shipping_fee'           => 0,
            'total_amount'           => $amount,
            'payable_amount'         => $amount,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'receiver_name'          => 'Test',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시',
            'address2'               => '101호',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertCoupon(): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'name'          => 'AU쿠폰_' . uniqid(),
            'code'          => 'AU-' . strtoupper(uniqid()),
            'type'          => 'fixed',
            'discount_value'=> 1000,
            'min_order_amount'=> 0,
            'total_qty'     => null,
            'used_count'    => 0,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return $id;
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'grade_silver_orders' => 3,
            'grade_silver_amount' => 30000,
            'grade_gold_orders'   => 10,
            'grade_gold_amount'   => 100000,
            'point_bonus_silver'  => 500,
            'point_bonus_gold'    => 1000,
        ], $overrides);
    }

    // ── checkAndUpgrade 테스트 ─────────────────────────────────────────────────

    public function test_bronze_not_upgraded_when_below_threshold(): void
    {
        $userId = $this->insertUser('bronze');
        $this->insertPaidOrder($userId, 10000);

        $result = $this->service->checkAndUpgrade($userId, $this->settings());

        $this->assertNull($result);
        $row = db_connect()->table('users')->select('grade')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('bronze', $row['grade']);
    }

    public function test_bronze_upgraded_to_silver_when_threshold_met(): void
    {
        $userId = $this->insertUser('bronze');
        for ($i = 0; $i < 3; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $result = $this->service->checkAndUpgrade($userId, $this->settings());

        $this->assertSame('silver', $result);
        $row = db_connect()->table('users')->select('grade')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('silver', $row['grade']);
    }

    public function test_silver_upgraded_to_gold_when_threshold_met(): void
    {
        $userId = $this->insertUser('silver');
        for ($i = 0; $i < 10; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $result = $this->service->checkAndUpgrade($userId, $this->settings());

        $this->assertSame('gold', $result);
    }

    public function test_gold_not_upgraded_automatically(): void
    {
        $userId = $this->insertUser('gold');
        for ($i = 0; $i < 20; $i++) {
            $this->insertPaidOrder($userId, 100000);
        }

        $result = $this->service->checkAndUpgrade($userId, $this->settings());

        $this->assertNull($result);
    }

    public function test_upgrade_grants_bonus_points(): void
    {
        $userId = $this->insertUser('bronze');
        for ($i = 0; $i < 3; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $this->service->checkAndUpgrade($userId, $this->settings());

        $row = db_connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRowArray();
        $this->assertSame(500, (int) $row['point_balance']);

        $log = db_connect()->table('point_logs')->where('user_id', $userId)->get()->getRowArray();
        $this->cleanup['point_logs'][] = (int) $log['id'];
        $this->assertSame(500, (int) $log['amount']);
    }

    // ── issueGradeCoupon 테스트 ───────────────────────────────────────────────

    public function test_grade_coupon_issued_on_upgrade(): void
    {
        $userId   = $this->insertUser('bronze');
        $couponId = $this->insertCoupon();

        for ($i = 0; $i < 3; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $this->service->checkAndUpgrade($userId, $this->settings([
            'coupon_grade_silver_id' => $couponId,
        ]));

        $issued = db_connect()->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->countAllResults();

        $this->assertSame(1, $issued);

        // cleanup
        db_connect()->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $couponId)->delete();
    }

    public function test_grade_coupon_not_issued_twice(): void
    {
        $userId   = $this->insertUser('bronze');
        $couponId = $this->insertCoupon();
        $settings = $this->settings(['coupon_grade_silver_id' => $couponId]);

        for ($i = 0; $i < 3; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $this->service->checkAndUpgrade($userId, $settings);
        $this->service->issueGradeCoupon($userId, 'silver', $settings);

        $count = db_connect()->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->countAllResults();

        $this->assertSame(1, $count);

        db_connect()->table('user_coupons')->where('user_id', $userId)->where('coupon_id', $couponId)->delete();
    }

    public function test_grade_coupon_skipped_when_setting_empty(): void
    {
        $userId = $this->insertUser('bronze');
        for ($i = 0; $i < 3; $i++) {
            $this->insertPaidOrder($userId, 10001);
        }

        $this->service->checkAndUpgrade($userId, $this->settings());

        $count = db_connect()->table('user_coupons')->where('user_id', $userId)->countAllResults();
        $this->assertSame(0, $count);
    }
}
