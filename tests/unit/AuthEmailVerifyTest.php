<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\AuthController;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * AuthController 이메일 인증 회귀 테스트
 *
 * MySQLi 드라이버는 조회 결과를 모두 문자열로 돌려준다. verifyByToken() /
 * findUnverified() 가 돌려준 문자열 id 를 int 파라미터로 선언된
 * UserModel::clearVerifyToken() · generateVerifyToken() 에 그대로 넘기면
 * strict_types 아래에서 TypeError 가 나 인증 링크 클릭·인증 메일 재발송이
 * 항상 500 이 된다.
 */
final class AuthEmailVerifyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<int, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'AEV' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->userIds !== []) {
            $db->table('point_logs')->whereIn('user_id', $this->userIds)->delete();
            $db->table('users')->whereIn('id', $this->userIds)->delete();
        }
        $this->userIds = [];

        service('request')->setGlobal('post', []);
        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** 인증 대기(is_active=0) 상태의 회원을 만들고 [id, email, token] 반환 */
    private function insertUnverifiedUser(string $tokenAt): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'              => $this->prefix,
            'email'                 => $this->email(),
            'password'              => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'              => $this->prefix,
            'role'                  => 'member',
            'is_active'             => 0,
            'email_verify_token'    => $this->token(),
            'email_verify_token_at' => $tokenAt,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
        $id              = (int) $db->insertID();
        $this->userIds[] = $id;

        return $id;
    }

    private function email(): string
    {
        return $this->prefix . '@example.test';
    }

    private function token(): string
    {
        return 'tok-' . $this->prefix;
    }

    private function controller(): AuthController
    {
        $controller = new AuthController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testVerifyEmailActivatesUserWithoutTypeError(): void
    {
        $userId = $this->insertUnverifiedUser(date('Y-m-d H:i:s'));

        $this->controller()->verifyEmail($this->token());

        $user = new UserModel()->find($userId);

        $this->assertNotNull($user, '인증 대상 회원이 사라졌다');
        $this->assertSame(1, (int) $user['is_active'], '이메일 인증 후에도 계정이 활성화되지 않았다');
        $this->assertNull($user['email_verify_token'], '인증 토큰이 초기화되지 않았다');
        $this->assertNull($user['email_verify_token_at'], '인증 토큰 발급시각이 초기화되지 않았다');
    }

    public function testVerifyEmailRedirectsWhenTokenIsUnknown(): void
    {
        $result = $this->controller()->verifyEmail('tok-does-not-exist-' . $this->prefix);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $result);
    }

    public function testResendVerificationIssuesNewTokenWithoutTypeError(): void
    {
        // 1분 이내 재발송 차단에 걸리지 않도록 토큰 발급시각을 과거로 둔다
        $userId   = $this->insertUnverifiedUser(date('Y-m-d H:i:s', time() - 600));
        $oldToken = $this->token();

        service('request')->setGlobal('post', ['email' => $this->email()]);

        $this->controller()->resendVerification();

        $user = new UserModel()->find($userId);

        $this->assertNotNull($user, '재발송 대상 회원이 사라졌다');
        $this->assertNotSame($oldToken, $user['email_verify_token'], '인증 토큰이 재발급되지 않았다');
        $this->assertNotEmpty($user['email_verify_token'], '인증 토큰이 비어 있다');
    }
}
