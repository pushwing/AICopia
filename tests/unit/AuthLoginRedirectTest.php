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
    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        session()->destroy();
        // Superglobals::setServerArray()는 실제 $_SERVER 전역을 통째로 바꿔치기한다
        // (CodeIgniter\Superglobals::setServerArray 내부에서 `$_SERVER = $array;`).
        // resetSingle()로 캐시된 서비스 인스턴스만 지우면 다음 인스턴스가 이미
        // 오염된 $_SERVER를 다시 읽어들이므로, 원본 $_SERVER 자체를 복원해야
        // 뒤따르는 테스트의 base_url()/current_url() 계산이 깨끗하게 유지된다.
        $_SERVER = $this->originalServer;
        \CodeIgniter\Config\Services::resetSingle('superglobals');
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
            // 전체 서버 배열을 통째로 교체하면 SCRIPT_NAME 등 SiteURI 계산에 쓰이는
            // 다른 키가 사라져 이후 요청 처리가 깨진다 — 기존 값에 HTTP_HOST만 덮어쓴다.
            $request->setGlobal('server', array_merge($request->getServer() ?? [], ['HTTP_HOST' => $host]));
        }

        $controller = new AuthController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    public function testLoginRemembersSameOriginRefererWhenNoFilterAlreadySetIt(): void
    {
        $shopListUrl = base_url('shop');

        $this->controllerWithReferer($shopListUrl)->login();

        $this->assertSame($shopListUrl, session()->getFlashdata('redirect_url'));
    }

    public function testLoginDoesNotOverrideRedirectUrlAlreadySetByAuthFilter(): void
    {
        $protectedUrl = base_url('mypage/wishlist');
        session()->setFlashdata('redirect_url', $protectedUrl);

        $this->controllerWithReferer(base_url('shop'))->login();

        $this->assertSame($protectedUrl, session()->getFlashdata('redirect_url'));
    }

    public function testLoginIgnoresCrossOriginReferer(): void
    {
        $this->controllerWithReferer('https://evil.example.com/phish')->login();

        $this->assertNull(session()->getFlashdata('redirect_url'));
    }

    public function testLoginIgnoresRefererPointingBackToAuthPages(): void
    {
        $this->controllerWithReferer(base_url('auth/register'))->login();

        $this->assertNull(session()->getFlashdata('redirect_url'));
    }

    public function testLoginLeavesRedirectUrlUnsetWhenNoReferer(): void
    {
        $this->controllerWithReferer(null)->login();

        $this->assertNull(session()->getFlashdata('redirect_url'));
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

        $this->assertSame($referer, session()->getFlashdata('redirect_url'));
    }

    /**
     * A 페이지에서 찜하기 → 로그인 취소 → B 페이지에서 다시 찜하기를 눌러도
     * 로그인 후 최신 요청인 B로 돌아가야 한다. tempdata(TTL) 방식이었을 때는
     * A가 "이미 저장된 값"으로 남아 B가 덮어쓰지 못했다 — flashdata는 로그인
     * 폼을 새로 그리는 이번 요청에서 갱신되므로 최신 값이 이긴다.
     *
     * (참고: 실제 다중 요청에 걸친 flashdata 만료 자체는 CI4 세션의 요청 경계
     * 기반 aging 메커니즘이라 단일 프로세스 단위 테스트로는 재현하기 어렵다.
     * 이 케이스는 개발 서버에서 curl로 세션 파일까지 직접 확인해 검증했다.)
     */
    public function testLoginUpdatesRedirectUrlToLatestRefererWhenPreviousAttemptWasAbandoned(): void
    {
        $firstReferer  = base_url('shop');
        $secondReferer = base_url('shop/tshirt');

        $this->controllerWithReferer($firstReferer)->login();
        $this->assertSame($firstReferer, session()->getFlashdata('redirect_url'));

        // 로그인 폼을 제출하지 않고 다른 페이지에서 다시 로그인 화면으로 들어온
        // 상황을 흉내내기 위해, 이전 flashdata를 지우고(= 실제로는 다음 요청
        // 경계에서 만료되는 것과 동등) 새 Referer로 다시 진입한다.
        session()->remove('redirect_url');
        $this->controllerWithReferer($secondReferer)->login();

        $this->assertSame($secondReferer, session()->getFlashdata('redirect_url'));
    }
}
