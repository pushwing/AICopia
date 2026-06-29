<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductOptionTables extends Migration
{
    public function up()
    {
        // 옵션 그룹 (예: 색상, 사이즈)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'unsigned' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'sort_order' => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->createTable('product_options');

        // 옵션 값 (예: 빨강, L)
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'option_id' => ['type' => 'INT', 'unsigned' => true],
            'value'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'sort_order'=> ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('option_id');
        $this->forge->createTable('product_option_values');

        // SKU (옵션 조합별 가격 차이 + 재고)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id' => ['type' => 'INT', 'unsigned' => true],
            'price_diff' => ['type' => 'INT', 'default' => 0],          // 기준가 대비 ±원
            'stock'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'sku_code'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->createTable('product_skus');

        // SKU ↔ 옵션값 연결 (junction)
        $this->forge->addField([
            'sku_id'          => ['type' => 'INT', 'unsigned' => true],
            'option_value_id' => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey(['sku_id', 'option_value_id'], false, true); // composite PK
        $this->forge->addKey('sku_id');
        $this->forge->createTable('product_sku_values');

        // cart_items: sku_id 추가 + UNIQUE KEY 변경
        $this->forge->addColumn('cart_items', [
            'sku_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'product_id'],
        ]);
        // 기존 UNIQUE KEY (user_id, product_id) 제거 후 (user_id, product_id, sku_id)로 교체
        $this->db->query('ALTER TABLE cart_items DROP INDEX user_id');
        $this->db->query('ALTER TABLE cart_items ADD UNIQUE KEY cart_item_unique (user_id, product_id, sku_id)');

        // order_items: sku 스냅샷 컬럼 추가
        $this->forge->addColumn('order_items', [
            'sku_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'product_id'],
            'sku_option_label' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'after' => 'sku_id'],
        ]);
    }

    public function down()
    {
        // order_items 컬럼 제거
        $this->forge->dropColumn('order_items', 'sku_option_label');
        $this->forge->dropColumn('order_items', 'sku_id');

        // cart_items 원상 복구
        $this->db->query('ALTER TABLE cart_items DROP INDEX cart_item_unique');
        $this->db->query('ALTER TABLE cart_items ADD UNIQUE KEY user_id (user_id, product_id)');
        $this->forge->dropColumn('cart_items', 'sku_id');

        $this->forge->dropTable('product_sku_values', true);
        $this->forge->dropTable('product_skus', true);
        $this->forge->dropTable('product_option_values', true);
        $this->forge->dropTable('product_options', true);
    }
}
