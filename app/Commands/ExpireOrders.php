<?php

namespace App\Commands;

use App\Models\OrderModel;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ExpireOrders extends BaseCommand
{
    protected $group       = 'Orders';
    protected $name        = 'orders:expire';
    protected $description = '결제 대기 30분 초과 주문을 만료 처리합니다.';

    public function run(array $params): void
    {
        $settings = (new SettingModel())->getAllAsMap();
        if (! (bool) ($settings['schedule_orders_expire_enabled'] ?? 1)) {
            CLI::write('[orders:expire] 비활성화됨 — 스킵', 'yellow');
            return;
        }

        $minutes = (int) ($params[0] ?? 30);
        $count   = (new OrderModel())->expirePending($minutes);

        CLI::write("[orders:expire] {$count}건 만료 처리 완료 ({$minutes}분 초과)", 'green');
        log_message('info', "[orders:expire] {$count}건 만료 처리");
    }
}
