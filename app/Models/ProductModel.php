<?php

namespace App\Models;

use App\Traits\HasSlug;
use CodeIgniter\Model;

class ProductModel extends Model
{
    use HasSlug;
    protected $table          = 'products';
    protected $primaryKey     = 'id';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'supplier_id', 'name', 'slug', 'price', 'cost_price', 'discount_price',
        'stock', 'status', 'is_featured', 'description', 'shipping_type', 'shipping_fee', 'free_threshold',
    ];

    public const STATUSES = [
        'on_sale'  => '판매중',
        'sold_out' => '품절',
        'hidden'   => '숨김',
    ];

    public const SHIPPING_TYPES = [
        'free'        => '무료배송',
        'fixed'       => '고정 배송비',
        'conditional' => '조건부 무료',
    ];

    private const CAT_NAME_SQL = '(SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR \', \')
        FROM product_categories pc JOIN categories c ON c.id = pc.category_id
        WHERE pc.product_id = products.id) AS category_name';

    /**
     * 상품에 카테고리 배열을 저장 (기존 연결 전부 교체)
     */
    public function setCategories(int $productId, array $categoryIds): void
    {
        $db = $this->db;
        $db->table('product_categories')->where('product_id', $productId)->delete();

        foreach (array_unique(array_filter(array_map('intval', $categoryIds))) as $cid) {
            $db->table('product_categories')->insert([
                'product_id'  => $productId,
                'category_id' => $cid,
            ]);
        }
    }

    /**
     * 상품의 카테고리 ID 배열 반환
     */
    public function getCategories(int $productId): array
    {
        $rows = $this->db->table('product_categories')
            ->select('category_id')
            ->where('product_id', $productId)
            ->get()->getResultArray();
        return array_map('intval', array_column($rows, 'category_id'));
    }

    /**
     * 프론트 상품 목록: 숨김 제외, 검색·카테고리·정렬 지원
     */
    public function getList(array $params = []): array
    {
        $keyword      = $params['keyword']      ?? '';
        $categoryId   = $params['category_id']  ?? null;
        $sort         = $params['sort']          ?? 'latest';
        $perPage      = $params['per_page']      ?? 12;
        $page         = max(1, (int) ($params['page'] ?? 1));
        $priceMin     = isset($params['price_min']) && $params['price_min'] !== '' ? (int) $params['price_min'] : null;
        $priceMax     = isset($params['price_max']) && $params['price_max'] !== '' ? (int) $params['price_max'] : null;
        $onlyDiscount = ! empty($params['only_discount']);

        $builder = $this->db->table('products')
            ->select('products.*, ' . self::CAT_NAME_SQL . ',
                EXISTS(SELECT 1 FROM product_options WHERE product_id = products.id) AS has_options')
            ->where('products.deleted_at IS NULL')
            ->whereIn('products.status', ['on_sale', 'sold_out']);

        if ($keyword) {
            $expanded = array_filter(array_map('trim', (array) ($params['expanded_terms'] ?? [])));

            if ($expanded === []) {
                // 확장어가 없으면 기존과 동일하게 상품명만 검색 (정밀도 유지)
                $builder->like('products.name', $keyword);
            } else {
                // 시맨틱 확장어가 있을 때만 원본 + 확장어를 name·description에 OR-매칭 (재현율 향상)
                $terms = array_values(array_unique(array_merge([$keyword], $expanded)));

                $builder->groupStart();
                foreach ($terms as $term) {
                    $builder->orLike('products.name', $term);
                    $builder->orLike('products.description', $term);
                }
                $builder->groupEnd();
            }
        }

        if ($categoryId) {
            // 대분류 선택 시 소분류 포함
            $subIds = $this->db->table('categories')
                ->select('id')
                ->where('parent_id', $categoryId)
                ->get()->getResultArray();

            $ids = array_column($subIds, 'id');
            $ids[] = $categoryId;

            $inList = implode(',', array_map('intval', $ids));
            $builder->where("EXISTS (SELECT 1 FROM product_categories pc2
                WHERE pc2.product_id = products.id AND pc2.category_id IN ({$inList}))", null, false);
        }

        if ($priceMin !== null) {
            $builder->where("COALESCE(products.discount_price, products.price) >= {$priceMin}", null, false);
        }
        if ($priceMax !== null) {
            $builder->where("COALESCE(products.discount_price, products.price) <= {$priceMax}", null, false);
        }
        if ($onlyDiscount) {
            $builder->where('products.discount_price IS NOT NULL', null, false);
        }

        match ($sort) {
            'price_asc'  => $builder->orderBy('COALESCE(products.discount_price, products.price)', 'ASC', false),
            'price_desc' => $builder->orderBy('COALESCE(products.discount_price, products.price)', 'DESC', false),
            default      => $builder->orderBy('products.id', 'DESC'),
        };

        return $this->buildPage($builder, $page, $perPage);
    }

    /**
     * 관리자 상품 목록
     */
    public function getAdminList(array $params = []): array
    {
        $keyword   = $params['keyword']        ?? '';
        $status    = $params['status']         ?? '';
        $stock     = $params['stock']          ?? '';
        $threshold = (int) ($params['low_stock_threshold'] ?? 5);
        $perPage   = 20;
        $page      = max(1, (int) ($params['page'] ?? 1));

        $builder = $this->db->table('products')
            ->select('products.*, ' . self::CAT_NAME_SQL)
            ->where('products.deleted_at IS NULL');

        if ($keyword) {
            $builder->like('products.name', $keyword);
        }
        if ($status) {
            $builder->where('products.status', $status);
        }
        if ($stock === 'low') {
            $builder->where('products.stock <=', $threshold);
        }

        $builder->orderBy('products.stock', 'ASC')->orderBy('products.id', 'DESC');

        return $this->buildPage($builder, $page, $perPage);
    }

    public function getLatest(int $limit = 8): array
    {
        return $this->db->table('products')
            ->select('products.*, ' . self::CAT_NAME_SQL)
            ->where('products.deleted_at IS NULL')
            ->whereIn('products.status', ['on_sale', 'sold_out'])
            ->orderBy('products.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function getDiscounted(int $limit = 8): array
    {
        return $this->db->table('products')
            ->select('products.*, ' . self::CAT_NAME_SQL)
            ->where('products.deleted_at IS NULL')
            ->where('products.status', 'on_sale')
            ->where('products.discount_price IS NOT NULL')
            ->orderBy('products.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function getFeatured(int $limit = 8): array
    {
        return $this->db->table('products')
            ->select('products.*, ' . self::CAT_NAME_SQL)
            ->where('products.deleted_at IS NULL')
            ->whereIn('products.status', ['on_sale', 'sold_out'])
            ->where('products.is_featured', 1)
            ->orderBy('products.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    private function buildPage($builder, int $page, int $perPage): array
    {
        // DB Builder는 배열 기반 상태라 clone이 안전함 (Model clone과 달리 DB 커넥션을 통한 상태 공유 없음)
        $total  = (clone $builder)->countAllResults();
        $offset = ($page - 1) * $perPage;

        return [
            'items'       => $builder->limit($perPage, $offset)->get()->getResultArray(),
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }

}
