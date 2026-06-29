<?php

namespace App\Libraries;

use App\Models\CouponModel;
use App\Models\UserCouponModel;

class CouponService
{
    private CouponModel     $couponModel;
    private UserCouponModel $userCouponModel;

    public function __construct()
    {
        $this->couponModel     = new CouponModel();
        $this->userCouponModel = new UserCouponModel();
    }

    /**
     * 쿠폰 코드로 검증
     *
     * @return array{valid: bool, coupon: array|null, user_coupon_id: int|null, discount: int, message: string}
     */
    public function validate(string $code, int $userId, int $orderAmount): array
    {
        $coupon = $this->couponModel->findByCode($code);
        if (! $coupon) {
            return $this->fail('존재하지 않거나 비활성화된 쿠폰입니다.');
        }

        return $this->checkCoupon($coupon, $userId, $orderAmount, null);
    }

    /**
     * 발급된 user_coupon_id 로 검증
     *
     * @return array{valid: bool, coupon: array|null, user_coupon_id: int|null, discount: int, message: string}
     */
    public function validateByUserCouponId(int $userCouponId, int $userId, int $orderAmount): array
    {
        $uc = $this->userCouponModel->getWithCoupon($userCouponId, $userId);
        if (! $uc) {
            return $this->fail('유효하지 않은 쿠폰입니다.');
        }
        if ($uc['status'] !== 'issued') {
            return $this->fail('이미 사용했거나 만료된 쿠폰입니다.');
        }

        $coupon = [
            'id'                  => $uc['coupon_id'],
            'code'                => $uc['code'],
            'name'                => $uc['name'],
            'type'                => $uc['type'],
            'discount_value'      => $uc['discount_value'],
            'min_order_amount'    => $uc['min_order_amount'],
            'max_discount_amount' => $uc['max_discount_amount'],
            'total_qty'           => $uc['total_qty'],
            'used_count'          => $uc['used_count'],
            'is_active'           => $uc['is_active'],
            'starts_at'           => $uc['starts_at'],
            'expires_at'          => $uc['expires_at'],
        ];

        return $this->checkCoupon($coupon, $userId, $orderAmount, $userCouponId);
    }

    /**
     * 할인 금액 계산 (orderAmount 초과 불가)
     * free_shipping은 배송비를 직접 알 수 없으므로 0 반환 — 호출처에서 shippingFee로 오버라이드
     */
    public function calculateDiscount(array $coupon, int $orderAmount): int
    {
        if ($coupon['type'] === 'free_shipping') {
            return 0;
        }

        if ($coupon['type'] === 'fixed') {
            return min((int) $coupon['discount_value'], $orderAmount);
        }

        $discount = (int) floor($orderAmount * $coupon['discount_value'] / 100);
        if ((int) $coupon['max_discount_amount'] > 0) {
            $discount = min($discount, (int) $coupon['max_discount_amount']);
        }

        return min($discount, $orderAmount);
    }

    private function checkCoupon(array $coupon, int $userId, int $orderAmount, ?int $userCouponId): array
    {
        if (! $coupon['is_active']) {
            return $this->fail('비활성화된 쿠폰입니다.');
        }

        $now = date('Y-m-d H:i:s');
        if ($coupon['starts_at'] && $coupon['starts_at'] > $now) {
            return $this->fail('아직 사용할 수 없는 쿠폰입니다.');
        }
        if ($coupon['expires_at'] && $coupon['expires_at'] < $now) {
            return $this->fail('만료된 쿠폰입니다.');
        }

        if ($coupon['total_qty'] !== null && (int) $coupon['used_count'] >= (int) $coupon['total_qty']) {
            return $this->fail('쿠폰 수량이 모두 소진되었습니다.');
        }

        if ((int) $coupon['min_order_amount'] > 0 && $orderAmount < (int) $coupon['min_order_amount']) {
            return $this->fail('최소 주문 금액(' . number_format($coupon['min_order_amount']) . '원) 이상일 때 사용 가능합니다.');
        }

        // 등급 제한 쿠폰 검증 (콤마 구분 다중 등급)
        if (! empty($coupon['target_grade'])) {
            $userRow = \Config\Database::connect()
                ->table('users')->select('grade')->where('id', $userId)->get()->getRowArray();
            $userGrade    = $userRow['grade'] ?? 'bronze';
            $targetGrades = array_map('trim', explode(',', $coupon['target_grade']));
            if (! in_array($userGrade, $targetGrades, true)) {
                $gradeLabels   = \App\Libraries\GradeService::LABELS;
                $targetLabels  = array_map(fn($g) => $gradeLabels[$g] ?? $g, $targetGrades);
                return $this->fail(implode('·', $targetLabels) . ' 등급 전용 쿠폰입니다.');
            }
        }

        // user_coupon_id 없이 코드로 접근하는 경우: 이미 사용한 이력 확인
        if ($userCouponId === null) {
            $used = \Config\Database::connect()
                ->table('user_coupons')
                ->where('user_id', $userId)
                ->where('coupon_id', $coupon['id'])
                ->whereIn('status', ['used'])
                ->countAllResults();
            if ($used >= (int) $coupon['per_user_limit']) {
                return $this->fail('이미 사용한 쿠폰입니다.');
            }
        }

        $discount = $this->calculateDiscount($coupon, $orderAmount);

        return [
            'valid'          => true,
            'coupon'         => $coupon,
            'user_coupon_id' => $userCouponId,
            'discount'       => $discount,
            'message'        => '',
        ];
    }

    private function fail(string $message): array
    {
        return ['valid' => false, 'coupon' => null, 'user_coupon_id' => null, 'discount' => 0, 'message' => $message];
    }
}
