<?php

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

    public function getByUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $builder = $this->db->table('point_logs')->where('user_id', $userId);

        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('id', 'DESC')
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
