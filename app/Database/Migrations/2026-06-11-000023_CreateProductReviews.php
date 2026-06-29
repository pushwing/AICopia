<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'product_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'order_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'content'     => ['type' => 'TEXT'],
            'is_rewarded' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->addKey('order_id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('product_reviews');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'review_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'image_path' => ['type' => 'VARCHAR', 'constraint' => 500],
            'sort_order' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('review_id');
        $this->forge->createTable('product_review_images');
    }

    public function down(): void
    {
        $this->forge->dropTable('product_review_images', true);
        $this->forge->dropTable('product_reviews', true);
    }
}
