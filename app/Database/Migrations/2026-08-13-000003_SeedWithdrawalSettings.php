<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedWithdrawalSettings extends Migration
{
    /** @var list<array<string, string>> */
    private array $rows = [
        [
            'group' => 'member',
            'key'   => 'withdrawal_retention_days',
            'value' => '30',
            'label' => '탈퇴회원 개인정보 보관일수',
            'type'  => 'text',
        ],
        [
            'group' => 'schedule',
            'key'   => 'schedule_users_purge_withdrawn_enabled',
            'value' => '1',
            'label' => '탈퇴회원 개인정보 파기',
            'type'  => 'boolean',
        ],
        [
            // 등급 승급(0 3 * * *)과 겹치지 않게 04시
            'group' => 'schedule',
            'key'   => 'schedule_users_purge_withdrawn_cron',
            'value' => '0 4 * * *',
            'label' => '탈퇴회원 개인정보 파기 — 크론 주기',
            'type'  => 'text',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if (! $this->db->table('settings')->where('key', $row['key'])->countAllResults()) {
                $this->db->table('settings')->insert([
                    'group'      => $row['group'],
                    'key'        => $row['key'],
                    'value'      => $row['value'],
                    'label'      => $row['label'],
                    'type'       => $row['type'],
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('settings')->whereIn('key', array_column($this->rows, 'key'))->delete();
    }
}
