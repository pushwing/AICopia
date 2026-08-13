<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\AuthController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 로그인이 필요 없는 화면(상품 목록 찜 버튼, 상단 로그인 버튼 등)에서
 * AuthFilter를 거치지 않고 곧장 /auth/login으로 이동한 경우에도
 * 로그인 후 원래 있던 페이지로 돌아가야 한다 (Referer 기반 fallback).
 */
final class AuthLoginRedirectTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    private function controllerWithReferer(?string $referer, ?string $host = null): AuthController
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, 'auth/login'),
            null,
            new UserAgent(),
        );

        if ($referer !== null) {
            $request->setHeader('Referer', $referer);
        }

        if ($host !== null) {
            $request->setGlobal('server', ['HTTP_HOST' => $host]);
        }

        $controller = new AuthController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    public function testLoginRemembersSameOriginRefererWhenNoFilterAlreadySetIt(): void
    {
        $shopListUrl = base_url('shop');

        $this->controllerWithReferer($shopListUrl)->login();

        $this->assertSame($shopListUrl, session()->getTempdata('redirect_url'));
    }

    public function testLoginDoesNotOverrideRedirectUrlAlreadySetByAuthFilter(): void
    {
        $protectedUrl = base_url('mypage/wishlist');
        session()->setTempdata('redirect_url', $protectedUrl, 300);

        $this->controllerWithReferer(base_url('shop'))->login();

        $this->assertSame($protectedUrl, session()->getTempdata('redirect_url'));
    }

    public function testLoginIgnoresCrossOriginReferer(): void
    {
        $this->controllerWithReferer('https://evil.example.com/phish')->login();

        $this->assertNull(session()->getTempdata('redirect_url'));
    }

    public function testLoginIgnoresRefererPointingBackToAuthPages(): void
    {
        $this->controllerWithReferer(base_url('auth/register'))->login();

        $this->assertNull(session()->getTempdata('redirect_url'));
    }

    public function testLoginLeavesRedirectUrlUnsetWhenNoReferer(): void
    {
        $this->controllerWithReferer(null)->login();

        $this->assertNull(session()->getTempdata('redirect_url'));
    }

    /**
     * app.baseURL(예: copia.test)과 실제 접속 호스트(예: localhost:8420)가
     * 다른 로컬 개발·포트포워딩 환경에서도 same-origin 판정이 base_url()이
     * 아니라 실제 요청 Host를 기준으로 이루어져야 한다.
     */
    public function testLoginUsesActualRequestHostNotConfiguredBaseUrlForSameOriginCheck(): void
    {
        $referer = 'http://localhost:8420/shop';

        $this->controllerWithReferer($referer, 'localhost:8420')->login();

        $this->assertSame($referer, session()->getTempdata('redirect_url'));
    }
}
