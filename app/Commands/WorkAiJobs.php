<?php

namespace App\Commands;

use App\Libraries\AiProvider\AiJobRunner;
use App\Models\AiJobModel;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class WorkAiJobs extends BaseCommand
{
    protected $group       = 'AI';
    protected $name        = 'ai:work';
    protected $description = '대기 중인 AI 작업 큐(ai_jobs)를 백그라운드에서 처리합니다.';
    protected $usage       = 'ai:work [limit]';

    public function run(array $params): void
    {
        $settings = (new SettingModel())->getAllAsMap();
        if (! (bool) ($settings['schedule_ai_work_enabled'] ?? 1)) {
            CLI::write('[ai:work] 비활성화됨 — 스킵', 'yellow');
            return;
        }

        $limit  = max(1, (int) ($params[0] ?? 10));
        $model  = new AiJobModel();
        $runner = new AiJobRunner();

        $done   = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $job = $model->claimNext();
            if ($job === null) {
                break; // 처리할 작업 없음
            }

            try {
                $result = $runner->run($job);
                $model->markDone((int) $job['id'], $result);
                $done++;
            } catch (\Throwable $e) {
                $model->markFailed((int) $job['id'], $e->getMessage());
                $failed++;
                log_message('error', "[ai:work] job#{$job['id']} ({$job['type']}) 실패: " . $e->getMessage());
            }
        }

        CLI::write("[ai:work] 성공 {$done}건 / 실패 {$failed}건", $failed > 0 ? 'yellow' : 'green');
        log_message('info', "[ai:work] 성공 {$done}건 / 실패 {$failed}건");
    }
}
