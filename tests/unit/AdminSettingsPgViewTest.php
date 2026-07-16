<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * /admin/settings/pg (결제수단 설정) 뷰 렌더링 검증 — 이슈 #73
 *
 * app/Views/admin/settings/pg.php 파일이 없어
 * SettingController::index('pg') 호출 시 ViewException("Invalid file")이 발생하던 문제.
 */
final class AdminSettingsPgViewTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $pgList = [
            'toss'          => ['label' => '토스페이먼츠', 'desc' => '신용카드·간편결제', 'env' => ['TOSS_CLIENT_KEY', 'TOSS_SECRET_KEY']],
            'bank_transfer' => ['label' => '무통장입금', 'desc' => '계좌이체 확인 후 처리', 'env' => []],
        ];

        $bankSettings = [
            ['group' => 'shop', 'key' => 'bank_name', 'value' => '', 'label' => '무통장 은행명', 'type' => 'text'],
            ['group' => 'shop', 'key' => 'bank_account', 'value' => '', 'label' => '무통장 계좌번호', 'type' => 'text'],
            ['group' => 'shop', 'key' => 'bank_holder', 'value' => '', 'label' => '무통장 예금주', 'type' => 'text'],
        ];

        return [
            // BaseController::initController()가 주입하는 전역 데이터
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
            // SettingController::index('pg')가 추가하는 페이지 전용 데이터
            'group'           => 'pg',
            'pgList'          => $pgList,
            'bankSettings'    => $bankSettings,
        ];
    }

    public function testPgSettingsViewRendersWithoutError(): void
    {
        $html = view('admin/settings/pg', $this->viewData());

        $this->assertStringContainsString('결제수단', $html);
    }

    public function testPgSettingsViewListsEachPaymentProviderToggle(): void
    {
        $html = view('admin/settings/pg', $this->viewData());

        $this->assertStringContainsString('토스페이먼츠', $html);
        $this->assertStringContainsString('name="pg_enabled_toss"', $html);
        $this->assertStringContainsString('무통장입금', $html);
        $this->assertStringContainsString('name="pg_enabled_bank_transfer"', $html);
    }

    public function testPgSettingsViewRendersBankTransferFields(): void
    {
        $html = view('admin/settings/pg', $this->viewData());

        $this->assertStringContainsString('name="bank_name"', $html);
        $this->assertStringContainsString('name="bank_account"', $html);
        $this->assertStringContainsString('name="bank_holder"', $html);
    }

    public function testPgSettingsViewPostsToPgUpdateRoute(): void
    {
        $html = view('admin/settings/pg', $this->viewData());

        $this->assertStringContainsString('action="/admin/settings/pg"', $html);
    }
}
