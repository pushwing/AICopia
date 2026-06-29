<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserProfileFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'nickname',
            ],
            'gender' => [
                'type'       => 'ENUM',
                'constraint' => ['M', 'F'],
                'null'       => true,
                'after'      => 'phone',
            ],
            'birthday' => [
                'type'  => 'DATE',
                'null'  => true,
                'after' => 'gender',
            ],
            'email_verify_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'is_active',
            ],
            'email_verify_token_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'email_verify_token',
            ],
        ]);

        // 토큰 조회 성능 + 중복 방지
        $this->db->query('ALTER TABLE users ADD UNIQUE KEY uq_email_verify_token (email_verify_token)');
    }

    public function down()
    {
        $this->forge->dropColumn('users', [
            'phone', 'gender', 'birthday',
            'email_verify_token', 'email_verify_token_at',
        ]);
    }
}
