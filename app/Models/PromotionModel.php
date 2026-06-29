<?php

namespace App\Models;

use CodeIgniter\Model;

class PromotionModel extends Model
{
    protected $table         = 'promotions';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'title', 'slug', 'description', 'banner_image',
        'grade_access', 'start_date', 'end_date', 'is_active', 'sort_order',
    ];

    private const GRADE_ORDER = [
        'all' => 0, 'bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4,
    ];

    public function getList(): array
    {
        return $this->orderBy('sort_order', 'ASC')->orderBy('id', 'DESC')->findAll();
    }

    /** 프론트용 — 활성 기획전 목록 (날짜 유효한 것만) */
    public function getActiveFrontList(): array
    {
        $today = date('Y-m-d');
        return $this->where('is_active', 1)
            ->groupStart()
                ->where('start_date IS NULL', null, false)
                ->orWhere('start_date <=', $today)
            ->groupEnd()
            ->groupStart()
                ->where('end_date IS NULL', null, false)
                ->orWhere('end_date >=', $today)
            ->groupEnd()
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'DESC')
            ->findAll();
    }

    /** 프론트용 — 활성·날짜 유효 기획전 반환 */
    public function getActiveBySlug(string $slug): ?array
    {
        $today = date('Y-m-d');
        return $this->where('slug', $slug)
            ->where('is_active', 1)
            ->groupStart()
                ->where('start_date IS NULL', null, false)
                ->orWhere('start_date <=', $today)
            ->groupEnd()
            ->groupStart()
                ->where('end_date IS NULL', null, false)
                ->orWhere('end_date >=', $today)
            ->groupEnd()
            ->first();
    }

    /** 기획전 상품 목록 (기본 이미지 포함) */
    public function getProducts(int $promotionId): array
    {
        $items = $this->db->table('promotion_products pp')
            ->select('p.id, p.name, p.slug, p.price, p.discount_price, p.stock, p.status, pp.sort_order')
            ->join('products p', 'p.id = pp.product_id')
            ->where('pp.promotion_id', $promotionId)
            ->orderBy('pp.sort_order', 'ASC')
            ->get()->getResultArray();

        if ($items) {
            $imageModel = new ProductImageModel();
            $imageModel->attachPrimaryImages($items);
        }

        return $items;
    }

    /** 기획전 상품 동기화 (전체 교체) */
    public function syncProducts(int $promotionId, array $products): void
    {
        $this->db->table('promotion_products')->where('promotion_id', $promotionId)->delete();
        foreach ($products as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) continue;
            $this->db->table('promotion_products')->insert([
                'promotion_id' => $promotionId,
                'product_id'   => $productId,
                'sort_order'   => (int) ($item['sort_order'] ?? 0),
            ]);
        }
    }

    /** 회원 등급이 기획전 접근 조건을 만족하는지 확인 */
    public function checkGradeAccess(string $required, ?string $userGrade): bool
    {
        if ($required === 'all') return true;
        if ($userGrade === null) return false;
        $req  = self::GRADE_ORDER[$required]   ?? 0;
        $user = self::GRADE_ORDER[$userGrade]  ?? 0;
        return $user >= $req;
    }
}
