<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsNegativeToProductReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('product_reviews', [
            'is_negative' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
                'after'      => 'is_hidden',
            ],
        ]);
        // 관리자 미확인 부정 리뷰 카운트 조회용
        $this->db->query('CREATE INDEX idx_pr_negative ON product_reviews (is_negative, is_hidden)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_pr_negative ON product_reviews');
        $this->forge->dropColumn('product_reviews', 'is_negative');
    }
}
