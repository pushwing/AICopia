<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UserCouponModel extends Model
{
    protected $table         = 'user_coupons';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id', 'coupon_id', 'order_id', 'source', 'status', 'issued_at', 'used_at',
    ];

    /**
     * 사용 가능한 쿠폰 목록 (issued 상태 + 쿠폰 유효성 체크)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailable(int $userId, int $orderAmount = 0): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table('user_coupons uc')
            ->select('uc.id AS user_coupon_id, c.id, c.code, c.name, c.type, c.target_grade,
                      c.discount_value, c.min_order_amount, c.max_discount_amount, uc.issued_at,
                      c.expires_at')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->join('users u', 'u.id = uc.user_id')
            ->where('uc.user_id', $userId)
            ->where('uc.status', 'issued')
            ->where('c.is_active', 1)
            // 등급 전용 쿠폰은 회원 등급이 대상에 포함될 때만 노출한다.
            // (CouponService::checkCoupon()의 등급 검증과 동일한 기준 — 목록에는
            //  떠 있는데 주문 시 거부되는 불일치를 막는다.)
            ->groupStart()
                ->where("COALESCE(c.target_grade, '') = ''", null, false)
                ->orWhere("FIND_IN_SET(COALESCE(u.grade, 'bronze'), REPLACE(c.target_grade, ' ', '')) > 0", null, false)
            ->groupEnd()
            ->groupStart()
                ->where('c.expires_at IS NULL', null, false)
                ->orWhere('c.expires_at >=', $now)
            ->groupEnd()
            ->groupStart()
                ->where('c.starts_at IS NULL', null, false)
                ->orWhere('c.starts_at <=', $now)
            ->groupEnd()
            ->where('c.min_order_amount <=', $orderAmount)
            ->orderBy('uc.id', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * 특정 user_coupon_id 조회 (user_id 소유 확인 포함)
     *
     * @return array<string, mixed>|null
     */
    public function getWithCoupon(int $userCouponId, int $userId): ?array
    {
        return $this->db->table('user_coupons uc')
            ->select('uc.*, c.code, c.name, c.type, c.target_grade, c.discount_value,
                      c.min_order_amount, c.max_discount_amount, c.total_qty, c.used_count,
                      c.starts_at, c.expires_at, c.is_active')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.id', $userCouponId)
            ->where('uc.user_id', $userId)
            ->get()->getRowArray() ?: null;
    }

    /**
     * 관리자: 쿠폰별 발급 내역
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, totalPages: int, currentPage: int, perPage: int}
     */
    public function getByCoupon(int $couponId, int $page = 1, int $perPage = 20): array
    {
        $builder = $this->db->table('user_coupons uc')
            ->select('uc.*, u.email, u.nickname')
            ->join('users u', 'u.id = uc.user_id')
            ->where('uc.coupon_id', $couponId);

        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('uc.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'       => $items,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }
}
