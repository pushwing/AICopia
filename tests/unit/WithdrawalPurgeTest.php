<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 보관기간 경과 개인정보 파기 검증
 *
 * 파기는 행 삭제가 아니라 개인정보 컬럼만 NULL 로 비우는 방식이다.
 * 탈퇴 사유·시점 통계는 남아야 한다.
 */
final class WithdrawalPurgeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private WithdrawalService $service;

    /** @var array<string, list<int>> */
    private array $cleanup = ['withdrawn_users' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WithdrawalService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['withdrawn_users', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['withdrawn_users' => [], 'users' => []];
        parent::tearDown();
    }

    /** 탈퇴한 지 $daysAgo 일 된 스냅샷 행을 직접 만든다 */
    private function insertSnapshot(int $daysAgo): int
    {
        $uid = 'WP' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('withdrawn_users')->insert([
            'user_id'      => 900000 + random_int(1, 99999),
            'username'     => $uid,
            'email'        => $uid . '@example.test',
            'nickname'     => $uid,
            'phone'        => '01098765432',
            'reason_text'  => '개인적인 사유입니다',
            'grade'        => 'gold',
            'reason_code'  => 'privacy',
            'withdrawn_by' => 'member',
            'withdrawn_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['withdrawn_users'][] = $id;

        return $id;
    }

    public function testExpiredSnapshotIsPurged(): void
    {
        $id = $this->insertSnapshot(40);

        $purged = $this->service->purgeExpired(30);
        $this->assertGreaterThanOrEqual(1, $purged);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['nickname']);
        $this->assertNull($row['reason_text']);
        $this->assertNotNull($row['purged_at']);
    }

    public function testStatsSurvivePurge(): void
    {
        $id = $this->insertSnapshot(40);
        $this->service->purgeExpired(30);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertSame('privacy', $row['reason_code'], '탈퇴 사유는 통계용이라 남아야 한다');
        $this->assertSame('gold', $row['grade']);
        $this->assertNotNull($row['withdrawn_at']);
    }

    public function testRecentSnapshotIsNotPurged(): void
    {
        $id = $this->insertSnapshot(5);
        $this->service->purgeExpired(30);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNotNull($row['email'], '보관기간 안이면 파기하지 않는다');
        $this->assertNull($row['purged_at']);
    }

    public function testPurgeIsIdempotent(): void
    {
        $id = $this->insertSnapshot(40);

        $this->service->purgeExpired(30);
        $first = new WithdrawnUserModel()->find($id)['purged_at'];

        $second = $this->service->purgeExpired(30);

        $this->assertSame(0, $second, '이미 파기된 행을 다시 세면 안 된다');
        $this->assertSame($first, new WithdrawnUserModel()->find($id)['purged_at']);
    }

    public function testRejoinWithSameEmailSucceeds(): void
    {
        $email = 'WP' . substr(uniqid(), -8) . '@example.test';
        $db    = db_connect();

        $db->table('users')->insert([
            'username'   => 'rejoin1',
            'email'      => $email,
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => 'rejoin1',
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $firstId = (int) $db->insertID();
        $this->cleanup['users'][] = $firstId;

        $this->service->withdraw($firstId, 'rejoin', null);
        $snap = $db->table('withdrawn_users')->where('user_id', $firstId)->get()->getRowArray();
        $this->cleanup['withdrawn_users'][] = (int) $snap['id'];

        // 같은 이메일로 재가입 — UNIQUE 충돌이 나면 안 된다
        $db->table('users')->insert([
            'username'   => 'rejoin2',
            'email'      => $email,
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => 'rejoin2',
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $secondId = (int) $db->insertID();
        $this->cleanup['users'][] = $secondId;

        $this->assertGreaterThan(0, $secondId);
        $this->assertNotSame($firstId, $secondId);
    }
}
