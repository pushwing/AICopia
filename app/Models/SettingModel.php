<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table      = 'settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['group', 'key', 'value', 'label', 'type', 'updated_at'];

    /**
     * 전체 설정을 ['key' => 'value'] 형태로 반환 (캐시 1시간)
     *
     * @return array<string, mixed>
     */
    public function getAllAsMap(): array
    {
        return (array) cache()->remember('site_settings', 3600, function (): array {
            $rows = $this->findAll();
            $map  = [];
            foreach ($rows as $row) {
                $map[$row['key']] = $row['value'];
            }
            return $map;
        });
    }

    /**
     * 특정 그룹의 설정 목록 반환
     *
     * @return array<int, array<string, mixed>>
     */
    public function getGroup(string $group): array
    {
        return $this->where('group', $group)->orderBy('id')->findAll();
    }

    /**
     * 설정 저장 (키가 있으면 UPDATE, 없으면 INSERT)
     *
     * 새 키를 INSERT할 때는 $group을 사용한다. 호출부가 그룹을 명시하지 않으면
     * 신규 키가 실제 탭과 무관하게 '기본(general)' 탭에 노출되므로 반드시
     * 해당 설정 탭의 그룹을 넘겨야 한다.
     *
     * @param array<string, mixed> $data
     */
    public function saveSettings(array $data, string $group = 'general'): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($data as $key => $value) {
            $existing = $this->where('key', $key)->first();
            if ($existing) {
                $this->update($existing['id'], ['value' => $value, 'updated_at' => $now]);
            } else {
                // 마이그레이션 없이 새 키가 들어올 경우 INSERT
                $this->insert(['key' => $key, 'value' => $value, 'group' => $group, 'label' => $key, 'type' => 'text', 'updated_at' => $now]);
            }
        }
        cache()->delete('site_settings');
    }
}
