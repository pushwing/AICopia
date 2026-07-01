<?php

declare(strict_types=1);

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

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
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

    /**
     * FAQPage 구조화 데이터용 — 답변 완료된 공개(비밀 아님) Q&A의 질문·답변만 반환.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getPublicAnswered(int $productId, int $limit = 20): array
    {
        $rows = $this->select('title, answer')
            ->where('product_id', $productId)
            ->where('is_answered', 1)
            ->where('is_secret', 0)
            ->where('answer IS NOT NULL')
            ->orderBy('answered_at', 'DESC')
            ->findAll($limit);

        return array_map(
            static fn (array $r): array => [
                'question' => (string) $r['title'],
                'answer'   => (string) ($r['answer'] ?? ''),
            ],
            $rows
        );
    }

    /**
     * @param  array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, perPage: int}
     */
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
