<?php

namespace App\Models;

use CodeIgniter\Model;

class CouponModel extends Model
{
    protected $table         = 'coupons';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'code', 'name', 'type', 'target_grade', 'discount_value', 'min_order_amount',
        'max_discount_amount', 'total_qty', 'used_count', 'per_user_limit',
        'starts_at', 'expires_at', 'is_active',
    ];

    public const TYPES = [
        'fixed'        => '정액 할인',
        'percent'      => '정률 할인',
        'free_shipping' => '무료배송',
    ];

    public function findByCode(string $code): ?array
    {
        return $this->where('code', $code)->where('is_active', 1)->first();
    }

    public function getAdminList(array $params = []): array
    {
        $keyword = trim($params['keyword'] ?? '');
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['perPage'] ?? 20));

        $builder = $this->db->table('coupons');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('code', $keyword)
                ->orLike('name', $keyword)
                ->groupEnd();
        }

        $total   = (clone $builder)->countAllResults();
        $coupons = $builder->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'       => $coupons,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }
}
