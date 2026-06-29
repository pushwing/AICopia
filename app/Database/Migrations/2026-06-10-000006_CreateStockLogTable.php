<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStockLogTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'   => ['type' => 'INT', 'unsigned' => true],
            'type'         => ['type' => 'ENUM', 'constraint' => ['adjust', 'order', 'cancel', 'return', 'in', 'out']],
            'quantity'     => ['type' => 'INT'],
            'stock_before' => ['type' => 'INT'],
            'stock_after'  => ['type' => 'INT'],
            'note'         => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'admin_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('stock_logs');
    }

    public function down()
    {
        $this->forge->dropTable('stock_logs', true);
    }
}
