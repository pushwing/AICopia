<?php

namespace App\Models;

use CodeIgniter\Model;

class StockLogModel extends Model
{
    protected $table        = 'stock_logs';
    protected $primaryKey   = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'product_id', 'type', 'quantity', 'stock_before', 'stock_after', 'note', 'admin_id', 'created_at',
    ];

    public const TYPES = [
        'adjust' => '재고 조정',
        'order'  => '주문 차감',
        'cancel' => '주문 취소',
        'return' => '반품',
        'in'     => '입고',
        'out'    => '출고',
    ];

    public function record(int $productId, string $type, int $quantity, int $before, int $after, ?string $note = null, ?int $adminId = null): void
    {
        $this->insert([
            'product_id'   => $productId,
            'type'         => $type,
            'quantity'     => $quantity,
            'stock_before' => $before,
            'stock_after'  => $after,
            'note'         => $note,
            'admin_id'     => $adminId,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function getByProduct(int $productId, int $limit = 30): array
    {
        return $this->select('stock_logs.*, users.nickname as admin_name')
            ->join('users', 'users.id = stock_logs.admin_id', 'left')
            ->where('product_id', $productId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}
