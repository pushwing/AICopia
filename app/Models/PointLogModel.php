<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PointLogModel extends Model
{
    protected $table         = 'point_logs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'user_id', 'type', 'amount', 'order_id', 'note', 'created_at',
    ];

    public const TYPES = [
        'use'    => '사용',
        'earn'   => '적립',
        'refund' => '환급',
        'cancel' => '적립 취소',
        'admin'  => '관리자 조정',
    ];

    public function record(int $userId, string $type, int $amount, ?int $orderId = null, ?string $note = null): void
    {
        $this->insert([
            'user_id'    => $userId,
            'type'       => $type,
            'amount'     => $amount,
            'order_id'   => $orderId,
            'note'       => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int, totalPages: int, currentPage: int, perPage: int} */
    public function getByUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $total = $this->visibleForUser($userId)->countAllResults();

        // 주문에서 비롯된 로그(사용·적립 등)는 주문 상세로 이어줄 수 있게 주문번호를 함께 가져온다.
        // 주문이 지워졌으면 order_number 는 null 로 남는다.
        //
        // 결제 확정 전 레거시 pending 주문은 상세가 열리지 않으므로(OrderModel::getWithItems)
        // JOIN 단계에서 빼 주문번호와 링크가 아예 생기지 않게 한다 — 남겨두면 목록 어디에도
        // 없는 주문번호가 여기서만 새어나오고 죽은 링크가 된다. 만료(expired) 주문은
        // "취소/환불" 탭에서 상세가 열리므로 그대로 둔다. (이슈 #214)
        $items = $this->visibleForUser($userId)
            ->select('pl.id, pl.user_id, pl.type, pl.amount, pl.order_id, pl.note, pl.created_at, o.order_number')
            ->join('orders o', "o.id = pl.order_id AND o.status != 'pending'", 'left')
            ->orderBy('pl.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'       => $items,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }

    /**
     * 관리자 회원 상세 "포인트" 탭용 최근 내역.
     *
     * 마이페이지와 같은 기준으로 걸러야 CS 응대 중 두 화면의 내역이 어긋나지 않는다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentByUser(int $userId, int $limit = 50): array
    {
        return $this->visibleForUser($userId)
            ->select('pl.type, pl.amount, pl.note, pl.created_at')
            ->orderBy('pl.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * 회원에게 보여줄 포인트 로그 빌더.
     *
     * 결제창을 열었다 닫기만 해도 선점(use)과 환급(refund)이 한 쌍씩 쌓인다
     * (OrderAttemptModel::preemptPoints / restorePoints). 합이 0이라 잔액은
     * 그대로인데 내역만 허수로 도배되므로, **끝내 주문이 되지 못한 시도**의
     * 로그는 목록에서 걷어낸다.
     *
     * 판정 기준을 "시도가 실패했는가"가 아니라 "주문이 남았는가"로 두는 이유:
     * PG 승인 후 전환에 실패한 시도는 status 가 failed 이지만 보상 주문이
     * 남고(OrderModel::compensateFailedConversion) 그 주문 앞으로 환급 로그가
     * 따로 생긴다. 그런 건까지 숨기면 선점만 사라져 근거 없는 환급 한 줄이
     * 남고 잔액과 내역 합계가 어긋난다.
     *
     * pending(결제창이 떠 있는 중)도 숨기지 않는다 — 잔액은 이미 차감된
     * 상태라 숨기면 "잔액은 줄었는데 내역이 없다"가 된다.
     */
    private function visibleForUser(int $userId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('point_logs pl')
            ->join('order_attempts oa', 'oa.id = pl.order_attempt_id', 'left')
            ->where('pl.user_id', $userId)
            ->where(
                "NOT (pl.order_attempt_id IS NOT NULL
                      AND pl.order_id IS NULL
                      AND oa.id IS NOT NULL
                      AND oa.order_id IS NULL
                      AND oa.status IN ('failed', 'expired'))",
                null,
                false
            );
    }

    /** 주문의 earn 로그가 이미 확정됐는지 확인 */
    public function hasEarned(int $orderId): bool
    {
        return $this->where('order_id', $orderId)->where('type', 'earn')->countAllResults() > 0;
    }
}
