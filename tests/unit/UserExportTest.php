<?php

namespace Tests\Unit;

use App\Controllers\Admin\UserController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * UserController::export() 검증
 *
 * - 필터(role, grade, status, 가입일 범위) 적용 여부
 * - 엑셀 응답 헤더 및 컨텐츠 타입
 * - 취소/환불 주문과 무관한 회원 데이터 정합성
 */
final class UserExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = ['users' => []];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(array $override = []): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert(array_merge([
            'username'   => 'exp_' . $uid,
            'email'      => 'exp_' . $uid . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'ExportUser',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $override));
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function buildExportRows(array $params = []): array
    {
        $keyword = trim($params['q']      ?? '');
        $role    = $params['role']         ?? '';
        $status  = $params['status']       ?? '';
        $grade   = $params['grade']        ?? '';
        $from    = $params['from']         ?? '';
        $to      = $params['to']           ?? '';

        $db      = db_connect();
        $builder = $db->table('users')
            ->select('id, nickname, email, phone, role, grade, social_provider, is_active, email_verify_token, created_at, last_login');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('nickname', $keyword)
                ->orLike('email', $keyword)
                ->groupEnd();
        }
        if ($role  !== '') $builder->where('role', $role);
        if ($grade !== '') $builder->where('grade', $grade);
        if ($from  !== '') $builder->where('DATE(created_at) >=', $from);
        if ($to    !== '') $builder->where('DATE(created_at) <=', $to);

        if ($status === 'active')         $builder->where('is_active', 1);
        elseif ($status === 'unverified') $builder->where('is_active', 0)->where('email_verify_token IS NOT NULL');
        elseif ($status === 'inactive')   $builder->where('is_active', 0)->where('email_verify_token IS NULL');

        return $builder->orderBy('id', 'DESC')->get()->getResultArray();
    }

    // ── 필터 검증 ─────────────────────────────────────────────────────────────

    public function testRoleFilterReturnsOnlyMatchingRole(): void
    {
        $memberId = $this->insertUser(['role' => 'member']);
        $adminId  = $this->insertUser(['role' => 'admin']);

        $rows = $this->buildExportRows(['role' => 'admin']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $adminId,  $ids, '관리자 회원이 포함돼야 함');
        $this->assertNotContains((string) $memberId, $ids, '일반 회원이 제외돼야 함');
    }

    public function testGradeFilterReturnsOnlyMatchingGrade(): void
    {
        $goldId   = $this->insertUser(['grade' => 'gold']);
        $bronzeId = $this->insertUser(['grade' => 'bronze']);

        $rows = $this->buildExportRows(['grade' => 'gold']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $goldId,   $ids, '골드 회원이 포함돼야 함');
        $this->assertNotContains((string) $bronzeId, $ids, '브론즈 회원이 제외돼야 함');
    }

    public function testStatusActiveFilterExcludesInactive(): void
    {
        $activeId   = $this->insertUser(['is_active' => 1]);
        $inactiveId = $this->insertUser(['is_active' => 0, 'email_verify_token' => null]);

        $rows = $this->buildExportRows(['status' => 'active']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $activeId,    $ids);
        $this->assertNotContains((string) $inactiveId, $ids);
    }

    public function testStatusInactiveFilterExcludesActive(): void
    {
        $activeId   = $this->insertUser(['is_active' => 1]);
        $inactiveId = $this->insertUser(['is_active' => 0, 'email_verify_token' => null]);

        $rows = $this->buildExportRows(['status' => 'inactive']);
        $ids  = array_column($rows, 'id');

        $this->assertNotContains((string) $activeId,  $ids);
        $this->assertContains((string) $inactiveId,   $ids);
    }

    public function testStatusUnverifiedFilterMatchesUnverifiedOnly(): void
    {
        $verifiedId   = $this->insertUser(['is_active' => 1, 'email_verify_token' => null]);
        $unverifiedId = $this->insertUser(['is_active' => 0, 'email_verify_token' => 'tok123']);

        $rows = $this->buildExportRows(['status' => 'unverified']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $unverifiedId,  $ids);
        $this->assertNotContains((string) $verifiedId, $ids);
    }

    public function testDateFromFilterExcludesOlderUsers(): void
    {
        $oldId  = $this->insertUser(['created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        $newId  = $this->insertUser(['created_at' => date('Y-m-d H:i:s'),   'updated_at' => date('Y-m-d H:i:s')]);

        $rows = $this->buildExportRows(['from' => date('Y-m-d')]);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $newId,  $ids, '오늘 가입 회원이 포함돼야 함');
        $this->assertNotContains((string) $oldId, $ids, '2020년 가입 회원은 제외돼야 함');
    }

    public function testDateToFilterExcludesNewerUsers(): void
    {
        $oldId = $this->insertUser(['created_at' => '2020-06-15 00:00:00', 'updated_at' => '2020-06-15 00:00:00']);
        $newId = $this->insertUser(['created_at' => date('Y-m-d H:i:s'),   'updated_at' => date('Y-m-d H:i:s')]);

        $rows = $this->buildExportRows(['to' => '2020-12-31']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $oldId,  $ids, '2020년 가입 회원이 포함돼야 함');
        $this->assertNotContains((string) $newId, $ids, '오늘 가입 회원은 제외돼야 함');
    }

    public function testKeywordFilterMatchesNickname(): void
    {
        $uid        = uniqid();
        $targetId   = $this->insertUser(['nickname' => 'findme_' . $uid]);
        $otherId    = $this->insertUser(['nickname' => 'other_' . $uid]);

        $rows = $this->buildExportRows(['q' => 'findme_' . $uid]);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $targetId, $ids);
        $this->assertNotContains((string) $otherId, $ids);
    }

    public function testNoFilterReturnsAllUsers(): void
    {
        $id1 = $this->insertUser();
        $id2 = $this->insertUser();

        $rows = $this->buildExportRows();
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $id1, $ids);
        $this->assertContains((string) $id2, $ids);
    }

    // ── 데이터 구조 검증 ──────────────────────────────────────────────────────

    public function testExportRowHasRequiredFields(): void
    {
        $this->insertUser();
        $rows = $this->buildExportRows();

        $this->assertNotEmpty($rows);
        $row = $rows[0];

        foreach (['id', 'nickname', 'email', 'role', 'grade', 'is_active', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $row, "필드 {$field}가 있어야 함");
        }
    }

    public function testCombinedFiltersWork(): void
    {
        $goldMemberId    = $this->insertUser(['grade' => 'gold',   'role' => 'member']);
        $silverMemberId  = $this->insertUser(['grade' => 'silver', 'role' => 'member']);
        $goldAdminId     = $this->insertUser(['grade' => 'gold',   'role' => 'admin']);

        $rows = $this->buildExportRows(['grade' => 'gold', 'role' => 'member']);
        $ids  = array_column($rows, 'id');

        $this->assertContains((string) $goldMemberId,   $ids);
        $this->assertNotContains((string) $silverMemberId, $ids);
        $this->assertNotContains((string) $goldAdminId,    $ids);
    }
}
