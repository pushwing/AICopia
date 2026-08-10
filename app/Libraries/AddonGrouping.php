<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 주문·장바구니 항목을 "본품 → 그 본품의 추가구성상품" 순서로 늘어놓는다.
 *
 * parent_product_id 는 상품 id 를 가리키므로, 같은 본품이 옵션만 다르게 두 줄
 * 들어간 경우 애드온은 첫 줄 아래로 묶인다. 포장 단위를 알아보는 데는 충분하다.
 */
final class AddonGrouping
{
    /**
     * @param  array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function order(array $items): array
    {
        $parents  = [];
        $children = [];

        foreach ($items as $item) {
            $parentId = isset($item['parent_product_id']) ? (int) $item['parent_product_id'] : 0;
            if ($parentId > 0) {
                $children[$parentId][] = $item;
                continue;
            }
            $parents[] = $item;
        }

        $ordered = [];
        foreach ($parents as $parent) {
            $parent['is_addon'] = false;
            $ordered[]          = $parent;

            $productId = (int) $parent['product_id'];
            if (! isset($children[$productId])) {
                continue;
            }

            foreach ($children[$productId] as $child) {
                $child['is_addon'] = true;
                $ordered[]         = $child;
            }
            unset($children[$productId]);
        }

        // 본품을 못 찾은 애드온은 일반 항목으로 끝에 붙인다.
        foreach ($children as $orphans) {
            foreach ($orphans as $orphan) {
                $orphan['is_addon'] = false;
                $ordered[]          = $orphan;
            }
        }

        return $ordered;
    }
}
