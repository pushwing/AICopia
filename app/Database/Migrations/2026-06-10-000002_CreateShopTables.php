<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateShopTables extends Migration
{
    public function up()
    {
        // 카테고리 테이블
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'parent_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('parent_id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('categories');

        // 상품 테이블
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'category_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
            'slug'            => ['type' => 'VARCHAR', 'constraint' => 220],
            'price'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'discount_price'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'stock'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'          => ['type' => 'ENUM', 'constraint' => ['on_sale', 'sold_out', 'hidden'], 'default' => 'on_sale'],
            'description'     => ['type' => 'LONGTEXT', 'null' => true],
            'shipping_type'   => ['type' => 'ENUM', 'constraint' => ['free', 'fixed', 'conditional'], 'default' => 'free'],
            'shipping_fee'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'free_threshold'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('category_id');
        $this->forge->addKey('status');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('products');

        // 상품 이미지 테이블 (media 테이블과 연동)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'unsigned' => true],
            'media_id'   => ['type' => 'INT', 'unsigned' => true],
            'is_primary' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->createTable('product_images');
    }

    public function down()
    {
        $this->forge->dropTable('product_images', true);
        $this->forge->dropTable('products', true);
        $this->forge->dropTable('categories', true);
    }
}
