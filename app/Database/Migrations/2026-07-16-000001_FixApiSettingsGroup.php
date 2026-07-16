<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Libraries\AiProvider\AiPrompts;
use CodeIgniter\Database\Migration;

/**
 * 외부 API 탭(/admin/settings/api) 설정값을 'api' 그룹으로 고정 — 이슈 #77
 *
 * SettingModel::saveSettings()가 group 인자 없이 신규 키를 항상 'general'로
 * INSERT하던 버그 때문에, 마이그레이션으로 미리 시딩되지 않은 외부 API 키
 * (ai_provider, groq_api_key 등)를 처음 저장하면 '기본' 탭에 노출되었다.
 * 이미 그렇게 저장된 운영 데이터만 'api' 그룹으로 옮긴다.
 *
 * 존재하지 않는 키를 미리 INSERT하지는 않는다 — AiCategoryAdvisor::create()의
 * `$settings['ai_provider'] ?? env('AI_PROVIDER', 'groq')`처럼 키 부재를 통해
 * env 값으로 폴백하는 로직이 있어, 빈 값이라도 행을 미리 만들면 그 폴백이
 * 영구히 무력화된다. saveSettings()가 이제 그룹을 올바르게 넘기므로(코드 수정
 * 완료) 신규 설치본은 처음 저장하는 시점부터 자동으로 'api' 그룹에 들어간다.
 */
class FixApiSettingsGroup extends Migration
{
    /**
     * @return array<int, string>
     */
    private function keys(): array
    {
        $keys = [
            'ai_provider',
            'groq_api_key',
            'anthropic_api_key',
            'naver_shopping_client_id',
            'naver_shopping_client_secret',
            'clipdrop_api_key',
        ];

        foreach (AiPrompts::KEYS as $promptKey) {
            $keys[] = AiPrompts::PREFIX . $promptKey;
        }

        return $keys;
    }

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->keys() as $key) {
            $this->db->table('settings')
                ->where('key', $key)
                ->where('group !=', 'api')
                ->update(['group' => 'api', 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // 어느 행이 이 마이그레이션으로 옮겨졌는지 추적하지 않으므로
        // 그룹을 임의로 되돌리면 오히려 원래 버그 상태로 회귀한다. no-op.
    }
}
