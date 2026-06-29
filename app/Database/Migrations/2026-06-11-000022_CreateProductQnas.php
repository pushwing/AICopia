<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductQnas extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'product_id'  => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'content'     => ['type' => 'TEXT'],
            'is_secret'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_answered' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'answer'      => ['type' => 'TEXT', 'null' => true],
            'answered_at' => ['type' => 'DATETIME', 'null' => true],
            'answered_by' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('product_id');
        $this->forge->createTable('product_qnas');
    }

    public function down(): void
    {
        $this->forge->dropTable('product_qnas', true);
    }
}
