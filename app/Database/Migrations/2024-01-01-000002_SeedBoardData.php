<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedBoardData extends Migration
{
    public function up()
    {
        // 기본 게시판 샘플 데이터
        $this->db->table('boards')->insertBatch([
            [
                'slug'             => 'notice',
                'name'             => '공지사항',
                'description'      => '운영자 공지사항',
                'read_permission'  => 'guest',
                'write_permission' => 'admin',
                'allow_file'       => 1,
                'allow_image'      => 1,
                'posts_per_page'   => 15,
                'sort_order'       => 1,
                'created_at'       => date('Y-m-d H:i:s'),
            ],
            [
                'slug'             => 'free',
                'name'             => '자유게시판',
                'description'      => '자유롭게 이야기하세요',
                'read_permission'  => 'guest',
                'write_permission' => 'member',
                'allow_file'       => 1,
                'allow_image'      => 1,
                'posts_per_page'   => 15,
                'sort_order'       => 2,
                'created_at'       => date('Y-m-d H:i:s'),
            ],
            [
                'slug'             => 'qna',
                'name'             => '문의게시판',
                'description'      => '비회원도 문의 가능합니다',
                'read_permission'  => 'guest',
                'write_permission' => 'guest',
                'allow_file'       => 1,
                'allow_image'      => 0,
                'posts_per_page'   => 15,
                'sort_order'       => 3,
                'created_at'       => date('Y-m-d H:i:s'),
            ],
        ]);

        // 관리자 계정
        $this->db->table('users')->insert([
            'username'   => 'admin',
            'email'      => 'admin@example.com',
            'password'   => password_hash('admin1234!', PASSWORD_DEFAULT),
            'nickname'   => '관리자',
            'role'       => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $this->db->table('boards')->truncate();
        $this->db->table('users')->truncate();
    }
}
