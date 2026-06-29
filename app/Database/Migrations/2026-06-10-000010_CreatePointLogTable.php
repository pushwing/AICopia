<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePointLogTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'type'       => ['type' => 'ENUM', 'constraint' => ['use', 'earn', 'refund', 'cancel', 'admin'], 'default' => 'earn'],
            'amount'     => ['type' => 'INT', 'default' => 0],
            'order_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addKey('order_id');
        $this->forge->createTable('point_logs');
    }

    public function down()
    {
        $this->forge->dropTable('point_logs', true);
    }
}
