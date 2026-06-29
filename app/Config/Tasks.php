<?php

declare(strict_types=1);

namespace Config;

use App\Models\SettingModel;
use CodeIgniter\Tasks\Config\Tasks as BaseTasks;
use CodeIgniter\Tasks\Scheduler;

class Tasks extends BaseTasks
{
    public bool $logPerformance = false;
    public int  $maxLogsPerTask = 10;

    private const JOB_COMMANDS = [
        'schedule_orders_expire'    => 'orders:expire',
        'schedule_stats_purge_logs' => 'stats:purge-logs',
        'schedule_coupons_birthday' => 'coupons:birthday',
        'schedule_grades_upgrade'   => 'grades:upgrade',
        'schedule_ai_work'          => 'ai:work',
    ];

    /**
     * 관리자 UI(settings 테이블)에서 활성화된 배치 잡만 등록.
     * 크론 등록: * * * * * cd /path/to/shop && php spark tasks:run >> /dev/null 2>&1
     */
    public function init(Scheduler $schedule): void
    {
        $settings = (new SettingModel())->getAllAsMap();

        foreach (self::JOB_COMMANDS as $baseKey => $command) {
            if (! ($settings[$baseKey . '_enabled'] ?? false)) {
                continue;
            }

            $cron = trim((string) ($settings[$baseKey . '_cron'] ?? ''));
            if ($cron === '') {
                continue;
            }

            $schedule->command($command)->cron($cron);
        }
    }
}
