<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedAiWorkScheduleSettings extends Migration
{
    private array $rows = [
        ['key' => 'schedule_ai_work_enabled', 'value' => '1',         'type' => 'boolean', 'label' => 'AI 작업 큐 처리 (ai:work)'],
        ['key' => 'schedule_ai_work_cron',    'value' => '* * * * *', 'type' => 'text',    'label' => 'AI 작업 큐 처리 — 크론 주기'],
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
