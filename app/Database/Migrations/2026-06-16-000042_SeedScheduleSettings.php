<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedScheduleSettings extends Migration
{
    private array $rows = [
        ['key' => 'schedule_orders_expire_enabled',     'label' => '주문 만료 처리 (orders:expire)'],
        ['key' => 'schedule_stats_purge_logs_enabled',  'label' => '접속 로그 정리 (stats:purge-logs)'],
        ['key' => 'schedule_coupons_birthday_enabled',  'label' => '생일 쿠폰 발급 (coupons:birthday)'],
        ['key' => 'schedule_grades_upgrade_enabled',    'label' => '등급 자동 승급 (grades:upgrade)'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if (! $this->db->table('settings')->where('key', $row['key'])->countAllResults()) {
                $this->db->table('settings')->insert([
                    'group'      => 'schedule',
                    'key'        => $row['key'],
                    'value'      => '1',
                    'label'      => $row['label'],
                    'type'       => 'boolean',
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
