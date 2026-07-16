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
 * 이미 그렇게 저장된 운영 데이터를 'api' 그룹으로 옮기고, 아직 저장된 적 없는
 * 설치본을 위해 기본값을 'api' 그룹으로 미리 시딩한다.
 */
class FixApiSettingsGroup extends Migration
{
    /**
     * @return array<int, array{key: string, type: string, value: string}>
     */
    private function keys(): array
    {
        $keys = [
            ['key' => 'ai_provider',                 'type' => 'text',     'value' => 'groq'],
            ['key' => 'groq_api_key',                'type' => 'password', 'value' => ''],
            ['key' => 'anthropic_api_key',            'type' => 'password', 'value' => ''],
            ['key' => 'naver_shopping_client_id',     'type' => 'text',     'value' => ''],
            ['key' => 'naver_shopping_client_secret', 'type' => 'password', 'value' => ''],
            ['key' => 'clipdrop_api_key',             'type' => 'password', 'value' => ''],
        ];

        foreach (AiPrompts::KEYS as $promptKey) {
            $keys[] = ['key' => AiPrompts::PREFIX . $promptKey, 'type' => 'textarea', 'value' => ''];
        }

        return $keys;
    }

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->keys() as $row) {
            $existing = $this->db->table('settings')->where('key', $row['key'])->get()->getRow();

            if ($existing) {
                if ($existing->group !== 'api') {
                    $this->db->table('settings')->where('id', $existing->id)->update([
                        'group'      => 'api',
                        'updated_at' => $now,
                    ]);
                }
                continue;
            }

            $this->db->table('settings')->insert([
                'group'      => 'api',
                'key'        => $row['key'],
                'value'      => $row['value'],
                'type'       => $row['type'],
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->keys() as $row) {
            $this->db->table('settings')->where('key', $row['key'])->delete();
        }
    }
}
