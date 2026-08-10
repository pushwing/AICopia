<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductAddons extends Migration
{
    public function up()
    {
        // 본품 ↔ 추가구성상품 연결
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'       => ['type' => 'INT', 'unsigned' => true],
            'addon_product_id' => ['type' => 'INT', 'unsigned' => true],
            'sort_order'       => ['type' => 'INT', 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->addUniqueKey(['product_id', 'addon_product_id'], 'uniq_product_addons_pair');
        $this->forge->createTable('product_addons');

        // 어느 본품에 딸려 담겼는지 — 표시·포장용이며 금액/재고 계산에는 쓰지 않는다.
        $this->forge->addColumn('cart_items', [
            'parent_product_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'sku_id'],
        ]);
        $this->forge->addColumn('order_items', [
            'parent_product_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'sku_id'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('order_items', 'parent_product_id');
        $this->forge->dropColumn('cart_items', 'parent_product_id');
        $this->forge->dropTable('product_addons', true);
    }
}
