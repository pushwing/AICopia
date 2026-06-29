<?php

namespace App\Models;

use CodeIgniter\Model;

class RestockAlertModel extends Model
{
    protected $table      = 'restock_alerts';
    protected $primaryKey = 'id';
    protected $allowedFields = ['product_id', 'user_id', 'email', 'notified_at', 'created_at'];
    protected $useTimestamps = false;

    public function exists(int $productId, string $email): bool
    {
        return $this->where('product_id', $productId)
                    ->where('email', $email)
                    ->countAllResults() > 0;
    }

    public function getPending(int $productId): array
    {
        return $this->where('product_id', $productId)
                    ->where('notified_at IS NULL', null, false)
                    ->findAll();
    }

    public function markNotified(int $productId): void
    {
        $this->where('product_id', $productId)
             ->where('notified_at IS NULL', null, false)
             ->set('notified_at', date('Y-m-d H:i:s'))
             ->update();
    }
}
