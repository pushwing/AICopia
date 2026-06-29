<?php

namespace App\Commands;

use App\Models\AccessLogModel;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PurgeAccessLogs extends BaseCommand
{
    protected $group       = 'Stats';
    protected $name        = 'stats:purge-logs';
    protected $description = '보존 기간(기본 90일) 이전의 접속 로그를 삭제합니다.';

    public function run(array $params): void
    {
        $settings = (new SettingModel())->getAllAsMap();
        if (! (bool) ($settings['schedule_stats_purge_logs_enabled'] ?? 1)) {
            CLI::write('[stats:purge-logs] 비활성화됨 — 스킵', 'yellow');
            return;
        }

        $days  = (int) ($params[0] ?? 90);
        $count = (new AccessLogModel())->purgeOlderThan($days);

        CLI::write("[stats:purge-logs] {$count}건 삭제 완료 ({$days}일 초과)", 'green');
        log_message('info', "[stats:purge-logs] {$count}건 삭제");
    }
}
