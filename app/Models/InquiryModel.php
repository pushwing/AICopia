<?php

namespace App\Models;

use CodeIgniter\Model;

class InquiryModel extends Model
{
    protected $table      = 'inquiries';
    protected $primaryKey = 'id';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = [
        'name', 'email', 'phone', 'subject', 'message', 'ip_address', 'is_read',
        'category', 'priority', 'sentiment',
    ];

    public function getUnreadCount(): int
    {
        return (int) $this->db->table($this->table)->where('is_read', 0)->countAllResults();
    }

    /** AI 분류 결과를 저장한다. */
    public function applyClassification(int $id, array $classification): bool
    {
        return $this->update($id, [
            'category'  => $classification['category']  ?? 'etc',
            'priority'  => $classification['priority']  ?? 'normal',
            'sentiment' => $classification['sentiment'] ?? 'neutral',
        ]);
    }

    public function markRead(int $id): void
    {
        $this->update($id, ['is_read' => 1]);
    }
}
