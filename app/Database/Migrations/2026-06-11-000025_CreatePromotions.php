<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePromotions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'slug'         => ['type' => 'VARCHAR', 'constraint' => 200],
            'description'  => ['type' => 'LONGTEXT', 'null' => true],
            'banner_image' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'grade_access' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'all'],
            'start_date'   => ['type' => 'DATE', 'null' => true, 'default' => null],
            'end_date'     => ['type' => 'DATE', 'null' => true, 'default' => null],
            'is_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order'   => ['type' => 'SMALLINT', 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('is_active');
        $this->forge->createTable('promotions');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'auto_increment' => true],
            'promotion_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'product_id'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'sort_order'   => ['type' => 'SMALLINT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['promotion_id', 'sort_order']);
        $this->forge->createTable('promotion_products');
    }

    public function down(): void
    {
        $this->forge->dropTable('promotion_products', true);
        $this->forge->dropTable('promotions', true);
    }
}
