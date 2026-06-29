<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\CouponService;

class CouponController extends BaseController
{
    /** POST /coupon/check — 쿠폰 코드 AJAX 검증 */
    public function check()
    {
        $userId      = (int) session()->get('user_id');
        $code        = trim($this->request->getPost('coupon_code') ?? '');
        $orderAmount = max(0, (int) $this->request->getPost('order_amount'));

        if ($code === '') {
            return $this->response->setJSON(['valid' => false, 'message' => '쿠폰 코드를 입력해주세요.']);
        }

        $service = new CouponService();
        $result  = $service->validate($code, $userId, $orderAmount);

        if ($result['valid']) {
            $coupon = $result['coupon'];
            $discDesc = $coupon['type'] === 'fixed'
                ? number_format($coupon['discount_value']) . '원 할인'
                : $coupon['discount_value'] . '% 할인'
                  . ((int) $coupon['max_discount_amount'] > 0 ? ' (최대 ' . number_format($coupon['max_discount_amount']) . '원)' : '');
            $result['label'] = $coupon['name'] . ' — ' . number_format($result['discount']) . '원 할인';
        }

        return $this->response->setJSON($result);
    }
}
