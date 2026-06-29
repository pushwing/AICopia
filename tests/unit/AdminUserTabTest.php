<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 회원 상세 탭 — tabOrders / tabPoints / tabCoupons 쿼리 검증
 * 이슈 #98
 */
final class AdminUserTabTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'users'       => [],
        'orders'      => [],
        'order_items' => [],
        'point_logs'  => [],
        'user_coupons'=> [],
        'coupons'     => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ([
            'user_coupons' => 'id',
            'order_items'  => 'order_id',
            'point_logs'   => 'id',
            'orders'       => 'id',
            'coupons'      => 'id',
            'users'        => 'id',
        ] as $table => $col) {
            $key = $table === 'order_items' ? 'order_items' : $table;
            if (! empty($this->cleanup[$key])) {
                if ($table === 'order_items') {
                    $db->table($table)->whereIn('order_id', $this->cleanup[$key])->delete();
                } else {
                    $db->table($table)->whereIn($col, $this->cleanup[$key])->delete();
                }
            }
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'ut_' . $uid,
            'email'         => 'ut-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'UTUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 1500,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'UT-' . uniqid(),
            'status'                 => 'paid',
            'total_product_price'    => 10000,
            'shipping_fee'           => 0,
            'total_amount'           => 10000,
            'payable_amount'         => 10000,
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
        $this->cleanup['orders'][]      = $id;
        $this->cleanup['order_items'][] = $id;
        return $id;
    }

    private function insertPointLog(int $userId, int $amount): int
    {
        $db = db_connect();
        $db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'earn',
            'amount'     => $amount,
            'note'       => 'UT 포인트',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['point_logs'][] = $id;
        return $id;
    }

    private function insertCoupon(): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'name'             => 'UT쿠폰_' . uniqid(),
            'code'             => 'UT-' . strtoupper(uniqid()),
            'type'             => 'fixed',
            'discount_value'   => 1000,
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

    private function issueUserCoupon(int $userId, int $couponId): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'status'     => 'issued',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;
        return $id;
    }

    // ── tabOrders 테스트 ──────────────────────────────────────────────────────

    public function test_tab_orders_returns_user_orders(): void
    {
        $userId = $this->insertUser();
        $this->insertOrder($userId);
        $this->insertOrder($userId);

        $db   = db_connect();
        $rows = $db->table('orders')
            ->select('id, order_number, status, total_amount, payable_amount, created_at')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(30)
            ->get()->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('order_number', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
    }

    public function test_tab_orders_does_not_return_other_users_orders(): void
    {
        $u1 = $this->insertUser();
        $u2 = $this->insertUser();
        $this->insertOrder($u1);
        $this->insertOrder($u2);

        $db   = db_connect();
        $rows = $db->table('orders')
            ->select('id')
            ->where('user_id', $u1)
            ->get()->getResultArray();

        $this->assertCount(1, $rows);
    }

    // ── tabPoints 테스트 ──────────────────────────────────────────────────────

    public function test_tab_points_returns_user_point_logs(): void
    {
        $userId = $this->insertUser();
        $this->insertPointLog($userId, 500);
        $this->insertPointLog($userId, 1000);

        $db   = db_connect();
        $rows = $db->table('point_logs')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('amount', $rows[0]);
    }

    public function test_tab_points_balance_from_users_table(): void
    {
        $userId = $this->insertUser();

        $row     = db_connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRowArray();
        $balance = $row ? (int) $row['point_balance'] : 0;

        $this->assertSame(1500, $balance);
    }

    // ── tabCoupons 테스트 ─────────────────────────────────────────────────────

    public function test_tab_coupons_returns_user_coupons(): void
    {
        $userId   = $this->insertUser();
        $couponId = $this->insertCoupon();
        $this->issueUserCoupon($userId, $couponId);

        $db   = db_connect();
        $rows = $db->table('user_coupons uc')
            ->select('uc.id, c.name, c.code, uc.status, uc.used_at, c.expires_at, uc.created_at')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.user_id', $userId)
            ->orderBy('uc.id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('code', $rows[0]);
        $this->assertSame('issued', $rows[0]['status']);
    }

    public function test_tab_coupons_does_not_return_other_users_coupons(): void
    {
        $u1       = $this->insertUser();
        $u2       = $this->insertUser();
        $couponId = $this->insertCoupon();
        $this->issueUserCoupon($u1, $couponId);

        $db   = db_connect();
        $rows = $db->table('user_coupons uc')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.user_id', $u2)
            ->get()->getResultArray();

        $this->assertCount(0, $rows);
    }
}
