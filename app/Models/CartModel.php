<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\ItemPricing;
use CodeIgniter\Model;

class CartModel extends Model
{
    /** 장바구니에서 선택해 주문서로 넘긴 cart_items.id 목록을 담는 세션 키 */
    public const CHECKOUT_SESSION_KEY = 'checkout_cart_ids';

    protected $table         = 'cart_items';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['user_id', 'product_id', 'sku_id', 'parent_product_id', 'qty', 'created_at'];

    /**
     * 사용자의 장바구니 목록 (상품 정보 + SKU + 대표 이미지 JOIN)
     *
     * $cartItemIds 를 넘기면 그 항목만 반환한다(주문서에서 선택 구매 시 사용).
     * 빈 배열이면 장바구니 전체를 반환한다. user_id 조건이 함께 걸리므로
     * 남의 장바구니 id 를 넘겨도 조회되지 않는다.
     *
     * @param  list<int>                        $cartItemIds
     * @return array<int, array<string, mixed>>
     */
    public function getByUser(int $userId, array $cartItemIds = []): array
    {
        $builder = $this->db->table('cart_items');

        if ($cartItemIds !== []) {
            $builder->whereIn('cart_items.id', $cartItemIds);
        }

        $rows = $builder
            ->select('cart_items.id, cart_items.product_id, cart_items.sku_id, cart_items.parent_product_id, cart_items.qty,
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

        return $this->enrichItems($rows);
    }

    /**
     * 비회원 세션 장바구니의 상품 정보를 조회한다.
     *
     * @param  array<string, mixed>            $sessionCart
     * @return array<int, array<string, mixed>>
     */
    public function getBySession(array $sessionCart): array
    {
        if ($sessionCart === []) {
            return [];
        }

        /** @var array<string, array{product_id: int, sku_id: int|null, qty: int}> $entries */
        $entries    = [];
        $productIds = [];
        $skuIds     = [];

        foreach ($sessionCart as $key => $qty) {
            [$productId, $skuId] = self::parseSessionKey((string) $key);
            $qty                 = (int) $qty;

            if ($productId < 1 || $qty < 1) {
                continue;
            }

            $entries[(string) $key] = [
                'product_id' => $productId,
                'sku_id'     => $skuId > 0 ? $skuId : null,
                'qty'        => $qty,
            ];
            $productIds[] = $productId;
            if ($skuId > 0) {
                $skuIds[] = $skuId;
            }
        }

        if ($entries === []) {
            return [];
        }

        $products = $this->db->table('products')
            ->select('products.id, products.name, products.slug, products.price, products.discount_price,
                 products.stock, products.status, products.shipping_type, products.shipping_fee, products.free_threshold,
                 media.file_path')
            ->join('product_images pi', 'pi.product_id = products.id AND pi.is_primary = 1', 'left')
            ->join('media', 'media.id = pi.media_id', 'left')
            ->whereIn('products.id', array_values(array_unique($productIds)))
            ->where('products.deleted_at IS NULL', null, false)
            ->get()->getResultArray();
        $productMap = array_column($products, null, 'id');

        $skuMap = [];
        if ($skuIds !== []) {
            $skus = $this->db->table('product_skus')
                ->select('id, product_id, price_diff, stock')
                ->whereIn('id', array_values(array_unique($skuIds)))
                ->get()->getResultArray();
            $skuMap = array_column($skus, null, 'id');
        }

        $parentMap = session()->get('cart_addon_of') ?? [];
        $rows      = [];
        foreach ($entries as $key => $entry) {
            $product = $productMap[$entry['product_id']] ?? null;
            if (! is_array($product)) {
                continue;
            }

            $sku = $entry['sku_id'] === null ? null : ($skuMap[$entry['sku_id']] ?? null);
            if ($entry['sku_id'] !== null && (! is_array($sku) || (int) $sku['product_id'] !== $entry['product_id'])) {
                continue;
            }

            $rows[] = [
                'id'                => 0,
                'product_id'        => $entry['product_id'],
                'sku_id'            => $entry['sku_id'],
                'parent_product_id' => isset($parentMap[$key]) ? (int) $parentMap[$key] : null,
                'qty'               => $entry['qty'],
                'name'              => $product['name'],
                'slug'              => $product['slug'],
                'price'             => $product['price'],
                'discount_price'    => $product['discount_price'],
                'stock'             => $product['stock'],
                'status'            => $product['status'],
                'shipping_type'     => $product['shipping_type'],
                'shipping_fee'      => $product['shipping_fee'],
                'free_threshold'    => $product['free_threshold'],
                'file_path'         => $product['file_path'],
                'price_diff'        => $sku['price_diff'] ?? null,
                'sku_stock'         => $sku['stock'] ?? null,
            ];
        }

        return $this->enrichItems($rows);
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichItems(array $rows): array
    {
        $skuLabels = $this->getSkuLabels(array_filter(array_column($rows, 'sku_id')));

        foreach ($rows as &$row) {
            $row['primary_image'] = $row['file_path'] ? base_url($row['file_path']) : null;
            $row['display_price'] = ItemPricing::unitPrice($row);
            $effectiveStock       = $row['sku_id'] ? (int) ($row['sku_stock'] ?? 0) : (int) $row['stock'];
            $row['is_available']  = $row['status'] !== 'hidden' && $effectiveStock > 0;
            $row['sku_label']     = $row['sku_id'] ? ($skuLabels[$row['sku_id']] ?? '') : '';
        }

        return $rows;
    }

    /**
     * @param  array<int|string, mixed> $skuIds
     * @return array<string, string>
     */
    private function getSkuLabels(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $rows = $this->db->table('product_sku_values sv')
            ->select('sv.sku_id, o.name as option_name, ov.value')
            ->join('product_option_values ov', 'ov.id = sv.option_value_id')
            ->join('product_options o', 'o.id = ov.option_id')
            ->whereIn('sv.sku_id', array_map(intval(...), $skuIds))
            ->orderBy('o.sort_order', 'ASC')
            ->get()->getResultArray();

        $labels = [];
        foreach ($rows as $r) {
            $labels[$r['sku_id']][] = $r['option_name'] . ':' . $r['value'];
        }
        return array_map(fn ($parts): string => implode('/', $parts), $labels);
    }

    /**
     * 담긴 상품 종류 수 (뱃지 표시용)
     */
    public function getCount(int $userId): int
    {
        return (int) $this->db->table($this->table)->where('user_id', $userId)->countAllResults();
    }

    /**
     * 장바구니에 담기. 같은 상품·같은 SKU 는 수량을 합산한다.
     *
     * $parentProductId 는 어느 본품에 딸려 담겼는지를 나타내는 표시·포장용 값이다.
     * 이미 행이 있으면 먼저 정해진 분류를 유지한다(COALESCE).
     */
    public function upsert(int $userId, int $productId, int $qty, ?int $skuId = null, ?int $parentProductId = null): void
    {
        $this->db->query(
            'INSERT INTO cart_items (user_id, product_id, sku_id, parent_product_id, qty, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                qty = qty + VALUES(qty),
                parent_product_id = COALESCE(parent_product_id, VALUES(parent_product_id))',
            [$userId, $productId, $skuId, $parentProductId, $qty]
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
     * cart_items.id 목록으로 삭제 (주문에 포함된 항목만 정확히 비울 때 사용)
     *
     * @param list<int> $cartItemIds
     */
    public function removeByIds(int $userId, array $cartItemIds): void
    {
        if ($cartItemIds === []) {
            return;
        }

        $this->where('user_id', $userId)->whereIn('id', $cartItemIds)->delete();
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
    /**
     * @param array<string, mixed> $sessionCart
     * @param array<string, int>   $stockMap
     */
    public function mergeSession(int $userId, array $sessionCart, array $stockMap): void
    {
        if ($sessionCart === []) {
            return;
        }

        $productIds = [];
        $skuIds     = [];
        foreach (array_keys($sessionCart) as $key) {
            [$pid, $sid] = static::parseSessionKey((string) $key);
            $productIds[] = $pid;
            if ($sid) {
                $skuIds[] = $sid;
            }
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

        $parentMap = session()->get('cart_addon_of') ?? [];

        foreach ($sessionCart as $key => $sessionQty) {
            [$productId, $skuId] = static::parseSessionKey((string) $key);
            $stock      = (int) ($stockMap[$key] ?? 0);
            if ($stock < 1) {
                continue;
            }

            $currentQty = $dbQtyMap[$key] ?? 0;
            $addQty     = min((int) $sessionQty, $stock - $currentQty);
            if ($addQty < 1) {
                continue;
            }

            $this->upsert($userId, $productId, $addQty, $skuId ?: null, isset($parentMap[$key]) ? (int) $parentMap[$key] : null);
        }
    }

    /**
     * 로그인 직후 호출 — 세션 카트를 DB 카트로 병합하고 세션을 비웁니다.
     * 재고를 직접 조회하므로 외부 모델 의존 없이 사용 가능합니다.
     */
    public function mergeAndClear(int $userId): void
    {
        $sessionCart = session()->get('cart') ?? [];
        if (empty($sessionCart)) {
            return;
        }

        $productIds = [];
        $skuIds     = [];
        foreach ($sessionCart as $key => $_) {
            [$pid, $sid] = self::parseSessionKey((string) $key);
            $productIds[] = $pid;
            if ($sid) {
                $skuIds[] = $sid;
            }
        }

        $productStocks = [];
        if ($productIds !== []) {
            $rows = $this->db->table('products')
                ->select('id, stock')
                ->whereIn('id', array_unique($productIds))
                ->get()->getResultArray();
            $productStocks = array_column($rows, 'stock', 'id');
        }

        $skuStocks = [];
        if ($skuIds !== []) {
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
        session()->remove('cart_addon_of');
    }

    /**
     * 세션 키 "productId_skuId" 파싱
     *
     * @return array{0: int, 1: int}
     */
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
