<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 비밀번호 강제 변경 플래그 (이슈 #119)
 *
 * 시드 마이그레이션이 만든 관리자 계정의 비밀번호는 저장소 문서에 평문으로
 * 적혀 있었다. 시드 코드를 고쳐도 **이미 설치된 인스턴스**에는 계정이 그대로
 * 남으므로, 아직 그 비밀번호를 쓰고 있는 계정을 찾아 변경을 강제한다.
 */
class AddMustChangePassword extends Migration
{
    /** 과거 시드가 심었던 비밀번호 — 이 값을 아직 쓰는 계정만 골라내는 용도 */
    private const string LEGACY_SEED_PASSWORD = 'admin1234!';

    public function up(): void
    {
        $this->forge->addColumn('users', [
            'must_change_password' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'is_active',
            ],
        ]);

        $candidates = $this->db->table('users')
            ->select('id, password, role, last_login')
            ->where('password IS NOT NULL', null, false)
            ->get()->getResultArray();

        $flagged = [];
        foreach ($candidates as $row) {
            // 기존 설치 — 여전히 과거 시드 비밀번호를 쓰는 계정.
            // 해시는 직접 비교할 수 없으므로 후보를 읽어 password_verify 로 확인한다.
            $usesLegacyPassword = password_verify(self::LEGACY_SEED_PASSWORD, (string) $row['password']);

            // 신규 설치 — 시드가 만든 관리자는 랜덤 비밀번호라 위 검사에 걸리지 않는다.
            // 아직 한 번도 로그인하지 않은 관리자는 초기 비밀번호 상태로 본다.
            $isUnusedAdmin = $row['role'] === 'admin' && $row['last_login'] === null;

            if ($usesLegacyPassword || $isUnusedAdmin) {
                $flagged[] = (int) $row['id'];
            }
        }

        if ($flagged !== []) {
            $this->db->table('users')->whereIn('id', $flagged)->update(['must_change_password' => 1]);
            // 운영자가 배포 로그에서 바로 알아볼 수 있게 남긴다.
            log_message('warning', '[Security] 초기 비밀번호 상태 계정 ' . count($flagged) . '건에 변경 강제 플래그를 적용했습니다.');
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'must_change_password');
    }
}
