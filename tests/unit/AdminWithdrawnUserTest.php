<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\UserModel;
use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 관리자 화면에서 탈퇴회원(tombstone)이 일반 회원 목록에 섞이지 않는지 검증
 *
 * 이 저장소는 컨트롤러를 HTTP 로 구동하는 feature 테스트를 쓰지 않는다.
 * 그래서 필터를 컨트롤러에 인라인으로 두면 검증할 방법이 없다 —
 * UserModel::activeBuilder() 로 추출하고 그 메서드를 테스트한다.
 */
final class AdminWithdrawnUserTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<string, list<int>> */
    private array $cleanup = ['withdrawn_users' => [], 'users' => []];

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

    private function insertUser(): int
    {
        $uid = 'AW' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function withdraw(int $userId): void
    {
        new WithdrawalService()->withdraw($userId, 'etc', null);
        $row = db_connect()->table('withdrawn_users')->where('user_id', $userId)->get()->getRowArray();
        $this->cleanup['withdrawn_users'][] = (int) $row['id'];
    }

    public function testActiveBuilderExcludesWithdrawnUser(): void
    {
        $keptId = $this->insertUser();
        $goneId = $this->insertUser();
        $this->withdraw($goneId);

        $ids = array_map(
            'intval',
            array_column(
                new UserModel()->activeBuilder()->select('id')->get()->getResultArray(),
                'id'
            )
        );

        $this->assertContains($keptId, $ids);
        $this->assertNotContains($goneId, $ids, '탈퇴회원 tombstone 이 일반 회원 목록에 섞이면 안 된다');
    }

    public function testWithdrawnListReturnsSnapshot(): void
    {
        $id = $this->insertUser();
        $this->withdraw($id);

        $result = new WithdrawnUserModel()->paginateList('', 1, 20);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $userIds = array_column($result['rows'], 'user_id');
        $this->assertContains($id, array_map('intval', $userIds));
    }
}
