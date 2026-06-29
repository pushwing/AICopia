<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePopupsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'image_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'content'    => ['type' => 'TEXT', 'null' => true],
            'show_scope' => ['type' => 'ENUM', 'constraint' => ['all', 'home_only', 'specific'], 'default' => 'all'],
            'pos_x'      => ['type' => 'INT', 'default' => 20],
            'pos_y'      => ['type' => 'INT', 'default' => 20],
            'priority'   => ['type' => 'INT', 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'ended_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('popups');

        $this->forge->addField([
            'popup_id' => ['type' => 'INT', 'unsigned' => true],
            'menu_id'  => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addKey(['popup_id', 'menu_id'], true);
        $this->forge->createTable('popup_pages');
    }

    public function down()
    {
        $this->forge->dropTable('popup_pages', true);
        $this->forge->dropTable('popups', true);
    }
}
