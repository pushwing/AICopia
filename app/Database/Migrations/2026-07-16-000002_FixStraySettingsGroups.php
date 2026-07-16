<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * '기본(general)' 탭에 남아있는 외부 API/결제수단/소셜 로그인 설정 잔여 행을
 * 각자의 그룹으로 옮긴다 — #78(FixApiSettingsGroup) 후속.
 *
 * FixApiSettingsGroup은 외부 API 키 일부만 다뤘고 openrouter_api_key /
 * openrouter_model은 목록에서 빠져 있었다. 또한 결제수단(pg_enabled_*)·
 * 소셜 로그인(oauth_enabled_*) 키는 마이그레이션으로 미리 시딩되지 않고
 * 관리자가 해당 탭을 처음 저장할 때 생성되므로, saveSettings() group 버그가
 * 살아있던 시점에 저장됐다면 여전히 'general'에 남아 '기본' 탭에 노출된다.
 * 실제 값은 변경하지 않고 그룹만 바로잡아 중복 노출을 제거한다.
 */
class FixStraySettingsGroups extends Migration
{
    /**
     * @return array<string, string>
     */
    private function keyGroups(): array
    {
        $groups = [
            'openrouter_api_key' => 'api',
            'openrouter_model'   => 'api',
        ];

        foreach (['toss', 'inicis', 'nicepay', 'kakaopay', 'naverpay', 'payco', 'bank_transfer'] as $pg) {
            $groups["pg_enabled_{$pg}"] = 'pg';
        }

        foreach (['naver', 'kakao', 'google'] as $oauth) {
            $groups["oauth_enabled_{$oauth}"] = 'oauth';
        }

        return $groups;
    }

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->keyGroups() as $key => $group) {
            $this->db->table('settings')
                ->where('key', $key)
                ->where('group !=', $group)
                ->update(['group' => $group, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // FixApiSettingsGroup과 동일한 이유로 no-op — 되돌리면 버그 상태로 회귀한다.
    }
}
