<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAccessLogSummaries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'log_date' => ['type' => 'DATE'],
            'page'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'pv'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'uv'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['log_date', 'page']);
        $this->forge->addKey(['log_date']);
        $this->forge->createTable('access_log_summaries');
    }

    public function down(): void
    {
        $this->forge->dropTable('access_log_summaries', true);
    }
}
