<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductQnaModel extends Model
{
    protected $table         = 'product_qnas';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'product_id', 'user_id', 'title', 'content',
        'is_secret', 'is_answered', 'answer', 'answered_at', 'answered_by',
    ];

    public function getByProduct(int $productId, int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;

        $items = $this->db->table('product_qnas q')
            ->select('q.*, u.nickname, u.username')
            ->join('users u', 'u.id = q.user_id')
            ->where('q.product_id', $productId)
            ->orderBy('q.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        $total = $this->where('product_id', $productId)->countAllResults();

        return compact('items', 'total');
    }

    public function adminGetAll(array $params = []): array
    {
        $keyword  = trim($params['keyword'] ?? '');
        $answered = $params['answered'] ?? '';
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = 20;

        $builder = $this->db->table('product_qnas q')
            ->select('q.*, p.name AS product_name, p.slug AS product_slug, u.nickname, u.username')
            ->join('products p', 'p.id = q.product_id')
            ->join('users u', 'u.id = q.user_id');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('q.title', $keyword)
                ->orLike('u.nickname', $keyword)
                ->orLike('p.name', $keyword)
            ->groupEnd();
        }

        if ($answered === '0') {
            $builder->where('q.is_answered', 0);
        } elseif ($answered === '1') {
            $builder->where('q.is_answered', 1);
        }

        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('q.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return compact('items', 'total', 'page', 'perPage');
    }

    public function getUnansweredCount(): int
    {
        return (int) $this->where('is_answered', 0)->countAllResults();
    }
}
