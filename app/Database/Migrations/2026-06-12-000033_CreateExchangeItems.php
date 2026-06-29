<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExchangeItems extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'order_id'         => ['type' => 'INT', 'unsigned' => true],
            'product_id'       => ['type' => 'INT', 'unsigned' => true],
            'sku_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'product_name'     => ['type' => 'VARCHAR', 'constraint' => 200],
            'sku_option_label' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'product_price'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'qty'              => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'subtotal'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('order_id');
        $this->forge->createTable('exchange_items');
    }

    public function down()
    {
        $this->forge->dropTable('exchange_items', true);
    }
}
