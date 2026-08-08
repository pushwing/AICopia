<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\ForcePasswordChangeFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 기본 관리자 계정 보호 검증 (이슈 #119)
 *
 * 시드 마이그레이션이 고정 비밀번호로 admin 계정을 무조건 만들었고, 그 비밀번호는
 * 저장소 문서에 평문으로 적혀 있었다. 코드를 고쳐도 이미 설치된 인스턴스에는
 * 계정이 그대로 남으므로, 두 갈래로 막는다.
 *
 *  1) 새 설치 — 시드가 랜덤 비밀번호를 쓰고 즉시 변경을 강제한다
 *  2) 기존 설치 — 기본 비밀번호를 아직 쓰는 계정을 찾아 변경을 강제한다
 */
final class DefaultAdminCredentialTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        if ($this->cleanup !== []) {
            db_connect()->table('users')->whereIn('id', $this->cleanup)->delete();
            $this->cleanup = [];
        }
        session()->destroy();
        parent::tearDown();
    }

    private function insertUser(string $role = 'admin', int $mustChange = 0): int
    {
        $uid = 'DAC' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'             => $uid,
            'email'                => $uid . '@example.test',
            'password'             => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'             => $uid,
            'role'                 => $role,
            'must_change_password' => $mustChange,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;

        return $id;
    }

    private function runFilter(string $uri = '/admin'): mixed
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, ltrim($uri, '/')),
            null,
            new UserAgent(),
        );

        return new ForcePasswordChangeFilter()->before($request);
    }

    // ── 비밀번호 변경 강제 ────────────────────────────────────────────────────

    public function testUserFlaggedForChangeIsRedirected(): void
    {
        $id = $this->insertUser('admin', 1);
        session()->set(['user_id' => $id, 'user_role' => 'admin', 'must_change_password' => true]);

        $result = $this->runFilter('/admin');

        $this->assertInstanceOf(
            RedirectResponse::class,
            $result,
            '기본 비밀번호를 쓰는 계정이 그대로 통과했다',
        );
    }

    public function testUserWithoutFlagPassesThrough(): void
    {
        $id = $this->insertUser('admin', 0);
        session()->set(['user_id' => $id, 'user_role' => 'admin', 'must_change_password' => false]);

        $this->assertNull($this->runFilter('/admin'), '정상 계정이 막혔다');
    }

    public function testGuestIsNotAffected(): void
    {
        $this->assertNull($this->runFilter('/admin'), '비로그인 사용자는 이 필터가 관여하지 않는다');
    }

    public function testPasswordChangePageItselfIsNotBlocked(): void
    {
        // 변경하러 가는 길까지 막으면 무한 리다이렉트가 된다
        $id = $this->insertUser('admin', 1);
        session()->set(['user_id' => $id, 'user_role' => 'admin', 'must_change_password' => true]);

        $this->assertNull($this->runFilter('/auth/profile'), '비밀번호 변경 페이지가 막혀 무한 루프가 된다');
        $this->assertNull($this->runFilter('/auth/logout'), '로그아웃까지 막히면 갇힌다');
    }

    // ── 플래그 해제 ───────────────────────────────────────────────────────────

    public function testChangingPasswordClearsTheFlag(): void
    {
        $id = $this->insertUser('admin', 1);
        $db = db_connect();

        // AuthController 의 비밀번호 변경이 수행해야 하는 동작
        (new \App\Models\UserModel())->update($id, [
            'password'             => password_hash('brand-new-password', PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ]);

        $row = $db->table('users')->where('id', $id)->get()->getRowArray();
        $this->assertSame(0, (int) $row['must_change_password'], '변경 후에도 플래그가 남아 있다');
    }

    public function testMustChangePasswordIsWritableThroughModel(): void
    {
        // $allowedFields 에 빠져 있으면 위 해제가 조용히 실패한다
        $this->assertContains('must_change_password', (new \App\Models\UserModel())->allowedFields);
    }
}
