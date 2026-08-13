<?php

declare(strict_types=1);

use App\Filters\AuthFilter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AuthFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private AuthFilter $filter;

    /** @var list<int> */
    private array $cleanupUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new AuthFilter();
        // 각 테스트 전 세션 초기화
        session()->destroy();
    }

    protected function tearDown(): void
    {
        if ($this->cleanupUsers !== []) {
            db_connect()->table('users')->whereIn('id', $this->cleanupUsers)->delete();
            $this->cleanupUsers = [];
        }
        parent::tearDown();
    }

    private function insertUser(bool $withdrawn = false): int
    {
        $uid = 'AF' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'     => $uid,
            'email'        => $uid . '@example.test',
            'password'     => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'     => $uid,
            'role'         => 'member',
            'is_active'    => 1,
            'withdrawn_at' => $withdrawn ? date('Y-m-d H:i:s') : null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanupUsers[] = $id;

        return $id;
    }

    public function testBeforeRedirectsWhenNotLoggedIn(): void
    {
        $request = service('request');
        $result  = $this->filter->before($request, ['member']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    /**
     * 이니시스 결제 레이어(iframe) 안에서 order/fail 같은 auth:member 경로가
     * 로드되면 SameSite=Lax 세션 쿠키가 안 실려 로그인 안 한 것처럼 보인다.
     * 로그인 화면으로 튕기는 대신, 최상위 창을 재요청시키는 탈출 페이지를 줘야 한다.
     */
    public function testBeforeReturnsBridgePageWhenNotLoggedInAndFramed(): void
    {
        $request = service('request');
        $request->setHeader('Sec-Fetch-Dest', 'iframe');

        $result = $this->filter->before($request, ['member']);

        $this->assertNotInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('location.href', (string) $result->getBody());
    }

    public function testBeforeReturnsNullWhenMemberLoggedIn(): void
    {
        $userId = $this->insertUser();
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $request = service('request');
        $result  = $this->filter->before($request, ['member']);

        $this->assertNull($result);
    }

    public function testBeforeReturnsNullWhenAdminAccessedByAdmin(): void
    {
        $userId = $this->insertUser();
        session()->set(['user_id' => $userId, 'user_role' => 'admin']);

        $request = service('request');
        $result  = $this->filter->before($request, ['admin']);

        $this->assertNull($result);
    }

    public function testBeforeRedirectsWhenMemberAccessesAdminArea(): void
    {
        $userId = $this->insertUser();
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $request = service('request');
        $result  = $this->filter->before($request, ['admin']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    /**
     * 관리자가 강제 탈퇴시킨 회원의 세션이 살아있으면 안 된다(최종 리뷰 결함 #4).
     * withdrawn_at 이 채워진 회원은 세션이 있어도 즉시 로그인 화면으로 튕겨야 한다.
     *
     * CI4 의 Session::destroy() 는 ENVIRONMENT==='testing' 에서 no-op 이라(세션 관련
     * 부작용을 테스트에서 재현하기 어렵게 만드는 프레임워크 제약) $_SESSION 이 비었는지는
     * 직접 검증할 수 없다 — 대신 로그인 화면으로의 리다이렉트와 에러 플래시 메시지로
     * "거부됐다"는 관찰 가능한 결과를 검증한다.
     */
    public function testBeforeDestroysSessionAndRedirectsWhenUserIsWithdrawn(): void
    {
        $userId = $this->insertUser(withdrawn: true);
        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $request = service('request');
        $result  = $this->filter->before($request, ['member']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('/auth/login', $result->getHeaderLine('Location'));
        $this->assertSame('탈퇴한 계정입니다.', session()->getFlashdata('error'));
    }

    public function testAfterAlwaysReturnsNull(): void
    {
        $request  = service('request');
        $response = service('response');

        $this->assertNull($this->filter->after($request, $response, null));
        $this->assertNull($this->filter->after($request, $response, ['admin']));
    }
}
