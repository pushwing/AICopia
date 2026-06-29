<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class ScheduleController extends BaseController
{
    private SettingModel $settingModel;

    private const JOB_COMMANDS = [
        'schedule_orders_expire'    => 'orders:expire',
        'schedule_stats_purge_logs' => 'stats:purge-logs',
        'schedule_coupons_birthday' => 'coupons:birthday',
        'schedule_grades_upgrade'   => 'grades:upgrade',
        'schedule_ai_work'          => 'ai:work',
    ];

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index(): string
    {
        $allSettings = $this->settingModel->getGroup('schedule');
        $settingsMap = array_column($allSettings, null, 'key');

        $jobs = [];
        foreach (self::JOB_COMMANDS as $baseKey => $command) {
            $enabledKey = $baseKey . '_enabled';
            if (! isset($settingsMap[$enabledKey])) {
                continue;
            }
            $jobs[] = [
                'base_key'    => $baseKey,
                'enabled_key' => $enabledKey,
                'command'     => $command,
                'label'       => $settingsMap[$enabledKey]['label'],
                'enabled'     => $settingsMap[$enabledKey]['value'],
                'cron'        => $settingsMap[$baseKey . '_cron']['value'] ?? '',
            ];
        }

        return $this->render('admin/schedule/index', [
            'pageTitle' => '배치 작업 관리',
            'jobs'      => $jobs,
        ]);
    }

    public function toggle(string $key): \CodeIgniter\HTTP\RedirectResponse|\CodeIgniter\HTTP\ResponseInterface
    {
        $existing = $this->settingModel->where('group', 'schedule')->where('key', $key)->first();

        if (! $existing) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['success' => false, 'message' => '존재하지 않는 설정 키입니다.']);
            }
            return redirect()->to('/admin/schedule')->with('error', '존재하지 않는 설정 키입니다.');
        }

        $newValue = $existing['value'] === '1' ? '0' : '1';
        $this->settingModel->saveSettings([$key => $newValue]);

        $label   = $existing['label'];
        $status  = $newValue === '1' ? '활성화' : '비활성화';
        $message = "{$label} — {$status}되었습니다.";

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'success'    => true,
                'enabled'    => $newValue === '1',
                'message'    => $message,
                'csrf_token' => csrf_hash(),
            ]);
        }

        return redirect()->to('/admin/schedule')->with('success', $message);
    }

    public function updateCron(string $baseKey): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! array_key_exists($baseKey, self::JOB_COMMANDS)) {
            return redirect()->to('/admin/schedule')->with('error', '존재하지 않는 배치 키입니다.');
        }

        $cron = trim((string) $this->request->getPost('cron'));

        if (! preg_match('/^(\S+\s+){4}\S+$/', $cron)) {
            return redirect()->to('/admin/schedule')->with('error', '올바른 크론 표현식을 입력하세요. (예: */5 * * * *)');
        }

        $this->settingModel->saveSettings([$baseKey . '_cron' => $cron]);

        $command = self::JOB_COMMANDS[$baseKey];
        return redirect()->to('/admin/schedule')->with('success', "{$command} 실행 주기가 저장되었습니다.");
    }
}
