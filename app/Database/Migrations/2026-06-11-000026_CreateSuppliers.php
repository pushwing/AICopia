<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSuppliers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'contact_person' => ['type' => 'VARCHAR', 'constraint' => 50,  'null' => true, 'default' => null],
            'phone'          => ['type' => 'VARCHAR', 'constraint' => 30,  'null' => true, 'default' => null],
            'email'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'memo'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('suppliers');

        $this->forge->addColumn('products', [
            'supplier_id' => [
                'type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null,
                'after' => 'category_id',
            ],
            'cost_price' => [
                'type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false, 'default' => 0,
                'after' => 'price',
            ],
        ]);

        $this->forge->addColumn('order_items', [
            'cost_price' => [
                'type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false, 'default' => 0,
                'after' => 'product_price',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('order_items', 'cost_price');
        $this->forge->dropColumn('products', ['supplier_id', 'cost_price']);
        $this->forge->dropTable('suppliers');
    }
}
