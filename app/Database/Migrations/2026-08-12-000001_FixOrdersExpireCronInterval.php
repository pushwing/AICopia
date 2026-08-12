<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 주문 만료 배치의 크론 주기를 5분으로 되돌린다.
 *
 * 이슈 #214 로 결제 확정 전 주문이 order_attempts 로 분리되면서 이 배치의 역할이
 * 커졌다. 결제 실패·이탈은 즉시 복구되지만, 사용자가 브라우저를 그냥 종료하거나
 * 나이스페이처럼 취소 URL 이 없는 PG 는 어떤 콜백도 오지 않아 이 배치가 선점된
 * 쿠폰·포인트를 회수하는 유일한 경로다.
 *
 * 시드 기본값은 원래 5분 주기인데(2026-06-17-000043_SeedScheduleCronSettings)
 * 운영·개발 DB 모두 '0 1 * * *'(하루 1회 새벽 1시)로 바뀌어 있었다. 그 상태에서는
 * 브라우저가 죽은 사용자의 쿠폰이 최대 24시간 잠긴다.
 *
 * 값이 '0 1 * * *' 인 경우에만 되돌린다 — 운영자가 그 사이 다른 주기를 의도적으로
 * 지정했다면 그 선택을 덮어쓰지 않기 위해서다.
 */
class FixOrdersExpireCronInterval extends Migration
{
    private const string KEY      = 'schedule_orders_expire_cron';
    private const string STALE    = '0 1 * * *';
    private const string INTENDED = '*/5 * * * *';

    public function up(): void
    {
        $this->db->table('settings')
            ->where('key', self::KEY)
            ->where('value', self::STALE)
            ->update(['value' => self::INTENDED, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        $this->db->table('settings')
            ->where('key', self::KEY)
            ->where('value', self::INTENDED)
            ->update(['value' => self::STALE, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
