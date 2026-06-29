<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBoardTables extends Migration
{
    public function up()
    {
        // 게시판 설정 테이블
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'slug'           => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'read_permission'  => ['type' => 'ENUM', 'constraint' => ['guest', 'member', 'admin'], 'default' => 'guest'],
            'write_permission' => ['type' => 'ENUM', 'constraint' => ['guest', 'member', 'admin'], 'default' => 'member'],
            'allow_file'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'allow_image'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'posts_per_page' => ['type' => 'INT', 'default' => 15],
            'sort_order'     => ['type' => 'INT', 'default' => 0],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('boards');

        // 사용자 테이블
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'username'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'password'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'nickname'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'role'       => ['type' => 'ENUM', 'constraint' => ['admin', 'member'], 'default' => 'member'],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_login' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('users');

        // 게시글 테이블
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'board_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'content'    => ['type' => 'LONGTEXT'],
            'author_name'  => ['type' => 'VARCHAR', 'constraint' => 50],   // 비회원용
            'author_password' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // 비회원 수정/삭제용
            'views'      => ['type' => 'INT', 'default' => 0],
            'is_notice'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_secret'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('board_id');
        $this->forge->createTable('posts');

        // 첨부파일 테이블
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'post_id'       => ['type' => 'INT', 'unsigned' => true],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'file_path'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'file_size'     => ['type' => 'INT', 'unsigned' => true],
            'mime_type'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'is_image'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'download_count'=> ['type' => 'INT', 'default' => 0],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('post_id');
        $this->forge->createTable('post_files');

        // 댓글 테이블
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'post_id'         => ['type' => 'INT', 'unsigned' => true],
            'user_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'content'         => ['type' => 'TEXT'],
            'author_name'     => ['type' => 'VARCHAR', 'constraint' => 50],
            'author_password' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('post_id');
        $this->forge->createTable('post_comments');
    }

    public function down()
    {
        $this->forge->dropTable('post_comments', true);
        $this->forge->dropTable('post_files', true);
        $this->forge->dropTable('posts', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('boards', true);
    }
}
