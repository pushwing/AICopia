<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * /admin/settings/seo 뷰의 GA4/GTM/네이버 인증 필드 설명 렌더링 검증.
 *
 * GA4 측정 ID와 GTM 컨테이너 ID를 혼동하기 쉬워, 각 필드가 무엇이고 어디서
 * 발급받는지 설명(form-text)이 함께 노출되는지 확인한다.
 */
final class AdminSettingsSeoHintTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        return [
            // BaseController::initController()가 주입하는 전역 데이터
            'menus'           => [],
            'authUser'        => ['id' => 1, 'nickname' => '관리자', 'role' => 'admin', 'grade' => 'bronze', 'loggedIn' => true],
            'unreadInquiries' => 0,
            'unansweredQna'   => 0,
            'lowStockCount'   => 0,
            'pendingOrders'   => 0,
            'subLeftBanners'  => [],
            'activePopups'    => [],
            'cartCount'       => 0,
            'categories'      => [],
            // SettingController::index('seo')가 추가하는 페이지 전용 데이터
            'group'    => 'seo',
            'settings' => [
                ['group' => 'seo', 'key' => 'ga_id', 'value' => '', 'label' => 'GA 측정 ID', 'type' => 'text'],
                ['group' => 'seo', 'key' => 'gtm_id', 'value' => '', 'label' => 'GTM 컨테이너 ID', 'type' => 'text'],
                ['group' => 'seo', 'key' => 'naver_verify', 'value' => '', 'label' => '네이버 인증', 'type' => 'text'],
            ],
        ];
    }

    public function testSeoViewExplainsGa4MeasurementId(): void
    {
        $html = view('admin/settings/index', $this->viewData());

        $this->assertStringContainsString('데이터 스트림', $html);
        $this->assertStringContainsString('G-XXXXXXXXXX', $html);
    }

    public function testSeoViewExplainsGtmContainerIdIsSeparateFromGa4(): void
    {
        $html = view('admin/settings/index', $this->viewData());

        $this->assertStringContainsString('Google Tag Manager', $html);
        $this->assertStringContainsString('GTM-XXXXXXX', $html);
        $this->assertStringContainsString('tagmanager.google.com', $html);
    }

    public function testSeoViewExplainsNaverVerification(): void
    {
        $html = view('admin/settings/index', $this->viewData());

        $this->assertStringContainsString('서치어드바이저', $html);
    }

    public function testNonSeoFieldsHaveNoHintText(): void
    {
        $data                        = $this->viewData();
        $data['group']               = 'general';
        $data['settings']            = [
            ['group' => 'general', 'key' => 'site_name', 'value' => '내 회사', 'label' => '사이트명', 'type' => 'text'],
        ];

        $html = view('admin/settings/index', $data);

        $this->assertStringNotContainsString('form-text', $html);
    }
}
