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
        $total = $this->db->table('point_logs')->where('user_id', $userId)->countAllResults();

        // 주문에서 비롯된 로그(사용·적립 등)는 주문 상세로 이어줄 수 있게 주문번호를 함께 가져온다.
        // 주문이 지워졌으면 order_number 는 null 로 남는다.
        $items = $this->db->table('point_logs pl')
            ->select('pl.id, pl.user_id, pl.type, pl.amount, pl.order_id, pl.note, pl.created_at, o.order_number')
            ->join('orders o', 'o.id = pl.order_id', 'left')
            ->where('pl.user_id', $userId)
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

    /** 주문의 earn 로그가 이미 확정됐는지 확인 */
    public function hasEarned(int $orderId): bool
    {
        return $this->where('order_id', $orderId)->where('type', 'earn')->countAllResults() > 0;
    }
}
