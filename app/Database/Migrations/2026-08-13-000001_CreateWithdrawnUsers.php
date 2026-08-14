<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 탈퇴회원 개인정보 스냅샷 테이블
 *
 * users 행은 삭제하지 않고 마스킹만 하므로(주문·리뷰의 user_id 참조 유지),
 * 개인정보 원본은 이 테이블에 옮겨 보관하다가 보관기간 경과 시 파기한다.
 * 파기는 행 삭제가 아니라 개인정보 컬럼만 NULL 로 비우는 방식이다 —
 * 탈퇴 사유·시점 통계는 남아야 하기 때문이다.
 */
class CreateWithdrawnUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],

            // ── 파기 대상 개인정보 ──
            'username'        => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'nickname'        => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'gender'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'birthday'        => ['type' => 'DATE', 'null' => true],
            'avatar'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'social_provider' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'social_id'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'reason_text'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

            // ── 통계용 메타 (파기하지 않음) ──
            'grade'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'point_balance' => ['type' => 'INT', 'default' => 0],
            'coupon_count'  => ['type' => 'INT', 'default' => 0],
            'order_count'   => ['type' => 'INT', 'default' => 0],
            'joined_at'     => ['type' => 'TIMESTAMP', 'null' => true],

            // ── 탈퇴 정보 (파기하지 않음) ──
            'reason_code'  => [
                'type'       => 'ENUM',
                'constraint' => ['unused', 'price', 'service', 'privacy', 'rejoin', 'admin', 'etc'],
                'default'    => 'etc',
            ],
            'withdrawn_by' => ['type' => 'ENUM', 'constraint' => ['member', 'admin'], 'default' => 'member'],
            'withdrawn_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'purged_at'    => ['type' => 'TIMESTAMP', 'null' => true],

            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id', false, false, 'idx_withdrawn_users_user_id');
        $this->forge->addKey('withdrawn_at', false, false, 'idx_withdrawn_users_withdrawn_at');
        $this->forge->addKey('purged_at', false, false, 'idx_withdrawn_users_purged_at');
        $this->forge->createTable('withdrawn_users');
    }

    public function down(): void
    {
        $this->forge->dropTable('withdrawn_users', true);
    }
}
