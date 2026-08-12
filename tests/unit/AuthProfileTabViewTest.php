<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * /auth/profile (내 정보 수정) 탭 영역 렌더링 검증.
 *
 * 소셜 로그인 계정은 비밀번호가 없어 '비밀번호 변경' 탭이 숨겨지는데, 그 결과
 * nav-tabs 안에 탭이 하나만 남아 카드와 떨어진 빈 상자처럼 보였다(깨진 것처럼 보이는 원인).
 * 탭이 하나뿐이면 탭 바 자체를 렌더링하지 않는다.
 *
 * 함께: 소셜 계정이 /auth/profile?tab=password 로 직접 들어오면 쓸 수 없는
 * 비밀번호 변경 폼이 그려지고 탭도 전부 비활성으로 남았다 — 기본 정보로 되돌린다.
 */
final class AuthProfileTabViewTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function viewData(?string $socialProvider, string $activeTab = 'info'): array
    {
        return [
            // BaseController::initController()가 주입하는 전역 데이터
            'settings'        => ['site_name' => '테스트몰'],
            'menus'           => [],
            'authUser'        => ['id' => 1, 'nickname' => '홍길동', 'role' => 'member', 'grade' => 'bronze', 'loggedIn' => true],
            'unreadInquiries' => 0,
            'unansweredQna'   => 0,
            'lowStockCount'   => 0,
            'pendingOrders'   => 0,
            'subLeftBanners'  => [],
            'activePopups'    => [],
            'cartCount'       => 0,
            'categories'      => [],
            // AuthController::profile()이 추가하는 페이지 전용 데이터
            'activeTab'       => $activeTab,
            'user'            => [
                'id'              => 1,
                'email'           => 'tester@example.test',
                'nickname'        => '홍길동',
                'phone'           => '010-1234-5678',
                'gender'          => null,
                'birthday'        => null,
                'grade'           => 'bronze',
                'social_provider' => $socialProvider,
                'created_at'      => '2026-01-02 03:04:05',
                'last_login'      => null,
            ],
        ];
    }

    public function testSocialAccountDoesNotRenderLoneTabBar(): void
    {
        // 탭이 '기본 정보' 하나뿐이면 탭 바는 정보를 주지 못하고 깨진 상자처럼 보인다
        $html = view('auth/profile', $this->viewData('naver'));

        $this->assertStringNotContainsString('nav-tabs', $html, '탭이 하나뿐인데 탭 바가 렌더링됐다');
        $this->assertStringNotContainsString('비밀번호 변경', $html);
    }

    public function testLocalAccountRendersBothTabs(): void
    {
        $html = view('auth/profile', $this->viewData(null));

        $this->assertStringContainsString('nav-tabs', $html);
        $this->assertStringContainsString('기본 정보', $html);
        $this->assertStringContainsString('비밀번호 변경', $html);
    }

    public function testSocialAccountFallsBackToInfoTabOnPasswordTabRequest(): void
    {
        // 소셜 계정은 비밀번호가 없다 — ?tab=password 로 직접 들어와도 폼을 주면 안 된다
        $html = view('auth/profile', $this->viewData('naver', 'password'));

        $this->assertStringNotContainsString('name="current_password"', $html);
        $this->assertStringContainsString('name="nickname"', $html);
    }

    public function testLocalAccountStillRendersPasswordForm(): void
    {
        $html = view('auth/profile', $this->viewData(null, 'password'));

        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('name="new_password"', $html);
    }
}
