<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 회원탈퇴 서비스 — 차단 판정과 탈퇴 실행 검증
 *
 * 테스트는 트랜잭션 롤백이 아니라 실제 커밋 + tearDown 수동 정리를 쓴다
 * (ParaTest worker 별 DB 분리 전제 — .claude/rules/testing.md 참고).
 */
final class WithdrawalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private WithdrawalService $service;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'withdrawn_users' => [],
        'point_logs'      => [],
        'orders'          => [],
        'users'           => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WithdrawalService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['withdrawn_users', 'point_logs', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['withdrawn_users' => [], 'point_logs' => [], 'orders' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function insertUser(array $extra = []): int
    {
        $uid = 'WD' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert(array_merge([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'grade'      => 'bronze',
            'phone'      => '01012345678',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $status): int
    {
        // receiver_name·receiver_phone·zipcode·address1 은 NOT NULL 에 기본값이 없다 — 반드시 채운다
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'        => $userId,
            'order_number'   => 'WD' . strtoupper(substr(uniqid(), -10)),
            'status'         => $status,
            'total_amount'   => 10000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'zipcode'        => '06134',
            'address1'       => '서울시 강남구 테헤란로',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function reload(int $userId): array
    {
        return (array) new UserModel()->find($userId);
    }

    /** 탈퇴로 생성된 스냅샷·포인트 로그를 tearDown 정리 목록에 등록 */
    private function trackSnapshot(int $userId): void
    {
        $db  = db_connect();
        $row = $db->table('withdrawn_users')->where('user_id', $userId)->get()->getRowArray();
        if ($row !== null) {
            $this->cleanup['withdrawn_users'][] = (int) $row['id'];
        }
        foreach ($db->table('point_logs')->where('user_id', $userId)->get()->getResultArray() as $log) {
            $this->cleanup['point_logs'][] = (int) $log['id'];
        }
    }

    // ── canWithdraw() ────────────────────────────────────────────────────────

    public function testAdminCannotWithdraw(): void
    {
        $id     = $this->insertUser(['role' => 'admin']);
        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('관리자', implode(' ', $result['reasons']));
    }

    public function testMemberWithNoOrdersCanWithdraw(): void
    {
        $id     = $this->insertUser();
        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testInProgressOrderBlocksWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'shipped');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('진행 중인 주문', implode(' ', $result['reasons']));
    }

    public function testReturnRequestBlocksWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'return_requested');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('반품', implode(' ', $result['reasons']));
    }

    public function testDeliveredOrderDoesNotBlockWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'delivered');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertTrue($result['allowed']);
    }

    // ── withdraw() ───────────────────────────────────────────────────────────

    public function testWithdrawSnapshotsPersonalDataAndMasksUser(): void
    {
        $id       = $this->insertUser();
        $original = $this->reload($id);

        $this->service->withdraw($id, 'unused', '자주 안 써서요');

        // 스냅샷에 원본 개인정보가 그대로 있다
        $snapshot = new \App\Models\WithdrawnUserModel()->findByUserId($id);
        $this->assertNotNull($snapshot);
        $this->cleanup['withdrawn_users'][] = (int) $snapshot['id'];
        $this->assertSame($original['email'], $snapshot['email']);
        $this->assertSame('01012345678', $snapshot['phone']);
        $this->assertSame('bronze', $snapshot['grade']);
        $this->assertSame('unused', $snapshot['reason_code']);
        $this->assertSame('자주 안 써서요', $snapshot['reason_text']);
        $this->assertSame('member', $snapshot['withdrawn_by']);
        $this->assertNotNull($snapshot['withdrawn_at']);
        $this->assertNull($snapshot['purged_at']);

        // users 행은 남아 있고 마스킹돼 있다
        $masked = $this->reload($id);
        $this->assertNotSame([], $masked);
        $this->assertSame("withdrawn_{$id}@deleted.local", $masked['email']);
        $this->assertSame("withdrawn_{$id}", $masked['username']);
        $this->assertSame('탈퇴회원', $masked['nickname']);
        $this->assertNull($masked['phone']);
        $this->assertNull($masked['social_provider']);
        $this->assertNull($masked['social_id']);
        $this->assertSame(0, (int) $masked['is_active']);
        $this->assertNotNull($masked['withdrawn_at']);
    }

    public function testWithdrawnUserCannotLogIn(): void
    {
        $id       = $this->insertUser();
        $original = $this->reload($id);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $this->assertNull(new UserModel()->findByEmail($original['email']));
    }

    public function testWithdrawnSocialUserCannotLogIn(): void
    {
        $id = $this->insertUser(['social_provider' => 'google', 'social_id' => 'g-' . uniqid()]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $found = db_connect()->table('users')
            ->where('social_provider', 'google')
            ->where('id', $id)
            ->get()->getRowArray();
        $this->assertNull($found);
    }

    public function testWithdrawWipesPasswordSoVerifyFails(): void
    {
        $id = $this->insertUser();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $masked = $this->reload($id);
        $this->assertFalse(password_verify('pw1234', $masked['password']));
    }

    public function testWithdrawForfeitsPointsAndLogsIt(): void
    {
        $id = $this->insertUser(['point_balance' => 5000]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $masked = $this->reload($id);
        $this->assertSame(0, (int) $masked['point_balance']);

        $log = db_connect()->table('point_logs')
            ->where('user_id', $id)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->cleanup['point_logs'][] = (int) $log['id'];
        $this->assertSame(-5000, (int) $log['amount']);
        $this->assertSame('admin', $log['type']);
    }

    public function testWithdrawIsBlockedWhenOrderInProgress(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'paid');

        $this->expectException(\App\Exceptions\WithdrawalBlockedException::class);
        $this->service->withdraw($id, 'etc', null);
    }

    public function testWithdrawClearsCartWishlistAndAddresses(): void
    {
        $id = $this->insertUser();
        $db = db_connect();

        $db->table('wishlists')->insert([
            'user_id'    => $id,
            'product_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('shipping_addresses')->insert([
            'user_id'        => $id,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'zipcode'        => '06134',
            'address1'       => '서울시 강남구',
            'address2'       => '101호',
            'is_default'     => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $this->assertSame(0, $db->table('wishlists')->where('user_id', $id)->countAllResults());
        $this->assertSame(0, $db->table('shipping_addresses')->where('user_id', $id)->countAllResults());
        $this->assertSame(0, $db->table('cart_items')->where('user_id', $id)->countAllResults());
    }

    public function testWithdrawExpiresUnusedCouponsWithoutDeletingRows(): void
    {
        $id = $this->insertUser();
        $db = db_connect();

        $db->table('coupons')->insert([
            'code'             => 'WDC-' . strtoupper(substr(uniqid(), -8)),
            'name'             => '탈퇴테스트쿠폰',
            'type'             => 'fixed',
            'discount_value'   => 3000,
            'min_order_amount' => 0,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $couponId = (int) $db->insertID();

        $db->table('user_coupons')->insert([
            'user_id'    => $id,
            'coupon_id'  => $couponId,
            'status'     => 'issued',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $userCouponId = (int) $db->insertID();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();

        // 행을 지우면 uniq(user_id, coupon_id) 가 지탱하는 재발급 이력이 깨진다
        $this->assertNotNull($uc, '쿠폰 행은 삭제하지 않고 상태만 바꿔야 한다');
        $this->assertSame('expired', $uc['status']);

        $db->table('user_coupons')->where('id', $userCouponId)->delete();
        $db->table('coupons')->where('id', $couponId)->delete();
    }

    public function testOrderReferenceSurvivesWithdrawal(): void
    {
        $id      = $this->insertUser();
        $orderId = $this->insertOrder($id, 'delivered');

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $row = db_connect()->table('orders')
            ->select('orders.id, users.nickname')
            ->join('users', 'users.id = orders.user_id')
            ->where('orders.id', $orderId)
            ->get()->getRowArray();

        $this->assertNotNull($row, '탈퇴 후에도 주문→회원 조인이 살아 있어야 한다');
        $this->assertSame('탈퇴회원', $row['nickname']);
    }

    public function testSecondWithdrawIsNoOp(): void
    {
        $id = $this->insertUser();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);
        $this->service->withdraw($id, 'etc', null);   // 예외 없이 조용히 통과

        $count = db_connect()->table('withdrawn_users')->where('user_id', $id)->countAllResults();
        $this->assertSame(1, $count, '중복 탈퇴로 스냅샷이 두 번 쌓이면 안 된다');
    }
}
