<?php

namespace App\Commands;

use App\Libraries\GradeService;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UpgradeGrades extends BaseCommand
{
    protected $group       = 'Members';
    protected $name        = 'grades:upgrade';
    protected $description = '누적 구매 조건을 충족한 회원을 자동 승급합니다 (bronze→silver, silver→gold).';

    public function run(array $params): void
    {
        $db       = \Config\Database::connect();
        $settings = (new SettingModel())->getAllAsMap();

        if (! (bool) ($settings['schedule_grades_upgrade_enabled'] ?? 1)) {
            CLI::write('[grades:upgrade] 비활성화됨 — 스킵', 'yellow');
            return;
        }
        $service  = new GradeService();
        $upgraded = 0;
        $checked  = 0;

        // platinum/gold 는 자동 승급 없음 — bronze, silver 만 대상
        $users = $db->table('users')
            ->select('id, nickname, email, grade')
            ->whereIn('grade', ['bronze', 'silver'])
            ->where('is_active', 1)
            ->get()->getResultArray();

        foreach ($users as $user) {
            $checked++;
            $newGrade = $service->checkAndUpgrade((int) $user['id'], $settings);
            if ($newGrade) {
                $upgraded++;
                $label = GradeService::LABELS[$newGrade];
                CLI::write("  ↑ [{$user['email']}] {$user['grade']} → {$newGrade}({$label})", 'yellow');
                log_message('info', "[grades:upgrade] user_id={$user['id']} {$user['grade']}→{$newGrade}");
            }
        }

        CLI::write("[grades:upgrade] 검사 {$checked}명 / 승급 {$upgraded}명", 'green');
        log_message('info', "[grades:upgrade] 검사 {$checked}명, 승급 {$upgraded}명");
    }
}
