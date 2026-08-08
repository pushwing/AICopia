<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 장바구니·주문 라인의 단가 계산 규칙을 한곳에 모은다.
 *
 * 이 계산이 여러 곳에 흩어져 있으면 일부만 SKU 추가금을 더하게 되고,
 * 실제로 청구되는 금액과 주문에 기록되는 금액이 어긋난다 (이슈 #124).
 * 장바구니 표시가·체크아웃 합계·PG 청구액·order_items 는 모두 여기를 거친다.
 */
final class ItemPricing
{
    /**
     * 옵션 추가금까지 반영한 실제 단가.
     *
     * 할인가가 있으면 할인가를, 없으면 정가를 기준으로 삼고 SKU 추가금을 더한다.
     * 추가금은 음수(옵션 할인)일 수 있다.
     *
     * @param array<string, mixed> $item
     */
    public static function unitPrice(array $item): int
    {
        $basePrice = (int) ($item['discount_price'] ?? $item['price']);
        $priceDiff = (int) ($item['price_diff'] ?? 0);

        return $basePrice + $priceDiff;
    }

    /**
     * 수량까지 반영한 라인 합계.
     *
     * @param array<string, mixed> $item
     */
    public static function subtotal(array $item): int
    {
        return self::unitPrice($item) * (int) $item['qty'];
    }

    /**
     * 라인 목록의 상품 금액 합계 (배송비 제외).
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function totalProductPrice(array $items): int
    {
        return array_sum(array_map(self::subtotal(...), $items));
    }
}
