<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderStatusLogTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'order_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'from_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'to_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'actor_type' => [
                'type'       => 'ENUM',
                'constraint' => ['admin', 'member', 'system'],
                'default'    => 'system',
            ],
            'actor_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'default'  => null,
            ],
            'actor_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => 'system',
            ],
            'note' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('order_id');
        $this->forge->createTable('order_status_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('order_status_logs', true);
    }
}
