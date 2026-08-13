<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * /auth/profile (내 정보 수정) 탭 영역 렌더링 검증.
 *
 * 소셜 로그인 계정은 비밀번호가 없어 '비밀번호 변경' 탭이 숨겨진다. 다만 회원
 * 탈퇴 탭은 계정 종류와 무관하게 항상 존재하므로, 소셜 계정도 탭이 최소
 * 2개(기본 정보·회원 탈퇴)가 되어 탭 바는 항상 렌더링된다.
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

    public function testSocialAccountRendersTabBarWithoutPasswordTab(): void
    {
        // 탈퇴 탭이 생기면서 소셜 계정도 탭이 2개(기본 정보·회원 탈퇴)가 됐다.
        // 탭 바를 숨기던 예전 동작은 더 이상 맞지 않는다 — 비밀번호 탭만 없으면 된다.
        $html = view('auth/profile', $this->viewData('naver'));

        $this->assertStringContainsString('nav-tabs', $html);
        $this->assertStringContainsString('회원 탈퇴', $html);
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

    /**
     * 탈퇴 탭용 뷰 데이터 (AuthController::profile()이 tab=withdraw 일 때 추가하는 키 포함)
     *
     * @param list<string> $blockReasons
     *
     * @return array<string, mixed>
     */
    private function withdrawViewData(?string $socialProvider, array $blockReasons = []): array
    {
        $data = $this->viewData($socialProvider, 'withdraw');
        $data['withdrawal'] = ['allowed' => $blockReasons === [], 'reasons' => $blockReasons];
        $data['forfeit']    = ['point' => 5000, 'coupon' => 2];

        return $data;
    }

    public function testWithdrawTabShowsBlockReasonsInsteadOfForm(): void
    {
        $html = view('auth/profile', $this->withdrawViewData(null, ['진행 중인 주문이 1건 있습니다.']));

        $this->assertStringContainsString('진행 중인 주문이 1건 있습니다.', $html);
        $this->assertStringNotContainsString('action="/auth/withdraw"', $html, '차단 상태에서 폼을 그리면 안 된다');
    }

    public function testWithdrawTabShowsForfeitSummaryAndForm(): void
    {
        $html = view('auth/profile', $this->withdrawViewData(null));

        $this->assertStringContainsString('action="/auth/withdraw"', $html);
        $this->assertStringContainsString('5,000점', $html);
        $this->assertStringContainsString('2장', $html);
        $this->assertStringContainsString('csrf', $html);
        $this->assertStringContainsString('name="password"', $html, '일반 계정은 비밀번호로 본인 확인한다');
    }

    public function testWithdrawTabAsksConfirmTextForSocialAccount(): void
    {
        $html = view('auth/profile', $this->withdrawViewData('kakao'));

        // 소셜 계정은 비밀번호가 랜덤이라 검증할 수 없다
        $this->assertStringContainsString('name="confirm_text"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }
}
