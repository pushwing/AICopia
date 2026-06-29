<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTriageColumnsToInquiries extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('inquiries', [
            // NULL = 미분류 (AI 분류 전)
            'category'  => [
                'type'       => 'ENUM',
                'constraint' => ['shipping', 'refund', 'product', 'payment', 'etc'],
                'null'       => true,
                'after'      => 'message',
            ],
            'priority'  => [
                'type'       => 'ENUM',
                'constraint' => ['high', 'normal', 'low'],
                'null'       => true,
                'after'      => 'category',
            ],
            'sentiment' => [
                'type'       => 'ENUM',
                'constraint' => ['positive', 'neutral', 'negative'],
                'null'       => true,
                'after'      => 'priority',
            ],
        ]);
        // 목록 카테고리 필터용
        $this->db->query('CREATE INDEX idx_inq_category ON inquiries (category)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_inq_category ON inquiries');
        $this->forge->dropColumn('inquiries', ['category', 'priority', 'sentiment']);
    }
}
