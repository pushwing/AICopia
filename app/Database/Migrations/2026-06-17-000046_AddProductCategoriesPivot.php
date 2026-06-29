<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddProductCategoriesPivot extends Migration
{
    public function up(): void
    {
        // 1. product_categories 피벗 테이블 생성
        $this->forge->addField([
            'product_id'  => ['type' => 'INT', 'unsigned' => true],
            'category_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['product_id', 'category_id']);
        $this->forge->addKey('category_id');
        $this->forge->createTable('product_categories');

        // 2. 기존 category_id 데이터를 피벗 테이블로 이전 (소프트 삭제 포함)
        $this->db->query('
            INSERT IGNORE INTO product_categories (product_id, category_id)
            SELECT id, category_id FROM products
            WHERE category_id IS NOT NULL
        ');

        // 3. products 테이블에서 category_id 컬럼 삭제
        $this->forge->dropColumn('products', 'category_id');
    }

    public function down(): void
    {
        // products 테이블에 category_id 컬럼 복원
        $this->forge->addColumn('products', [
            'category_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id',
            ],
        ]);

        // 피벗에서 첫 번째 카테고리로 복원
        $this->db->query('
            UPDATE products p
            SET p.category_id = (
                SELECT category_id FROM product_categories
                WHERE product_id = p.id
                ORDER BY category_id ASC
                LIMIT 1
            )
        ');

        $this->forge->dropTable('product_categories', true);
    }
}
