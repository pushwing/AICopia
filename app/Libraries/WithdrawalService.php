<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 회원탈퇴 유스케이스
 *
 * users 행은 삭제하지 않고 마스킹한다. 이 DB 에는 외래키 제약이 없어서
 * 행을 지워도 에러는 안 나지만 orders·product_reviews·posts 등 8개 테이블의
 * user_id 가 조용히 고아가 되기 때문이다.
 *
 * WithdrawnUserModel 주입은 이 클래스를 실제로 사용하는 withdraw() 구현
 * 태스크에서 추가한다 — 아직 쓰이지 않는 프로퍼티를 미리 넣으면
 * PHPStan(property.onlyWritten)이 막는다.
 */
class WithdrawalService
{
    /** 결제·배송이 진행 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_ORDER_STATUSES = [
        'pending', 'awaiting_payment', 'paid', 'preparing', 'shipped',
    ];

    /** 반품·교환·환불 처리 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_CLAIM_STATUSES = [
        'refund_requested', 'return_requested', 'return_approved',
        'exchange_requested', 'exchange_approved',
    ];

    /**
     * 탈퇴 가능 여부 판정
     *
     * @param array<string, mixed> $user users 행
     *
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function canWithdraw(array $user): array
    {
        $reasons = [];

        if (($user['role'] ?? 'member') === 'admin') {
            $reasons[] = '관리자 계정은 탈퇴할 수 없습니다.';
        }

        $counts = $this->countBlockingOrders((int) $user['id']);

        if ($counts['order'] > 0) {
            $reasons[] = "진행 중인 주문이 {$counts['order']}건 있습니다. 배송 완료 후 탈퇴해 주세요.";
        }
        if ($counts['claim'] > 0) {
            $reasons[] = "처리 중인 반품·교환·환불이 {$counts['claim']}건 있습니다.";
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * 차단 대상 주문을 한 번의 조회로 상태별 집계 (N+1 방지)
     *
     * @return array{order: int, claim: int}
     */
    private function countBlockingOrders(int $userId): array
    {
        $all = array_merge(self::BLOCKING_ORDER_STATUSES, self::BLOCKING_CLAIM_STATUSES);

        $rows = db_connect()->table('orders')
            ->select('status, COUNT(*) AS cnt')
            ->where('user_id', $userId)
            ->whereIn('status', $all)
            ->groupBy('status')
            ->get()->getResultArray();

        $order = 0;
        $claim = 0;
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            if (in_array($row['status'], self::BLOCKING_ORDER_STATUSES, true)) {
                $order += $cnt;
            } else {
                $claim += $cnt;
            }
        }

        return ['order' => $order, 'claim' => $claim];
    }
}
