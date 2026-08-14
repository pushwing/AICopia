<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WithdrawalService;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PurgeWithdrawnUsers extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'users:purge-withdrawn';
    protected $description = '보관 기간이 지난 탈퇴회원의 개인정보를 파기합니다.';
    protected $usage       = 'users:purge-withdrawn [일수]';

    /** @param array<int|string, string|null> $params */
    public function run(array $params): void
    {
        $settings = new SettingModel()->getAllAsMap();
        if (! (bool) ($settings['schedule_users_purge_withdrawn_enabled'] ?? 1)) {
            CLI::write('[users:purge-withdrawn] 비활성화됨 — 스킵', 'yellow');

            return;
        }

        $days = (int) ($params[0] ?? $settings['withdrawal_retention_days'] ?? 30);
        if ($days < 1) {
            CLI::write('[users:purge-withdrawn] 보관일수가 1 미만이라 중단합니다.', 'red');

            return;
        }

        $count = new WithdrawalService()->purgeExpired($days);

        CLI::write("[users:purge-withdrawn] {$count}건 개인정보 파기 완료 ({$days}일 초과)", 'green');
        log_message('info', "[users:purge-withdrawn] {$count}건 파기");
    }
}
