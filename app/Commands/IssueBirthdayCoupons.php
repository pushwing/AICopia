<?php

namespace App\Commands;

use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class IssueBirthdayCoupons extends BaseCommand
{
    protected $group       = 'Members';
    protected $name        = 'coupons:birthday';
    protected $description = '생일인 회원에게 생일 쿠폰을 자동 발급합니다 (매일 실행).';

    public function run(array $params): void
    {
        $settings = (new SettingModel())->getAllAsMap();

        if (! (bool) ($settings['schedule_coupons_birthday_enabled'] ?? 1)) {
            CLI::write('[coupons:birthday] 비활성화됨 — 스킵', 'yellow');
            return;
        }

        $couponId = (int) ($settings['coupon_birthday_id'] ?? 0);

        if ($couponId <= 0) {
            CLI::write('[coupons:birthday] coupon_birthday_id 설정 없음 — 스킵', 'yellow');
            return;
        }

        $db     = \Config\Database::connect();
        $coupon = $db->table('coupons')->where('id', $couponId)->where('is_active', 1)->get()->getRowArray();

        if (! $coupon) {
            CLI::write("[coupons:birthday] coupon_id={$couponId} 없음 또는 비활성 — 스킵", 'yellow');
            return;
        }

        // total_qty 소진 확인
        if ($coupon['total_qty'] !== null && (int) $coupon['used_count'] >= (int) $coupon['total_qty']) {
            CLI::write('[coupons:birthday] 쿠폰 수량 소진 — 스킵', 'yellow');
            return;
        }

        $today   = date('m-d');  // 월-일 비교
        $now     = date('Y-m-d H:i:s');
        $todayYmd = date('Y-m-d');

        // 오늘 생일인 활성 회원 조회
        $users = $db->query(
            "SELECT id, email, nickname FROM users
             WHERE is_active = 1
               AND birthday IS NOT NULL
               AND DATE_FORMAT(birthday, '%m-%d') = ?",
            [$today]
        )->getResultArray();

        $issued = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $uid = (int) $user['id'];

            // 오늘 이미 같은 쿠폰 발급됐으면 스킵
            $alreadyToday = $db->table('user_coupons')
                ->where('user_id', $uid)
                ->where('coupon_id', $couponId)
                ->where('DATE(issued_at)', $todayYmd)
                ->countAllResults();

            if ($alreadyToday > 0) {
                $skipped++;
                continue;
            }

            // total_qty 재확인 (루프 중 소진 방지)
            if ($coupon['total_qty'] !== null) {
                $usedNow = (int) $db->table('user_coupons')->where('coupon_id', $couponId)->countAllResults();
                if ($usedNow >= (int) $coupon['total_qty']) {
                    CLI::write('[coupons:birthday] 쿠폰 수량 소진으로 중단', 'yellow');
                    break;
                }
            }

            $db->table('user_coupons')->insert([
                'user_id'    => $uid,
                'coupon_id'  => $couponId,
                'source'     => 'admin',
                'status'     => 'issued',
                'issued_at'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $issued++;
            log_message('info', "[coupons:birthday] user_id={$uid} 생일쿠폰 발급 coupon_id={$couponId}");
        }

        CLI::write("[coupons:birthday] 대상 " . count($users) . "명 / 발급 {$issued}명 / 스킵 {$skipped}명", 'green');
        log_message('info', "[coupons:birthday] 대상=" . count($users) . " 발급={$issued} 스킵={$skipped}");
    }
}
