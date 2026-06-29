<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table      = 'settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['group', 'key', 'value', 'label', 'type', 'updated_at'];

    /**
     * 전체 설정을 ['key' => 'value'] 형태로 반환 (캐시 1시간)
     */
    public function getAllAsMap(): array
    {
        return (array) cache()->remember('site_settings', 3600, function () {
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
     */
    public function getGroup(string $group): array
    {
        return $this->where('group', $group)->orderBy('id')->findAll();
    }

    /**
     * 설정 저장 (키가 있으면 UPDATE, 없으면 INSERT)
     */
    public function saveSettings(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($data as $key => $value) {
            $existing = $this->where('key', $key)->first();
            if ($existing) {
                $this->update($existing['id'], ['value' => $value, 'updated_at' => $now]);
            } else {
                // 마이그레이션 없이 새 키가 들어올 경우 INSERT
                $this->insert(['key' => $key, 'value' => $value, 'group' => 'general', 'label' => $key, 'type' => 'text', 'updated_at' => $now]);
            }
        }
        cache()->delete('site_settings');
    }
}
