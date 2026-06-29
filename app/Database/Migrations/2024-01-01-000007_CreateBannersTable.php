<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBannersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'image_path'   => ['type' => 'VARCHAR', 'constraint' => 500],
            'link_url'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'link_target'  => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => '_self'],
            'position'     => ['type' => 'ENUM', 'constraint' => ['main_top', 'main_bottom', 'sub_left', 'sub_right'], 'default' => 'main_top'],
            'priority'     => ['type' => 'INT', 'default' => 0],
            'is_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'started_at'   => ['type' => 'DATETIME', 'null' => true],
            'ended_at'     => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('banners');
    }

    public function down()
    {
        $this->forge->dropTable('banners', true);
    }
}
