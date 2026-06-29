<?php

namespace App\Libraries;

use App\Models\ProductImageModel;

/**
 * 회원 개인화 상품 추천 (휴리스틱: 카테고리 선호도 기반).
 *
 * 찜·구매 이력에서 선호 카테고리를 도출해 해당 카테고리의 추천/인기 상품을 노출한다.
 * 이력이 없거나 부족하면 추천(is_featured)·최신 상품으로 채운다.
 * LLM을 쓰지 않아 API 비용·지연이 없고, 결과는 회원별로 짧게 캐시한다.
 */
class RecommendationService
{
    /** 매출에 잡히는 주문 상태 (구매 이력으로 인정) */
    private const PURCHASED_STATUSES = [
        'paid', 'preparing', 'shipped', 'delivered', 'refund_requested', 'return_requested', 'return_approved',
    ];

    /** 회원별 캐시 TTL (초) */
    private const CACHE_TTL = 600;

    /**
     * 회원 추천 상품 목록 (primary_image 포함).
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 8): array
    {
        if ($userId <= 0) {
            return [];
        }

        $cache = service('cache');
        $key   = "reco_user_{$userId}_{$limit}";
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $items = $this->build($userId, $limit);
        $cache->save($key, $items, self::CACHE_TTL);

        return $items;
    }

    /** 회원의 추천 캐시를 무효화한다 (찜·구매 변경 시 호출). */
    public static function forget(int $userId): void
    {
        $cache = service('cache');
        foreach ([4, 8, 12] as $limit) {
            $cache->delete("reco_user_{$userId}_{$limit}");
        }
    }

    private function build(int $userId, int $limit): array
    {
        $db       = \Config\Database::connect();
        $ownedIds = $this->ownedProductIds($db, $userId);
        $prefCats = $this->preferredCategories($db, $ownedIds);

        $items = [];
        if ($prefCats !== []) {
            $builder = $db->table('products p')
                ->select('p.*')
                ->distinct()
                ->join('product_categories pc', 'pc.product_id = p.id')
                ->whereIn('pc.category_id', $prefCats)
                ->where('p.status', 'on_sale')
                ->where('p.deleted_at', null);
            if ($ownedIds !== []) {
                $builder->whereNotIn('p.id', $ownedIds);
            }
            $items = $builder->orderBy('p.is_featured', 'DESC')
                ->orderBy('p.id', 'DESC')
                ->limit($limit)
                ->get()->getResultArray();
        }

        // 부족하면 추천·최신 상품으로 채움 (신규 회원 폴백 포함)
        if (count($items) < $limit) {
            $exclude = array_map('intval', array_merge($ownedIds, array_column($items, 'id')));
            $fb = $db->table('products p')
                ->select('p.*')
                ->where('p.status', 'on_sale')
                ->where('p.deleted_at', null);
            if ($exclude !== []) {
                $fb->whereNotIn('p.id', $exclude);
            }
            $fbItems = $fb->orderBy('p.is_featured', 'DESC')
                ->orderBy('p.id', 'DESC')
                ->limit($limit - count($items))
                ->get()->getResultArray();
            $items = array_merge($items, $fbItems);
        }

        (new ProductImageModel())->attachPrimaryImages($items);

        return $items;
    }

    /** 사용자가 찜했거나 구매한 상품 id 집합. */
    private function ownedProductIds($db, int $userId): array
    {
        $wished = $db->table('wishlists')
            ->select('product_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();

        $bought = $db->table('order_items oi')
            ->select('oi.product_id')
            ->join('orders o', 'o.id = oi.order_id')
            ->where('o.user_id', $userId)
            ->whereIn('o.status', self::PURCHASED_STATUSES)
            ->get()->getResultArray();

        $ids = array_merge(
            array_column($wished, 'product_id'),
            array_column($bought, 'product_id')
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** 보유 상품들의 카테고리 빈도 상위 5개. */
    private function preferredCategories($db, array $ownedIds): array
    {
        if ($ownedIds === []) {
            return [];
        }

        $rows = $db->table('product_categories')
            ->select('category_id, COUNT(*) AS score')
            ->whereIn('product_id', $ownedIds)
            ->groupBy('category_id')
            ->orderBy('score', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        return array_map(fn ($r) => (int) $r['category_id'], $rows);
    }
}
