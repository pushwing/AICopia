<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRestockAlerts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'product_id'  => ['type' => 'INT', 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'notified_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['product_id', 'email'], 'uq_product_email');
        $this->forge->addKey(['product_id', 'notified_at'], false, false, 'idx_product_notified');
        $this->forge->createTable('restock_alerts');
    }

    public function down(): void
    {
        $this->forge->dropTable('restock_alerts', true);
    }
}
