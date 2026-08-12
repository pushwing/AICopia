<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\UserCouponModel;

class CouponService
{
    private readonly UserCouponModel $userCouponModel;

    public function __construct()
    {
        $this->userCouponModel = new UserCouponModel();
    }

    /**
     * 발급된 user_coupon_id 로 검증
     *
     * 쿠폰은 발급받아 보유한 것만 쓸 수 있다. 코드로 조회해 검증하던 경로는
     * 발급받지 않은 쿠폰도 코드만 알면 통과시켜서 제거했다(이슈 #219).
     *
     * @return array{valid: bool, coupon: array<string, mixed>|null, user_coupon_id: int|null, discount: int, message: string}
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
            // 누락되면 checkCoupon()의 등급 검증이 통째로 건너뛰어져
            // 타 등급 회원이 등급 전용 쿠폰을 사용할 수 있게 된다.
            'target_grade'        => $uc['target_grade'],
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
    /** @param array<string, mixed> $coupon */
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

    /**
     * @param  array<string, mixed>  $coupon
     * @return array{valid: bool, coupon: array<string, mixed>|null, user_coupon_id: int|null, discount: int, message: string}
     */
    private function checkCoupon(array $coupon, int $userId, int $orderAmount, int $userCouponId): array
    {
        // MySQLi 는 정수 컬럼도 문자열로 반환한다. 정규화 없이 문자열이 그대로
        // 나가면, strict_types 호출부인 OrderController::create() →
        // OrderAttemptModel::createAttempt(?int $couponId) 에서 TypeError 로 500 이 난다.
        $coupon = $this->normalizeCoupon($coupon);

        if (! $coupon['is_active']) {
            return $this->fail('비활성화된 쿠폰입니다.');
        }

        $now = date('Y-m-d H:i:s');
        if ($coupon['starts_at'] && $coupon['starts_at'] > $now) {
            // 시작 시각을 함께 알려준다. 안 그러면 "쿠폰을 방금 발급받았는데 왜 못 쓰냐"는
            // 오해로 이어진다 — 실제로 시작 1~2분 전에 테스트해 버그로 신고된 적이 있다.
            return $this->fail(
                date('Y-m-d H:i', strtotime((string) $coupon['starts_at'])) . '부터 사용할 수 있는 쿠폰입니다.',
            );
        }
        if ($coupon['expires_at'] && $coupon['expires_at'] < $now) {
            return $this->fail('만료된 쿠폰입니다.');
        }

        if ($coupon['total_qty'] !== null && (int) $coupon['used_count'] >= (int) $coupon['total_qty']) {
            return $this->fail('쿠폰 수량이 모두 소진되었습니다.');
        }

        if ((int) $coupon['min_order_amount'] > 0 && $orderAmount < (int) $coupon['min_order_amount']) {
            return $this->fail('최소 주문 금액(' . number_format((int) $coupon['min_order_amount']) . '원) 이상일 때 사용 가능합니다.');
        }

        // 등급 제한 쿠폰 검증 (콤마 구분 다중 등급)
        if (! empty($coupon['target_grade'])) {
            $userRow = \Config\Database::connect()
                ->table('users')->select('grade')->where('id', $userId)->get()->getRowArray();
            $userGrade    = $userRow['grade'] ?? 'bronze';
            $targetGrades = array_map(trim(...), explode(',', (string) $coupon['target_grade']));
            if (! in_array($userGrade, $targetGrades, true)) {
                $gradeLabels   = \App\Libraries\GradeService::LABELS;
                $targetLabels  = array_map(fn ($g) => $gradeLabels[$g] ?? $g, $targetGrades);
                return $this->fail(implode('·', $targetLabels) . ' 등급 전용 쿠폰입니다.');
            }
        }

        // 재사용 방지는 user_coupons.status 로 걸린다 — 이 메서드에 들어오는
        // 시점에 validateByUserCouponId() 가 'issued' 인지 이미 확인했다.

        return [
            'valid'          => true,
            'coupon'         => $coupon,
            'user_coupon_id' => $userCouponId,
            'discount'       => $this->calculateDiscount($coupon, $orderAmount),
            'message'        => '',
        ];
    }

    /**
     * DB 에서 읽은 쿠폰 배열의 숫자 필드를 정규화한다.
     *
     * MySQLi 드라이버는 정수 컬럼도 문자열로 반환한다. 이 클래스의 반환 타입
     * docblock 은 coupon['id'] 등을 int 로 선언하고 있고, 호출부가
     * declare(strict_types=1) 상태에서 그 값을 ?int 파라미터에 그대로 넘기므로
     * (예: OrderController::create() → OrderAttemptModel::createAttempt()),
     * 캐스팅 없이 문자열이 새어나가면 TypeError 로 500 이 난다.
     *
     * total_qty 는 "무제한"을 의미하는 null 을 반드시 보존해야 한다 — 0 으로
     * 캐스팅하면 "수량 무제한"이 "소진됨"으로 둔갑해 쿠폰이 전부 막힌다.
     *
     * @param  array<string, mixed> $coupon
     * @return array<string, mixed>
     */
    private function normalizeCoupon(array $coupon): array
    {
        $coupon['id']                  = (int) $coupon['id'];
        $coupon['discount_value']      = (int) $coupon['discount_value'];
        $coupon['min_order_amount']    = (int) $coupon['min_order_amount'];
        $coupon['max_discount_amount'] = (int) $coupon['max_discount_amount'];
        $coupon['used_count']          = (int) $coupon['used_count'];
        $coupon['total_qty']           = $coupon['total_qty'] !== null ? (int) $coupon['total_qty'] : null;

        if (array_key_exists('is_active', $coupon)) {
            $coupon['is_active'] = (int) $coupon['is_active'];
        }

        return $coupon;
    }

    /** @return array{valid: bool, coupon: null, user_coupon_id: null, discount: int, message: string} */
    private function fail(string $message): array
    {
        return ['valid' => false, 'coupon' => null, 'user_coupon_id' => null, 'discount' => 0, 'message' => $message];
    }
}
