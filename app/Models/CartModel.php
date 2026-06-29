<?php

namespace App\Models;

use CodeIgniter\Model;

class CartModel extends Model
{
    protected $table         = 'cart_items';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['user_id', 'product_id', 'sku_id', 'qty', 'created_at'];

    /**
     * 사용자의 장바구니 목록 (상품 정보 + SKU + 대표 이미지 JOIN)
     */
    public function getByUser(int $userId): array
    {
        $rows = $this->db->table('cart_items')
            ->select('cart_items.id, cart_items.product_id, cart_items.sku_id, cart_items.qty,
                 products.name, products.slug, products.price, products.discount_price,
                 products.stock, products.status,
                 products.shipping_type, products.shipping_fee, products.free_threshold,
                 media.file_path,
                 ps.price_diff, ps.stock as sku_stock')
            ->join('products', 'products.id = cart_items.product_id')
            ->join('product_images pi', 'pi.product_id = cart_items.product_id AND pi.is_primary = 1', 'left')
            ->join('media', 'media.id = pi.media_id', 'left')
            ->join('product_skus ps', 'ps.id = cart_items.sku_id', 'left')
            ->where('cart_items.user_id', $userId)
            ->where('products.deleted_at IS NULL', null, false)
            ->orderBy('cart_items.id', 'DESC')
            ->get()->getResultArray();

        $skuLabels = $this->getSkuLabels(array_filter(array_column($rows, 'sku_id')));

        foreach ($rows as &$row) {
            $row['primary_image'] = $row['file_path'] ? base_url($row['file_path']) : null;
            $priceDiff            = (int) ($row['price_diff'] ?? 0);
            $basePrice            = (int) ($row['discount_price'] ?? $row['price']);
            $row['display_price'] = $basePrice + $priceDiff;
            $effectiveStock       = $row['sku_id'] ? (int) ($row['sku_stock'] ?? 0) : (int) $row['stock'];
            $row['is_available']  = $row['status'] !== 'hidden' && $effectiveStock > 0;
            $row['sku_label']     = $row['sku_id'] ? ($skuLabels[$row['sku_id']] ?? '') : '';
        }
        return $rows;
    }

    private function getSkuLabels(array $skuIds): array
    {
        if (empty($skuIds)) return [];

        $rows = $this->db->table('product_sku_values sv')
            ->select('sv.sku_id, o.name as option_name, ov.value')
            ->join('product_option_values ov', 'ov.id = sv.option_value_id')
            ->join('product_options o', 'o.id = ov.option_id')
            ->whereIn('sv.sku_id', array_map('intval', $skuIds))
            ->orderBy('o.sort_order', 'ASC')
            ->get()->getResultArray();

        $labels = [];
        foreach ($rows as $r) {
            $labels[$r['sku_id']][] = $r['option_name'] . ':' . $r['value'];
        }
        return array_map(fn($parts) => implode('/', $parts), $labels);
    }

    /**
     * 담긴 상품 종류 수 (뱃지 표시용)
     */
    public function getCount(int $userId): int
    {
        return (int) $this->db->table($this->table)->where('user_id', $userId)->countAllResults();
    }

    /**
     * 담기 — 이미 있으면 qty 합산, 없으면 신규 삽입 (원자적 처리)
     * sku_id가 NULL이면 옵션 없는 상품 (UNIQUE KEY는 NULL을 별개 행으로 취급 → COALESCE 처리)
     */
    public function upsert(int $userId, int $productId, int $qty, ?int $skuId = null): void
    {
        $this->db->query(
            'INSERT INTO cart_items (user_id, product_id, sku_id, qty, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)',
            [$userId, $productId, $skuId, $qty]
        );
    }

    /**
     * 수량 직접 지정 (수정 버튼)
     */
    public function updateQty(int $userId, int $productId, int $qty, ?int $skuId = null): void
    {
        $builder = $this->where('user_id', $userId)->where('product_id', $productId);
        if ($skuId !== null) {
            $builder->where('sku_id', $skuId);
        } else {
            $builder->where('sku_id IS NULL', null, false);
        }
        $builder->set('qty', $qty)->update();
    }

    /**
     * 개별 삭제
     */
    public function removeItem(int $userId, int $productId, ?int $skuId = null): void
    {
        $builder = $this->where('user_id', $userId)->where('product_id', $productId);
        if ($skuId !== null) {
            $builder->where('sku_id', $skuId);
        } else {
            $builder->where('sku_id IS NULL', null, false);
        }
        $builder->delete();
    }

    /**
     * 전체 비우기
     */
    public function clear(int $userId): void
    {
        $this->where('user_id', $userId)->delete();
    }

    /**
     * 세션 장바구니 → DB 병합 (로그인 후 호출)
     * 세션 키 형식: "productId_skuId" (skuId=0이면 SKU 없음)
     * $stockMap: ['productId_skuId' => stock] — 호출자가 미리 조회해서 전달
     */
    public function mergeSession(int $userId, array $sessionCart, array $stockMap): void
    {
        if (empty($sessionCart)) return;

        $productIds = [];
        $skuIds     = [];
        foreach ($sessionCart as $key => $_) {
            [$pid, $sid] = $this->parseSessionKey((string) $key);
            $productIds[] = $pid;
            if ($sid) $skuIds[] = $sid;
        }

        // DB에 이미 있는 항목 조회 (클리핑 계산용)
        $existing   = $this->where('user_id', $userId)
            ->whereIn('product_id', array_unique($productIds))
            ->findAll();
        $dbQtyMap = [];
        foreach ($existing as $row) {
            $k = $row['product_id'] . '_' . (int) $row['sku_id'];
            $dbQtyMap[$k] = (int) $row['qty'];
        }

        foreach ($sessionCart as $key => $sessionQty) {
            [$productId, $skuId] = $this->parseSessionKey((string) $key);
            $stock      = (int) ($stockMap[$key] ?? 0);
            if ($stock < 1) continue;

            $currentQty = (int) ($dbQtyMap[$key] ?? 0);
            $addQty     = min((int) $sessionQty, $stock - $currentQty);
            if ($addQty < 1) continue;

            $this->upsert($userId, $productId, $addQty, $skuId ?: null);
        }
    }

    /**
     * 로그인 직후 호출 — 세션 카트를 DB 카트로 병합하고 세션을 비웁니다.
     * 재고를 직접 조회하므로 외부 모델 의존 없이 사용 가능합니다.
     */
    public function mergeAndClear(int $userId): void
    {
        $sessionCart = session()->get('cart') ?? [];
        if (empty($sessionCart)) return;

        $productIds = [];
        $skuIds     = [];
        foreach ($sessionCart as $key => $_) {
            [$pid, $sid] = self::parseSessionKey((string) $key);
            $productIds[] = $pid;
            if ($sid) $skuIds[] = $sid;
        }

        $productStocks = [];
        if ($productIds) {
            $rows = $this->db->table('products')
                ->select('id, stock')
                ->whereIn('id', array_unique($productIds))
                ->get()->getResultArray();
            $productStocks = array_column($rows, 'stock', 'id');
        }

        $skuStocks = [];
        if ($skuIds) {
            $rows = $this->db->table('product_skus')
                ->select('id, stock')
                ->whereIn('id', array_unique($skuIds))
                ->get()->getResultArray();
            $skuStocks = array_column($rows, 'stock', 'id');
        }

        $stockMap = [];
        foreach ($sessionCart as $key => $_) {
            [$pid, $sid] = self::parseSessionKey((string) $key);
            $stockMap[$key] = $sid ? (int) ($skuStocks[$sid] ?? 0) : (int) ($productStocks[$pid] ?? 0);
        }

        $this->mergeSession($userId, $sessionCart, $stockMap);
        session()->remove('cart');
    }

    /** 세션 키 "productId_skuId" 파싱 */
    public static function parseSessionKey(string $key): array
    {
        $parts = explode('_', $key, 2);
        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }

    /** 세션 장바구니 키 생성 */
    public static function sessionKey(int $productId, ?int $skuId = null): string
    {
        return $productId . '_' . (int) ($skuId ?? 0);
    }
}
