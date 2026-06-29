<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAccessLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45],
            'page'       => ['type' => 'VARCHAR', 'constraint' => 500],
            'url'        => ['type' => 'VARCHAR', 'constraint' => 1000],
            'user_id'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true, 'default' => null],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'referer'    => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['ip']);
        $this->forge->addKey(['page']);
        $this->forge->addKey(['created_at']);
        $this->forge->createTable('access_logs');
    }

    public function down(): void
    {
        $this->forge->dropTable('access_logs', true);
    }
}
