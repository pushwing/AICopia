<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductSkuModel extends Model
{
    protected $table         = 'product_skus';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['product_id', 'price_diff', 'stock', 'sku_code'];

    /**
     * 상품의 옵션 구조 + SKU 목록을 한 번에 조회
     * 반환 형태:
     * [
     *   'options' => [
     *     ['id' => 1, 'name' => '색상', 'values' => [['id' => 1, 'value' => '빨강'], ...]],
     *     ...
     *   ],
     *   'skus' => [
     *     ['id' => 1, 'price_diff' => 0, 'stock' => 10, 'sku_code' => null,
     *      'option_value_ids' => [1, 3], 'option_label' => '색상:빨강/사이즈:S'],
     *     ...
     *   ],
     * ]
     */
    public function getOptionsAndSkus(int $productId): array
    {
        $options = $this->db->table('product_options')
            ->where('product_id', $productId)
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        if (empty($options)) {
            return ['options' => [], 'skus' => []];
        }

        $optionIds = array_column($options, 'id');

        $values = $this->db->table('product_option_values')
            ->whereIn('option_id', $optionIds)
            ->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();

        $valuesByOption = [];
        foreach ($values as $v) {
            $valuesByOption[$v['option_id']][] = $v;
        }
        foreach ($options as &$opt) {
            $opt['values'] = $valuesByOption[$opt['id']] ?? [];
        }
        unset($opt);

        $skus = $this->db->table('product_skus')->where('product_id', $productId)->get()->getResultArray();

        if ($skus) {
            $skuIds = array_column($skus, 'id');

            $skuValues = $this->db->table('product_sku_values sv')
                ->select('sv.sku_id, sv.option_value_id, o.name as option_name, ov.value')
                ->join('product_option_values ov', 'ov.id = sv.option_value_id')
                ->join('product_options o', 'o.id = ov.option_id')
                ->whereIn('sv.sku_id', $skuIds)
                ->orderBy('o.sort_order', 'ASC')
                ->get()->getResultArray();

            $skuValueMap = [];
            $skuLabelMap = [];
            foreach ($skuValues as $sv) {
                $skuValueMap[$sv['sku_id']][] = (int) $sv['option_value_id'];
                $skuLabelMap[$sv['sku_id']][] = $sv['option_name'] . ':' . $sv['value'];
            }
            foreach ($skus as &$sku) {
                $sku['option_value_ids'] = $skuValueMap[$sku['id']] ?? [];
                $sku['option_label']     = implode('/', $skuLabelMap[$sku['id']] ?? []);
            }
            unset($sku);
        }

        return ['options' => $options, 'skus' => $skus];
    }

    /**
     * 옵션 + SKU를 일괄 저장 (상품 저장 후 호출)
     * $data 형태: ['options' => [...], 'skus' => [...]]
     */
    public function saveOptionsAndSkus(int $productId, array $data): void
    {
        $this->deleteByProduct($productId);

        $options = $data['options'] ?? [];
        $skus    = $data['skus']    ?? [];

        if (empty($options)) {
            return;
        }

        // 옵션 그룹 + 값 저장, 클라이언트의 임시 ID → DB ID 매핑
        $valueIdMap = []; // 클라이언트 임시 value_id => 실제 DB id
        foreach ($options as $sortIdx => $opt) {
            $optionId = (int) $this->db->table('product_options')->insert([
                'product_id' => $productId,
                'name'       => $opt['name'],
                'sort_order' => $sortIdx,
            ]);
            $optionId = (int) $this->db->insertID();

            foreach ($opt['values'] as $valSortIdx => $val) {
                $this->db->table('product_option_values')->insert([
                    'option_id'  => $optionId,
                    'value'      => $val['value'],
                    'sort_order' => $valSortIdx,
                ]);
                $dbValueId = (int) $this->db->insertID();
                $valueIdMap[$val['tmp_id']] = $dbValueId;
            }
        }

        // SKU 저장
        $totalStock = 0;
        foreach ($skus as $sku) {
            $skuStock = max(0, (int) ($sku['stock'] ?? 0));
            $totalStock += $skuStock;

            $this->db->table('product_skus')->insert([
                'product_id' => $productId,
                'price_diff' => (int) ($sku['price_diff'] ?? 0),
                'stock'      => $skuStock,
                'sku_code'   => $sku['sku_code'] ?? null,
            ]);
            $skuId = (int) $this->db->insertID();

            foreach (($sku['value_tmp_ids'] ?? []) as $tmpId) {
                if (! isset($valueIdMap[$tmpId])) continue;
                $this->db->table('product_sku_values')->insert([
                    'sku_id'          => $skuId,
                    'option_value_id' => $valueIdMap[$tmpId],
                ]);
            }
        }

        // products.stock을 SKU 재고 합계로 동기화
        $this->db->table('products')->where('id', $productId)->update(['stock' => $totalStock]);
    }

    public function deleteByProduct(int $productId): void
    {
        $skuIds = $this->db->table('product_skus')
            ->select('id')
            ->where('product_id', $productId)
            ->get()->getResultArray();

        if ($skuIds) {
            $ids = array_column($skuIds, 'id');
            $this->db->table('product_sku_values')->whereIn('sku_id', $ids)->delete();
        }

        $this->db->table('product_skus')->where('product_id', $productId)->delete();

        $optionIds = $this->db->table('product_options')
            ->select('id')
            ->where('product_id', $productId)
            ->get()->getResultArray();

        if ($optionIds) {
            $ids = array_column($optionIds, 'id');
            $this->db->table('product_option_values')->whereIn('option_id', $ids)->delete();
        }

        $this->db->table('product_options')->where('product_id', $productId)->delete();
    }

    /**
     * SKU 단건 조회 (product_id 소속 검증 포함)
     */
    public function findForProduct(int $skuId, int $productId): ?array
    {
        return $this->where('id', $skuId)->where('product_id', $productId)->first();
    }
}
