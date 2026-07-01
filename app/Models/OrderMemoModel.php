<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class OrderMemoModel extends Model
{
    #[\Override]
    protected $table         = 'order_memos';
    #[\Override]
    protected $primaryKey    = 'id';
    #[\Override]
    protected $useTimestamps = true;
    #[\Override]
    protected $allowedFields = ['order_id', 'admin_id', 'content'];

    /** @return array<int, array<string, mixed>> */
    public function getByOrder(int $orderId): array
    {
        return $this->db->table('order_memos om')
            ->select('om.*, u.nickname AS admin_name')
            ->join('users u', 'u.id = om.admin_id', 'left')
            ->where('om.order_id', $orderId)
            ->orderBy('om.id', 'ASC')
            ->get()->getResultArray();
    }
}
