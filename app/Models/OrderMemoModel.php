<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderMemoModel extends Model
{
    protected $table         = 'order_memos';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = ['order_id', 'admin_id', 'content'];

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
