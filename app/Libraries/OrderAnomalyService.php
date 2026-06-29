<?php

namespace App\Libraries;

/**
 * 이상 주문(사기·어뷰징 의심) 탐지 — 휴리스틱 규칙 기반.
 *
 * IP 정보가 없는 환경이라 주문 데이터로 판정 가능한 신호만 사용한다.
 *   R1 고액 주문      : payable_amount >= 임계값(설정 anomaly_high_amount)
 *   R2 단시간 다건    : 같은 회원이 BURST_MINUTES 내 BURST_COUNT건 이상
 *   R3 동일 연락처·다계정: 같은 수취 연락처를 2개 이상 계정이 사용
 *
 * LLM을 쓰지 않아 비용·지연이 없고 결정적이다.
 */
class OrderAnomalyService
{
    /** 기본 고액 임계값(원) */
    public const HIGH_AMOUNT_DEFAULT = 1000000;

    /** 단시간 다건 판정 */
    public const BURST_COUNT   = 3;
    public const BURST_MINUTES = 60;

    /** 조회 기간 상한(일) — 전체 이력 풀스캔 방지 */
    public const MAX_DAYS = 365;

    /** 탐지 대상 주문 상태 (취소·만료 제외한 유효 주문) */
    private const ACTIVE_STATUSES = [
        'pending', 'awaiting_payment', 'paid', 'preparing', 'shipped', 'delivered',
        'refund_requested', 'return_requested',
    ];

    /**
     * 최근 N일 내 이상 신호가 잡힌 주문 목록 (위험도 순).
     *
     * @return array<int,array<string,mixed>>
     */
    public function flagged(int $days = 7): array
    {
        $days  = min(self::MAX_DAYS, max(1, $days));
        $db    = \Config\Database::connect();
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $orders = $db->table('orders')
            ->select('id, order_number, user_id, receiver_name, receiver_phone, payable_amount, status, created_at')
            ->where('created_at >=', $since)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        if ($orders === []) {
            return [];
        }

        $highThreshold = (int) (model('SettingModel')->getAllAsMap()['anomaly_high_amount'] ?? 0)
            ?: self::HIGH_AMOUNT_DEFAULT;

        $burstOrderIds = $this->detectBurst($orders);
        $multiAcctIds  = $this->detectMultiAccountPhone($orders);

        $flagged = [];
        foreach ($orders as $o) {
            $reasons = [];

            if ((int) $o['payable_amount'] >= $highThreshold) {
                $reasons[] = '고액 주문 (' . number_format((int) $o['payable_amount']) . '원)';
            }
            if (isset($burstOrderIds[(int) $o['id']])) {
                $reasons[] = '단시간 연속 주문';
            }
            if (isset($multiAcctIds[(int) $o['id']])) {
                $reasons[] = '동일 연락처·다계정';
            }

            if ($reasons === []) {
                continue;
            }

            $flagged[] = [
                'id'           => (int) $o['id'],
                'order_number' => $o['order_number'],
                'user_id'      => $o['user_id'] !== null ? (int) $o['user_id'] : null,
                'receiver_name'=> $o['receiver_name'],
                'receiver_phone'=> $o['receiver_phone'],
                'amount'       => (int) $o['payable_amount'],
                'status'       => $o['status'],
                'created_at'   => $o['created_at'],
                'reasons'      => $reasons,
                'risk'         => count($reasons),
            ];
        }

        // 위험도 높은 순 → 최신 순
        usort($flagged, fn ($a, $b) => [$b['risk'], $b['created_at']] <=> [$a['risk'], $a['created_at']]);

        return $flagged;
    }

    /**
     * 같은 회원이 BURST_MINUTES 내 BURST_COUNT건 이상 주문한 주문 id 집합.
     *
     * @return array<int,true>
     */
    private function detectBurst(array $orders): array
    {
        $byUser = [];
        foreach ($orders as $o) {
            if ($o['user_id'] === null) {
                continue;
            }
            $byUser[(int) $o['user_id']][] = ['id' => (int) $o['id'], 't' => strtotime($o['created_at'])];
        }

        $flagged = [];
        $windowSec = self::BURST_MINUTES * 60;
        foreach ($byUser as $list) {
            $n = count($list);
            if ($n < self::BURST_COUNT) {
                continue;
            }
            // 이미 시간 오름차순(쿼리 정렬) — 슬라이딩 윈도우
            for ($i = 0; $i < $n; $i++) {
                $j = $i;
                while ($j < $n && $list[$j]['t'] - $list[$i]['t'] <= $windowSec) {
                    $j++;
                }
                if ($j - $i >= self::BURST_COUNT) {
                    for ($k = $i; $k < $j; $k++) {
                        $flagged[$list[$k]['id']] = true;
                    }
                }
            }
        }

        return $flagged;
    }

    /**
     * 같은 수취 연락처를 2개 이상 계정이 사용한 주문 id 집합.
     *
     * @return array<int,true>
     */
    private function detectMultiAccountPhone(array $orders): array
    {
        $usersByPhone  = [];
        $ordersByPhone = [];
        foreach ($orders as $o) {
            // 하이픈·공백 등 표기 차이로 우회/오탐되지 않도록 숫자만 남겨 비교
            $phone = preg_replace('/\D/', '', (string) $o['receiver_phone']);
            if ($phone === '' || $o['user_id'] === null) {
                continue;
            }
            $usersByPhone[$phone][(int) $o['user_id']] = true;
            $ordersByPhone[$phone][] = (int) $o['id'];
        }

        $flagged = [];
        foreach ($usersByPhone as $phone => $users) {
            if (count($users) >= 2) {
                foreach ($ordersByPhone[$phone] as $oid) {
                    $flagged[$oid] = true;
                }
            }
        }

        return $flagged;
    }
}
