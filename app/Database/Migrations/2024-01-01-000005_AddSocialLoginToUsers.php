<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSocialLoginToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'social_provider' => [
                'type'       => 'ENUM',
                'constraint' => ['naver', 'kakao', 'google'],
                'null'       => true,
                'after'      => 'role',
            ],
            'social_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'social_provider',
            ],
            'social_token' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'social_id',
            ],
            'avatar' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
                'after'      => 'social_token',
            ],
        ]);

        // 소셜 로그인 유저는 password가 없을 수 있으므로 nullable 처리
        $this->forge->modifyColumn('users', [
            'password' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
        ]);

        $this->db->query('ALTER TABLE users ADD UNIQUE KEY unique_social (social_provider, social_id)');
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['social_provider', 'social_id', 'social_token', 'avatar']);
    }
}
