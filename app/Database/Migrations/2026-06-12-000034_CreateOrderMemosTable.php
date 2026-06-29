<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderMemosTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'order_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'admin_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'content'    => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('order_id');
        $this->forge->createTable('order_memos');
    }

    public function down(): void
    {
        $this->forge->dropTable('order_memos', true);
    }
}
