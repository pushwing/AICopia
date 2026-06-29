<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAiJobsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'type'         => ['type' => 'VARCHAR', 'constraint' => 50],
            'payload'      => ['type' => 'TEXT', 'null' => true],
            'status'       => ['type' => 'ENUM', 'constraint' => ['pending', 'processing', 'done', 'failed'], 'default' => 'pending'],
            'result'       => ['type' => 'TEXT', 'null' => true],
            'attempts'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 3],
            'error'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'worker_token' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'available_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'processed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // 워커가 처리 대상을 선점할 때 사용하는 인덱스
        $this->forge->addKey(['status', 'available_at', 'id']);
        $this->forge->addKey('type');
        $this->forge->createTable('ai_jobs');
    }

    public function down(): void
    {
        $this->forge->dropTable('ai_jobs', true);
    }
}
