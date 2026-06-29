<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSiteTables extends Migration
{
    public function up()
    {
        // 사이트 전역 설정 (key-value)
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'group'      => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'general'],
            'key'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'type'       => ['type' => 'ENUM', 'constraint' => ['text', 'textarea', 'image', 'boolean'], 'default' => 'text'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['group', 'key']);
        $this->forge->createTable('settings');

        // 동적 페이지
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'slug'        => ['type' => 'VARCHAR', 'constraint' => 200],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'content'     => ['type' => 'LONGTEXT', 'null' => true],
            'layout'      => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'default'],
            'meta_title'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'meta_desc'   => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'og_image'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sort_order'  => ['type' => 'INT', 'default' => 0],
            'status'      => ['type' => 'ENUM', 'constraint' => ['published', 'draft'], 'default' => 'published'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('pages');

        // 네비게이션 메뉴
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'parent_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'url'        => ['type' => 'VARCHAR', 'constraint' => 300],
            'target'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => '_self'],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('menus');

        // 미디어 라이브러리
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'file_path'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'file_size'     => ['type' => 'INT', 'unsigned' => true],
            'mime_type'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'alt'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('media');

        // 문의 수신함
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'phone'      => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'subject'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'message'    => ['type' => 'TEXT'],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'is_read'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('inquiries');
    }

    public function down()
    {
        $this->forge->dropTable('inquiries', true);
        $this->forge->dropTable('media', true);
        $this->forge->dropTable('menus', true);
        $this->forge->dropTable('pages', true);
        $this->forge->dropTable('settings', true);
    }
}
