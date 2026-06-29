<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedWelcomeSettings extends Migration
{
    private array $rows = [
        ['welcome_show_hero',           '1',        'Hero 배너 표시',       'boolean'],
        ['welcome_show_categories',     '1',        '카테고리 바로가기 표시', 'boolean'],
        ['welcome_show_featured',       '1',        '기획전 섹션 표시',      'boolean'],
        ['welcome_featured_title',      '기획전',   '기획전 섹션 제목',      'text'],
        ['welcome_featured_count',      '8',        '기획전 상품 수',        'text'],
        ['welcome_show_new',            '1',        '신상품 섹션 표시',      'boolean'],
        ['welcome_new_title',           '신상품',   '신상품 섹션 제목',      'text'],
        ['welcome_new_count',           '8',        '신상품 수',             'text'],
        ['welcome_show_discount',       '1',        '할인 상품 섹션 표시',   'boolean'],
        ['welcome_discount_title',      '할인 상품','할인 상품 섹션 제목',   'text'],
        ['welcome_discount_count',      '8',        '할인 상품 수',          'text'],
        ['welcome_show_bottom_banner',  '1',        '하단 배너 표시',        'boolean'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as [$key, $value, $label, $type]) {
            $this->db->table('settings')->insert([
                'group'      => 'welcome',
                'key'        => $key,
                'value'      => $value,
                'label'      => $label,
                'type'       => $type,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('settings')->where('group', 'welcome')->delete();
    }
}
