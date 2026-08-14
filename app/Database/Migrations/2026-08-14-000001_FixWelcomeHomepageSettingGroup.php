<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * '기본(general)' 탭에 남아있는 store_homepage 설정을 welcome 그룹으로 옮긴다
 * — FixStraySettingsGroups(#78)와 동일한 원인의 후속 사례.
 *
 * store_homepage는 welcome_show_* 등과 달리 마이그레이션으로 미리 시딩된 적이
 * 없어, WelcomeController::update()가 saveSettings() group 버그가 살아있던
 * 시점에 처음 저장되면 'general'로 INSERT되어 "사이트 설정 > 기본" 탭에 원시
 * 키로 노출됐다. 값은 건드리지 않고 그룹만 바로잡아 중복 노출을 제거한다.
 */
class FixWelcomeHomepageSettingGroup extends Migration
{
    public function up(): void
    {
        $this->db->table('settings')
            ->where('key', 'store_homepage')
            ->where('group !=', 'welcome')
            ->update(['group' => 'welcome', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        // FixStraySettingsGroups와 동일한 이유로 no-op — 되돌리면 버그 상태로 회귀한다.
    }
}
