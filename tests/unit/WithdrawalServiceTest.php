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
}
