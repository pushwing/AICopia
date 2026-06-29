<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductReviewModel extends Model
{
    protected $table         = 'product_reviews';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'product_id', 'order_id', 'user_id', 'content', 'is_rewarded', 'is_hidden', 'is_negative',
    ];

    /** AI 요약용 — 노출 중인 리뷰의 id·content만 최신순으로 반환 */
    public function getForSummary(int $productId, int $limit = 50): array
    {
        return $this->db->table('product_reviews')
            ->select('id, content')
            ->where('product_id', $productId)
            ->where('is_hidden', 0)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /** 상품의 부정 리뷰 표시를 갱신한다 (전체 0으로 리셋 후 지정 id만 1). */
    public function markNegative(int $productId, array $negativeIds): void
    {
        $this->db->table('product_reviews')
            ->where('product_id', $productId)
            ->update(['is_negative' => 0]);

        $negativeIds = array_values(array_filter(array_map('intval', $negativeIds)));
        if ($negativeIds !== []) {
            $this->db->table('product_reviews')
                ->where('product_id', $productId)
                ->whereIn('id', $negativeIds)
                ->update(['is_negative' => 1]);
        }
    }

    /** 상품별 리뷰 목록 (이미지 포함) */
    public function getByProduct(int $productId, int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;

        $items = $this->db->table('product_reviews r')
            ->select('r.*, u.nickname, u.username')
            ->join('users u', 'u.id = r.user_id')
            ->where('r.product_id', $productId)
            ->where('r.is_hidden', 0)
            ->orderBy('r.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        $total = $this->where('product_id', $productId)->where('is_hidden', 0)->countAllResults();

        if ($items) {
            $ids           = array_column($items, 'id');
            $imageRows     = $this->db->table('product_review_images')
                ->whereIn('review_id', $ids)
                ->orderBy('sort_order', 'ASC')
                ->get()->getResultArray();
            $imagesByReview = [];
            foreach ($imageRows as $img) {
                $imagesByReview[(int) $img['review_id']][] = $img;
            }
            foreach ($items as &$item) {
                $item['images'] = $imagesByReview[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return compact('items', 'total');
    }

    /**
     * 리뷰 작성 가능 여부 확인.
     * 배송완료 주문이 있고 해당 주문에 리뷰를 아직 작성하지 않은 경우 order_id 반환.
     */
    public function canWriteReview(int $userId, int $productId): ?int
    {
        $row = $this->db->query(
            "SELECT o.id
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.id AND oi.product_id = ?
             WHERE o.user_id = ? AND o.status = 'delivered'
               AND NOT EXISTS (
                   SELECT 1 FROM product_reviews pr
                   WHERE pr.order_id = o.id AND pr.user_id = ?
               )
             LIMIT 1",
            [$productId, $userId, $userId]
        )->getRow();

        return $row ? (int) $row->id : null;
    }

    /** 리뷰 작성 후 포인트 지급 (사진 1장 이상 + 30자 이상) */
    public function grantPoints(int $reviewId, int $userId): void
    {
        $this->db->table('product_reviews')->where('id', $reviewId)->update(['is_rewarded' => 1]);
        $this->db->query(
            'UPDATE users SET point_balance = point_balance + 150 WHERE id = ?',
            [$userId]
        );
        $this->db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'earn',
            'amount'     => 150,
            'note'       => '리뷰 작성 포인트 적립',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 리뷰 삭제 — 이미지 파일·DB + 포인트 회수까지 처리 */
    public function deleteReview(int $reviewId, ?int $userId = null): bool
    {
        $builder = $this->where('id', $reviewId);
        if ($userId !== null) {
            $builder->where('user_id', $userId);
        }
        $review = $builder->first();
        if (! $review) return false;

        $images = $this->db->table('product_review_images')
            ->where('review_id', $reviewId)->get()->getResultArray();

        foreach ($images as $img) {
            $path = FCPATH . ltrim($img['image_path'], '/');
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->db->table('product_review_images')->where('review_id', $reviewId)->delete();

        if ((int) $review['is_rewarded'] === 1) {
            $this->db->query(
                'UPDATE users SET point_balance = GREATEST(0, point_balance - 150) WHERE id = ?',
                [$review['user_id']]
            );
            $this->db->table('point_logs')->insert([
                'user_id'    => $review['user_id'],
                'type'       => 'cancel',
                'amount'     => -150,
                'note'       => '리뷰 삭제로 인한 포인트 회수',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->delete($reviewId);
        return true;
    }

    /** 숨김/노출 토글 */
    public function toggleHidden(int $reviewId): int
    {
        $review = $this->find($reviewId);
        if (! $review) return -1;
        $next = ((int) $review['is_hidden']) === 0 ? 1 : 0;
        $this->update($reviewId, ['is_hidden' => $next]);
        return $next;
    }

    public function adminGetAll(array $params = []): array
    {
        $keyword = trim($params['keyword'] ?? '');
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = 20;

        $builder = $this->db->table('product_reviews r')
            ->select('r.*, p.name AS product_name, p.slug AS product_slug, u.nickname, u.username')
            ->join('products p', 'p.id = r.product_id')
            ->join('users u', 'u.id = r.user_id');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('r.content', $keyword)
                ->orLike('u.nickname', $keyword)
                ->orLike('p.name', $keyword)
            ->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('r.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        if ($items) {
            $ids        = array_column($items, 'id');
            $imageRows  = $this->db->table('product_review_images')
                ->whereIn('review_id', $ids)->get()->getResultArray();
            $byReview   = [];
            foreach ($imageRows as $img) {
                $byReview[(int) $img['review_id']][] = $img;
            }
            foreach ($items as &$item) {
                $item['images'] = $byReview[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return compact('items', 'total', 'page', 'perPage');
    }
}
