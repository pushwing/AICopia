<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\AuthController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * AuthController 이메일 인증 경로 회귀 테스트
 *
 * verifyEmail()·resendVerification() 모두 조회 결과의 users.id 를
 * (MySQLi → 문자열) 그대로 int 파라미터인 UserModel::clearVerifyToken() /
 * generateVerifyToken() 에 넘기고 있었다. strict_types 아래에서는 TypeError 라
 * 인증 링크 클릭과 인증 메일 재발송이 각각 500 이 된다.
 */
final class AuthEmailVerificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<int, int> */
    private array $userCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'AEV' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->userCleanup !== []) {
            $db->table('point_logs')->whereIn('user_id', $this->userCleanup)->delete();
            $db->table('users')->whereIn('id', $this->userCleanup)->delete();
        }
        $this->userCleanup = [];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** 인증 대기(is_active = 0) 상태의 회원을 만든다. */
    private function insertUnverifiedUser(string $token, string $tokenAt): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'              => $this->prefix,
            'email'                 => $this->prefix . '@example.test',
            'password'              => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'              => $this->prefix,
            'role'                  => 'member',
            'is_active'             => 0,
            'email_verify_token'    => $token,
            'email_verify_token_at' => $tokenAt,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
        $id                  = (int) $db->insertID();
        $this->userCleanup[] = $id;

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function findUser(int $id): ?array
    {
        return db_connect()->table('users')->where('id', $id)->get()->getRowArray();
    }

    private function controller(?string $postEmail = null): AuthController
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, 'auth/resend-verification'),
            null,
            new UserAgent(),
        );

        if ($postEmail !== null) {
            $request->setGlobal('post', ['email' => $postEmail]);
        }

        $controller = new AuthController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    // ── 인증 링크 클릭 ───────────────────────────────────────────────────────

    public function testVerifyEmailActivatesUser(): void
    {
        $token = $this->prefix . 'token';
        $id    = $this->insertUnverifiedUser($token, date('Y-m-d H:i:s'));

        $this->controller()->verifyEmail($token);

        $user = $this->findUser($id);
        $this->assertSame(1, (int) $user['is_active'], '인증 후에도 계정이 활성화되지 않았다');
        $this->assertNull($user['email_verify_token'], '인증 후에도 토큰이 남아 있다');
    }

    public function testVerifyEmailRejectsExpiredToken(): void
    {
        $token = $this->prefix . 'token';
        $id    = $this->insertUnverifiedUser($token, date('Y-m-d H:i:s', strtotime('-25 hours')));

        $this->controller()->verifyEmail($token);

        $user = $this->findUser($id);
        $this->assertSame(0, (int) $user['is_active'], '만료된 토큰으로 계정이 활성화됐다');
    }

    // ── 인증 메일 재발송 ─────────────────────────────────────────────────────

    public function testResendVerificationIssuesNewToken(): void
    {
        $token = $this->prefix . 'token';
        $id    = $this->insertUnverifiedUser($token, date('Y-m-d H:i:s', strtotime('-10 minutes')));

        $this->controller($this->prefix . '@example.test')->resendVerification();

        $user = $this->findUser($id);
        $this->assertNotSame($token, $user['email_verify_token'], '재발송했는데 토큰이 갱신되지 않았다');
        $this->assertNotEmpty($user['email_verify_token'], '재발송 후 토큰이 비어 있다');
    }

    public function testResendVerificationIsRateLimitedWithinOneMinute(): void
    {
        $token = $this->prefix . 'token';
        $id    = $this->insertUnverifiedUser($token, date('Y-m-d H:i:s'));

        $this->controller($this->prefix . '@example.test')->resendVerification();

        $user = $this->findUser($id);
        $this->assertSame($token, $user['email_verify_token'], '1분 내 재발송이 차단되지 않았다');
    }
}
