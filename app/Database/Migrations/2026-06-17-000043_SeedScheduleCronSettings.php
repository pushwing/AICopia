<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedScheduleCronSettings extends Migration
{
    private array $rows = [
        ['key' => 'schedule_orders_expire_cron',    'label' => '주문 만료 처리 — 크론 주기',  'value' => '*/5 * * * *'],
        ['key' => 'schedule_stats_purge_logs_cron', 'label' => '접속 로그 정리 — 크론 주기',  'value' => '0 2 * * 1'],
        ['key' => 'schedule_coupons_birthday_cron', 'label' => '생일 쿠폰 발급 — 크론 주기',  'value' => '0 1 * * *'],
        ['key' => 'schedule_grades_upgrade_cron',   'label' => '등급 자동 승급 — 크론 주기',  'value' => '0 3 * * *'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if (! $this->db->table('settings')->where('key', $row['key'])->countAllResults()) {
                $this->db->table('settings')->insert([
                    'group'      => 'schedule',
                    'key'        => $row['key'],
                    'value'      => $row['value'],
                    'label'      => $row['label'],
                    'type'       => 'text',
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
