<?php

namespace App\Libraries;

use App\Models\ProductImageModel;

/**
 * 판매 추세 기반 발주(재입고) 제안 (휴리스틱).
 *
 * 최근 판매 속도(일평균 판매량)로 재고 소진 예상일과 권장 발주 수량을 계산한다.
 * LLM을 쓰지 않아 비용·지연이 없고 결정적이다.
 *
 *   일평균 판매량 = 기간 내 판매수량 / windowDays
 *   목표 재고     = ceil(일평균 × coverDays)
 *   권장 발주량   = max(0, 목표 재고 − 현재 재고)
 */
class RestockSuggestionService
{
    /** 판매 추세 산정 기간(일) */
    public const WINDOW_DAYS = 30;

    /** 목표 재고 커버 일수 */
    public const COVER_DAYS = 30;

    /** 판매(수요)로 인정할 주문 상태 */
    private const SOLD_STATUSES = ['paid', 'preparing', 'shipped', 'delivered'];

    /**
     * 발주가 필요한 상품 제안 목록 (권장 발주량 > 0).
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggestions(int $windowDays = self::WINDOW_DAYS, int $coverDays = self::COVER_DAYS): array
    {
        $windowDays = max(1, $windowDays);
        $coverDays  = max(1, $coverDays);

        $db    = \Config\Database::connect();
        $since = $db->escape(date('Y-m-d H:i:s', strtotime("-{$windowDays} days")));
        $statusList = "'" . implode("','", self::SOLD_STATUSES) . "'";

        $soldSub = "(SELECT oi.product_id, SUM(oi.qty) AS sold
                     FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id
                     WHERE o.status IN ({$statusList}) AND o.created_at >= {$since}
                     GROUP BY oi.product_id)";

        $rows = $db->table('products p')
            ->select('p.id, p.name, p.slug, p.stock, p.status, COALESCE(s.sold, 0) AS sold', false)
            ->join("{$soldSub} s", 's.product_id = p.id', 'inner', false)
            ->where('p.deleted_at IS NULL', null, false)
            ->where('p.status !=', 'hidden')
            ->get()->getResultArray();

        $suggestions = [];
        foreach ($rows as $r) {
            $sold = (int) $r['sold'];
            if ($sold <= 0) {
                continue;
            }
            $stock       = (int) $r['stock'];
            $daily       = $sold / $windowDays;
            $targetStock = (int) ceil($daily * $coverDays);
            $suggested   = max(0, $targetStock - $stock);

            if ($suggested <= 0) {
                continue; // 재고가 목표 이상이면 발주 불필요
            }

            $suggestions[] = [
                'id'             => (int) $r['id'],
                'name'           => $r['name'],
                'slug'           => $r['slug'],
                'status'         => $r['status'],
                'stock'          => $stock,
                'sold'           => $sold,
                'daily_velocity' => round($daily, 1),
                'days_remaining' => $daily > 0 ? round($stock / $daily, 1) : 0.0,
                'suggested_qty'  => $suggested,
            ];
        }

        // 소진 임박(잔여일 적은) 순 → 판매량 많은 순
        usort($suggestions, function ($a, $b) {
            return [$a['days_remaining'], -$a['sold']] <=> [$b['days_remaining'], -$b['sold']];
        });

        if ($suggestions !== []) {
            (new ProductImageModel())->attachPrimaryImages($suggestions);
        }

        return $suggestions;
    }
}
