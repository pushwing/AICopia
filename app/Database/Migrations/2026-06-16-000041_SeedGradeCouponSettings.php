<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedGradeCouponSettings extends Migration
{
    private array $rows = [
        ['key' => 'coupon_birthday_id',      'label' => '생일 쿠폰 ID (비워두면 미발급)'],
        ['key' => 'coupon_grade_silver_id',  'label' => '실버 승급 쿠폰 ID (비워두면 미발급)'],
        ['key' => 'coupon_grade_gold_id',    'label' => '골드 승급 쿠폰 ID (비워두면 미발급)'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            $exists = $this->db->table('settings')->where('key', $row['key'])->countAllResults();
            if (! $exists) {
                $this->db->table('settings')->insert([
                    'group'      => 'grade',
                    'key'        => $row['key'],
                    'value'      => '',
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
