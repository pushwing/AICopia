<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRatingToProductReviews extends Migration
{
    public function up(): void
    {
        // 별점 1~5. 0 = 레거시(별점 없는 기존 리뷰) — 집계에서 제외한다.
        $this->forge->addColumn('product_reviews', [
            'rating' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'default'    => 0,
                'null'       => false,
                'after'      => 'content',
            ],
        ]);
        // 상품별 평균 평점·개수 집계 조회용
        $this->db->query('CREATE INDEX idx_pr_rating ON product_reviews (product_id, rating, is_hidden)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_pr_rating ON product_reviews');
        $this->forge->dropColumn('product_reviews', 'rating');
    }
}
