<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedGradeSettings extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        // 기존 단일 point_earn_rate 제거
        $this->db->table('settings')->where('key', 'point_earn_rate')->delete();

        $this->db->table('settings')->insertBatch([
            // ── 등급별 포인트 적립률 ─────────────────────────────
            ['group' => 'grade', 'key' => 'point_earn_rate_bronze',   'value' => '1', 'label' => '브론즈 포인트 적립률 (%)',   'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_earn_rate_silver',   'value' => '2', 'label' => '실버 포인트 적립률 (%)',     'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_earn_rate_gold',     'value' => '3', 'label' => '골드 포인트 적립률 (%)',     'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_earn_rate_platinum', 'value' => '5', 'label' => '플래티넘 포인트 적립률 (%)','type' => 'text', 'updated_at' => $now],

            // ── 가입/승급 보너스 포인트 ──────────────────────────
            ['group' => 'grade', 'key' => 'point_bonus_signup',    'value' => '1000', 'label' => '가입 보너스 포인트',        'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_bonus_silver',    'value' => '2000', 'label' => '실버 승급 보너스 포인트',   'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_bonus_gold',      'value' => '3000', 'label' => '골드 승급 보너스 포인트',   'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'point_bonus_platinum',  'value' => '5000', 'label' => '플래티넘 승급 보너스 포인트','type' => 'text', 'updated_at' => $now],

            // ── 등급 승급 기준 ───────────────────────────────────
            ['group' => 'grade', 'key' => 'grade_silver_orders',  'value' => '10',     'label' => '실버 승급 구매 횟수',     'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'grade_silver_amount',  'value' => '100000', 'label' => '실버 승급 구매 금액 (원)','type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'grade_gold_orders',    'value' => '20',     'label' => '골드 승급 구매 횟수',     'type' => 'text', 'updated_at' => $now],
            ['group' => 'grade', 'key' => 'grade_gold_amount',    'value' => '200000', 'label' => '골드 승급 구매 금액 (원)','type' => 'text', 'updated_at' => $now],
        ]);
    }

    public function down()
    {
        $keys = [
            'point_earn_rate_bronze', 'point_earn_rate_silver', 'point_earn_rate_gold', 'point_earn_rate_platinum',
            'point_bonus_signup', 'point_bonus_silver', 'point_bonus_gold', 'point_bonus_platinum',
            'grade_silver_orders', 'grade_silver_amount', 'grade_gold_orders', 'grade_gold_amount',
        ];
        $this->db->table('settings')->whereIn('key', $keys)->delete();

        // 단일 적립률 복구
        $this->db->table('settings')->insert([
            'group' => 'shop', 'key' => 'point_earn_rate', 'value' => '1',
            'label' => '포인트 적립률 (%)', 'type' => 'text', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
