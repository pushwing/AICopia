<?php

declare(strict_types=1);

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
        //
        // 고정 비밀번호를 심으면 이 솔루션을 설치한 모든 인스턴스가 동일한(그리고
        // 문서에 공개된) 자격증명을 갖게 된다. 매 설치마다 다른 비밀번호를 만들어
        // 콘솔에 1회만 출력하고, 최초 로그인 시 변경을 강제한다. (이슈 #119)
        $initialPassword = env('ADMIN_INITIAL_PASSWORD') ?: bin2hex(random_bytes(12));

        $this->db->table('users')->insert([
            'username'   => 'admin',
            'email'      => env('ADMIN_INITIAL_EMAIL') ?: 'admin@example.com',
            'password'   => password_hash($initialPassword, PASSWORD_DEFAULT),
            'nickname'   => '관리자',
            'role'       => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 이 출력은 마이그레이션을 실행한 사람만 볼 수 있다. 놓치더라도 비밀번호
        // 찾기로 재설정하면 되고, 어느 쪽이든 최초 로그인 때 변경해야 한다.
        if (is_cli()) {
            fwrite(STDOUT, PHP_EOL
                . "  관리자 초기 비밀번호: {$initialPassword}" . PHP_EOL
                . '  최초 로그인 후 반드시 변경해야 합니다 (변경 전까지 다른 화면 접근이 막힙니다).' . PHP_EOL . PHP_EOL);
        }
    }

    public function down()
    {
        $this->db->table('boards')->truncate();
        $this->db->table('users')->truncate();
    }
}
