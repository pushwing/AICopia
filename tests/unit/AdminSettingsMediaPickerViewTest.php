<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * /admin/settings/general (기본탭) 로고·파비콘 필드 — 이슈 #279
 *
 * 기존에는 미디어 라이브러리에서 경로를 복사해 텍스트 입력창에 붙여넣는 방식뿐이었다.
 * 이제 (1) 미디어 라이브러리에서 선택, (2) 그 자리에서 바로 업로드 두 방식을 모두 제공해야 한다.
 */
final class AdminSettingsMediaPickerViewTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function viewData(): array
    {
        return [
            'settings'        => ['site_name' => '테스트몰'],
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
            'group'    => 'general',
        ];
    }

    /** @return array<string, mixed> */
    private function generalGroupData(): array
    {
        return array_merge($this->viewData(), [
            'settings' => [
                ['group' => 'general', 'key' => 'site_name', 'value' => '테스트몰', 'label' => '사이트명', 'type' => 'text'],
                ['group' => 'general', 'key' => 'site_logo', 'value' => 'uploads/media/2026/08/logo.png', 'label' => '로고 이미지', 'type' => 'image'],
                ['group' => 'general', 'key' => 'favicon',   'value' => '',                               'label' => '파비콘',       'type' => 'image'],
            ],
        ]);
    }

    public function testImageFieldKeepsHiddenInputWithSettingKeyName(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        // saveSettings()가 그대로 동작하려면 name 속성은 유지되어야 한다(text → hidden 전환).
        $this->assertMatchesRegularExpression('/<input[^>]*name="site_logo"[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="favicon"[^>]*>/', $html);
    }

    public function testImageFieldNoLongerRendersManualTextInput(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertStringNotContainsString('미디어 라이브러리에서 이미지 경로를 복사하세요.', $html);
    }

    public function testImageFieldRendersMediaLibraryPickerButton(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertStringContainsString('media-picker-open-btn', $html);
        $this->assertStringContainsString('미디어에서 선택', $html);
    }

    public function testImageFieldRendersDirectUploadInput(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertStringContainsString('media-picker-upload-input', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*accept="image\/\*"/', $html);
    }

    public function testRendersMediaPickerModalOnce(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertSame(1, substr_count($html, 'id="mediaPickerModal"'));
    }

    public function testExistingLogoValuePrefillsPreviewImage(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertStringContainsString('src="/uploads/media/2026/08/logo.png"', $html);
    }

    public function testScriptReferencesPickerAndUploadRoutes(): void
    {
        $html = view('admin/settings/index', $this->generalGroupData());

        $this->assertStringContainsString('/admin/media/picker', $html);
        $this->assertStringContainsString('/admin/media/upload', $html);
    }
}
