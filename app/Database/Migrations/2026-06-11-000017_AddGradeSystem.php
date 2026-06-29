<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGradeSystem extends Migration
{
    public function up()
    {
        // users 테이블에 grade 컬럼 추가
        $this->forge->addColumn('users', [
            'grade' => [
                'type'       => 'ENUM',
                'constraint' => ['bronze', 'silver', 'gold', 'platinum'],
                'default'    => 'bronze',
                'after'      => 'role',
            ],
        ]);

        // coupons 테이블: type ENUM에 free_shipping 추가
        $this->db->query(
            "ALTER TABLE coupons MODIFY COLUMN type ENUM('fixed','percent','free_shipping') NOT NULL DEFAULT 'fixed'"
        );

        // coupons 테이블: 등급 대상 컬럼 추가
        $this->forge->addColumn('coupons', [
            'target_grade' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'type',
            ],
        ]);

        // 기존 회원 전체 bronze로 초기화 (default가 이미 bronze이므로 신규는 자동 처리)
        $this->db->query("UPDATE users SET grade = 'bronze' WHERE grade IS NULL OR grade = ''");
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'grade');
        $this->forge->dropColumn('coupons', 'target_grade');
        $this->db->query(
            "ALTER TABLE coupons MODIFY COLUMN type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'"
        );
    }
}
