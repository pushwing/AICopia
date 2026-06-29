<?php

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
