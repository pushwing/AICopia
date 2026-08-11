<?php

declare(strict_types=1);

use App\Filters\AuthFilter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuthFilterTest extends CIUnitTestCase
{
    private AuthFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new AuthFilter();
        // 각 테스트 전 세션 초기화
        session()->destroy();
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
        session()->set(['user_id' => 1, 'user_role' => 'member']);

        $request = service('request');
        $result  = $this->filter->before($request, ['member']);

        $this->assertNull($result);
    }

    public function testBeforeReturnsNullWhenAdminAccessedByAdmin(): void
    {
        session()->set(['user_id' => 1, 'user_role' => 'admin']);

        $request = service('request');
        $result  = $this->filter->before($request, ['admin']);

        $this->assertNull($result);
    }

    public function testBeforeRedirectsWhenMemberAccessesAdminArea(): void
    {
        session()->set(['user_id' => 1, 'user_role' => 'member']);

        $request = service('request');
        $result  = $this->filter->before($request, ['admin']);

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testAfterAlwaysReturnsNull(): void
    {
        $request  = service('request');
        $response = service('response');

        $this->assertNull($this->filter->after($request, $response, null));
        $this->assertNull($this->filter->after($request, $response, ['admin']));
    }
}
