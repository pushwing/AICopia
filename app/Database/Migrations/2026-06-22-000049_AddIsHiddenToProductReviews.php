<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsHiddenToProductReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('product_reviews', [
            'is_hidden' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'is_rewarded',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('product_reviews', 'is_hidden');
    }
}
