<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * 본품 ↔ 추가구성상품 연결.
 *
 * 애드온은 별도 엔티티가 아니라 일반 상품이다. 이 모델은 "어떤 상품 상세에서
 * 어떤 상품을 부속으로 보여줄지"만 관리한다.
 */
class ProductAddonModel extends Model
{
    protected $table         = 'product_addons';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['product_id', 'addon_product_id', 'sort_order', 'created_at'];

    /**
     * 연결을 전량 교체한다. 자기 자신 연결과 중복은 버린다.
     *
     * @param array<int, int> $addonProductIds 노출할 순서대로
     */
    public function saveForProduct(int $productId, array $addonProductIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $addonProductIds),
            static fn (int $id): bool => $id > 0 && $id !== $productId,
        )));

        $this->db->transStart();

        $this->db->table('product_addons')->where('product_id', $productId)->delete();

        if ($ids !== []) {
            $now  = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($ids as $index => $addonId) {
                $rows[] = [
                    'product_id'       => $productId,
                    'addon_product_id' => $addonId,
                    'sort_order'       => $index,
                    'created_at'       => $now,
                ];
            }

            $this->db->table('product_addons')->insertBatch($rows);
        }

        $this->db->transComplete();
    }

    /** @return array<int, int> 노출 순서대로의 애드온 상품 id */
    public function getAddonProductIds(int $productId): array
    {
        $rows = $this->db->table('product_addons')
            ->select('addon_product_id')
            ->where('product_id', $productId)
            ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['addon_product_id'], $rows);
    }

    /**
     * 상품 상세에 노출할 애드온 목록. 살 수 없는 상품(판매중 아님·재고 0·삭제)은 뺀다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForDisplay(int $productId): array
    {
        return $this->db->table('product_addons pa')
            ->select('p.id, p.name, p.slug, p.price, p.discount_price, p.stock, m.file_path')
            ->join('products p', 'p.id = pa.addon_product_id')
            ->join('product_images pi', 'pi.product_id = p.id AND pi.is_primary = 1', 'left')
            ->join('media m', 'm.id = pi.media_id', 'left')
            ->where('pa.product_id', $productId)
            ->where('p.status', 'on_sale')
            ->where('p.stock >', 0)
            ->where('p.deleted_at IS NULL', null, false)
            ->orderBy('pa.sort_order', 'ASC')->orderBy('pa.id', 'ASC')
            ->get()->getResultArray();
    }

    /** 이 쌍이 실제로 등록된 연결인지 — 임의 상품 주입을 막는 검증용 */
    public function isLinked(int $productId, int $addonProductId): bool
    {
        return $this->db->table('product_addons')
            ->where('product_id', $productId)
            ->where('addon_product_id', $addonProductId)
            ->countAllResults() > 0;
    }
}
