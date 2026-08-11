<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\OrderAttemptModel;
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
        $settings = new SettingModel()->getAllAsMap();
        if (! (bool) ($settings['schedule_orders_expire_enabled'] ?? 1)) {
            CLI::write('[orders:expire] 비활성화됨 — 스킵', 'yellow');

            return;
        }

        $minutes = (int) ($params[0] ?? 30);

        $attempts = new OrderAttemptModel()->expireStale($minutes);

        // 배포 전에 만들어진 orders.pending 행은 쿠폰·포인트를 선점한 상태다.
        // 이 호출을 빼면 그 선점이 영구히 잠긴다. 레거시 pending 이 0건이 된 걸
        // 확인한 뒤 제거한다.
        // TODO(#214): 다음 릴리스에서 아래 레거시 만료 호출을 제거한다.
        $legacy = new OrderModel()->expirePending($minutes);

        CLI::write("[orders:expire] 시도 {$attempts}건 / 레거시 주문 {$legacy}건 만료 처리 ({$minutes}분 초과)", 'green');
        log_message('info', "[orders:expire] 시도 {$attempts}건 / 레거시 주문 {$legacy}건 만료 처리");
    }
}
