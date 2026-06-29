<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 회원 상세 탭 AJAX 데이터 검증 (#98)
 *
 * - tabOrders: 주문 내역 반환 (30건 제한)
 * - tabPoints: 포인트 내역 + 잔액 반환
 * - tabCoupons: 보유 쿠폰 목록 반환
 */
final class MemberDetailTabsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'orders'      => [],
        'order_items' => [],
        'point_logs'  => [],
        'user_coupons'=> [],
        'coupons'     => [],
        'products'    => [],
        'users'       => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['orders'] !== []) {
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['point_logs'] !== []) {
            $db->table('point_logs')->whereIn('id', $this->cleanup['point_logs'])->delete();
        }
        if ($this->cleanup['user_coupons'] !== []) {
            $db->table('user_coupons')->whereIn('id', $this->cleanup['user_coupons'])->delete();
        }
        if ($this->cleanup['coupons'] !== []) {
            $db->table('coupons')->whereIn('id', $this->cleanup['coupons'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'tab_' . $uid,
            'email'         => 'tab_' . $uid . '@test.com',
            'password'      => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'      => 'TabUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'point_balance' => $pointBalance,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'           => 'TabProd_' . $uid,
            'slug'           => 'tab-prod-' . $uid,
            'price'          => 10000,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status = 'paid'): int
    {
        $db        = db_connect();
        $productId = $this->insertProduct();

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'TAB-' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'total_amount'           => 10000,
            'payable_amount'         => 10000,
            'shipping_fee'           => 0,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => 'TabProd',
            'product_price' => 10000,
            'cost_price'    => 0,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return $orderId;
    }

    private function insertPointLog(int $userId, string $type, int $amount): int
    {
        $db = db_connect();
        $db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => $type,
            'amount'     => $amount,
            'note'       => $type . ' 테스트',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['point_logs'][] = $id;
        return $id;
    }

    private function insertCoupon(string $name = '테스트 쿠폰'): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('coupons')->insert([
            'name'           => $name,
            'code'           => 'TEST-' . strtoupper($uid),
            'type'           => 'fixed',
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'is_active'      => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
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
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;
        return $id;
    }

    // tabOrders 쿼리 로직 (컨트롤러와 동일)
    private function queryTabOrders(int $userId): array
    {
        return db_connect()->table('orders')
            ->select('id, order_number, status, total_amount, payable_amount, created_at')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(30)
            ->get()->getResultArray();
    }

    // tabPoints 쿼리 로직
    private function queryTabPoints(int $userId): array
    {
        $rows = db_connect()->table('point_logs')
            ->select('type, amount, note, created_at')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();
        $row     = db_connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRowArray();
        $balance = $row ? (int) $row['point_balance'] : 0;
        return ['data' => $rows, 'balance' => $balance];
    }

    // tabCoupons 쿼리 로직
    private function queryTabCoupons(int $userId): array
    {
        return db_connect()->table('user_coupons uc')
            ->select('uc.id, c.name, c.code, uc.status, uc.used_at, c.expires_at, uc.created_at')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.user_id', $userId)
            ->orderBy('uc.id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();
    }

    // ── tabOrders ──────────────────────────────────────────────────────────────

    public function testTabOrdersReturnsOrdersForUser(): void
    {
        $userId = $this->insertUser();
        $this->insertOrder($userId, 'paid');
        $this->insertOrder($userId, 'delivered');

        $rows = $this->queryTabOrders($userId);

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('order_number', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
        $this->assertArrayHasKey('payable_amount', $rows[0]);
    }

    public function testTabOrdersReturnsEmptyForUserWithNoOrders(): void
    {
        $userId = $this->insertUser();

        $rows = $this->queryTabOrders($userId);

        $this->assertSame([], $rows);
    }

    public function testTabOrdersDoesNotReturnOtherUsersOrders(): void
    {
        $userA = $this->insertUser();
        $userB = $this->insertUser();
        $this->insertOrder($userA, 'paid');

        $rows = $this->queryTabOrders($userB);

        $this->assertSame([], $rows);
    }

    public function testTabOrdersLimitedTo30(): void
    {
        $userId = $this->insertUser();
        for ($i = 0; $i < 32; $i++) {
            $this->insertOrder($userId, 'paid');
        }

        $rows = $this->queryTabOrders($userId);

        $this->assertCount(30, $rows);
    }

    public function testTabOrdersOrderedByIdDesc(): void
    {
        $userId   = $this->insertUser();
        $firstId  = $this->insertOrder($userId, 'paid');
        $secondId = $this->insertOrder($userId, 'paid');

        $rows = $this->queryTabOrders($userId);

        $this->assertSame($secondId, (int) $rows[0]['id'], '최신 주문이 먼저 나와야 함');
    }

    // ── tabPoints ──────────────────────────────────────────────────────────────

    public function testTabPointsReturnsLogsAndBalance(): void
    {
        $userId = $this->insertUser(500);
        $this->insertPointLog($userId, 'earn', 500);
        $this->insertPointLog($userId, 'use', -500);

        $res = $this->queryTabPoints($userId);

        $this->assertCount(2, $res['data']);
        $this->assertSame(500, $res['balance']);
    }

    public function testTabPointsEmptyDataForUserWithNoLogs(): void
    {
        $userId = $this->insertUser(0);

        $res = $this->queryTabPoints($userId);

        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['balance']);
    }

    public function testTabPointsDoesNotReturnOtherUsersLogs(): void
    {
        $userA = $this->insertUser();
        $userB = $this->insertUser();
        $this->insertPointLog($userA, 'earn', 100);

        $res = $this->queryTabPoints($userB);

        $this->assertSame([], $res['data']);
    }

    public function testTabPointsLogContainsRequiredFields(): void
    {
        $userId = $this->insertUser(100);
        $this->insertPointLog($userId, 'earn', 100);

        $res  = $this->queryTabPoints($userId);
        $row  = $res['data'][0];

        $this->assertArrayHasKey('type', $row);
        $this->assertArrayHasKey('amount', $row);
        $this->assertArrayHasKey('note', $row);
        $this->assertArrayHasKey('created_at', $row);
    }

    // ── tabCoupons ─────────────────────────────────────────────────────────────

    public function testTabCouponsReturnsCouponsForUser(): void
    {
        $userId   = $this->insertUser();
        $couponId = $this->insertCoupon('10% 할인');
        $this->insertUserCoupon($userId, $couponId, 'issued');

        $rows = $this->queryTabCoupons($userId);

        $this->assertCount(1, $rows);
        $this->assertSame('10% 할인', $rows[0]['name']);
        $this->assertSame('issued', $rows[0]['status']);
    }

    public function testTabCouponsReturnsEmptyForUserWithNoCoupons(): void
    {
        $userId = $this->insertUser();

        $rows = $this->queryTabCoupons($userId);

        $this->assertSame([], $rows);
    }

    public function testTabCouponsUsedAndUnusedBothReturned(): void
    {
        $userId    = $this->insertUser();
        $couponId1 = $this->insertCoupon('쿠폰A');
        $couponId2 = $this->insertCoupon('쿠폰B');
        $this->insertUserCoupon($userId, $couponId1, 'issued');
        $this->insertUserCoupon($userId, $couponId2, 'used');

        $rows = $this->queryTabCoupons($userId);

        $this->assertCount(2, $rows);
        $statuses = array_column($rows, 'status');
        $this->assertContains('issued', $statuses);
        $this->assertContains('used', $statuses);
    }

    public function testTabCouponsDoesNotReturnOtherUsersCoupons(): void
    {
        $userA    = $this->insertUser();
        $userB    = $this->insertUser();
        $couponId = $this->insertCoupon();
        $this->insertUserCoupon($userA, $couponId);

        $rows = $this->queryTabCoupons($userB);

        $this->assertSame([], $rows);
    }
}
