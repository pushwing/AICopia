<?php

namespace App\Models;

use CodeIgniter\Model;

class WishlistModel extends Model
{
    protected $table         = 'wishlists';
    protected $allowedFields = ['user_id', 'product_id'];
    protected $useTimestamps = true;
    protected $updatedField  = '';

    /** 찜 토글 — true: 추가됨, false: 제거됨 */
    public function toggle(int $userId, int $productId): bool
    {
        $existing = $this->where('user_id', $userId)
                         ->where('product_id', $productId)
                         ->first();

        // 찜 변경 → 개인화 추천 캐시 무효화
        \App\Libraries\RecommendationService::forget($userId);

        if ($existing) {
            $this->delete($existing['id']);
            return false;
        }

        $this->insert(['user_id' => $userId, 'product_id' => $productId]);
        return true;
    }

    public function isWished(int $userId, int $productId): bool
    {
        return (bool) $this->where('user_id', $userId)
                           ->where('product_id', $productId)
                           ->first();
    }

    /** 마이페이지 찜 목록 (상품 정보 JOIN, 페이지네이션) */
    public function getByUser(int $userId, int $page = 1, int $perPage = 12): array
    {
        $db      = \Config\Database::connect();
        $offset  = ($page - 1) * $perPage;

        $base = $db->table('wishlists w')
            ->select('p.id, p.name, p.slug, p.price, p.discount_price, p.status, p.stock,
                      p.shipping_type, p.free_threshold,
                      w.created_at AS wished_at,
                      (SELECT file_path FROM media m
                         JOIN product_images pi ON pi.media_id = m.id
                        WHERE pi.product_id = p.id AND pi.is_primary = 1
                        LIMIT 1) AS primary_image')
            ->join('products p', 'p.id = w.product_id')
            ->where('w.user_id', $userId)
            ->where('p.deleted_at IS NULL', null, false);

        $total = (clone $base)->countAllResults();
        $items = $base->orderBy('w.id', 'DESC')
                      ->limit($perPage, $offset)
                      ->get()->getResultArray();

        return [
            'items'       => $items,
            'total'       => $total,
            'currentPage' => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int) ceil($total / $perPage),
        ];
    }
}
