<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * users 에 탈퇴 표식 추가
 *
 * is_active=0 은 이미 '이메일 미인증' 계정이 쓰고 있어(Admin\UserController::index()
 * 의 status 필터 참고) 탈퇴 판별에 재사용하면 두 상태가 섞인다. 별도 컬럼을 둔다.
 */
class AddWithdrawnAtToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'withdrawn_at' => ['type' => 'TIMESTAMP', 'null' => true, 'default' => null, 'after' => 'last_login'],
        ]);
        $this->db->query('ALTER TABLE users ADD INDEX idx_users_withdrawn_at (withdrawn_at)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE users DROP INDEX idx_users_withdrawn_at');
        $this->forge->dropColumn('users', 'withdrawn_at');
    }
}
