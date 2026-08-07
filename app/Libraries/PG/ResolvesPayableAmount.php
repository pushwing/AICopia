<?php

declare(strict_types=1);

namespace App\Libraries\PG;

/**
 * 결제창에 넘길 금액을 주문에서 뽑아낸다.
 *
 * 반드시 payable_amount(= total_amount - 쿠폰할인 - 포인트사용)를 써야 한다.
 * PaymentController::callback() 이 승인 요청·금액 검증을 payable_amount 로 하므로,
 * 결제창에 total_amount(할인 전)를 넘기면 쿠폰·포인트를 쓴 주문에서
 * 고객 결제액과 서버 승인액이 어긋나 PG 가 결제를 거부한다.
 *
 * payable_amount 컬럼이 없던 시절의 레거시 주문만 total_amount 로 폴백한다.
 */
trait ResolvesPayableAmount
{
    /** @param array<string, mixed> $order */
    private function payableAmount(array $order): int
    {
        return (int) ($order['payable_amount'] ?? $order['total_amount']);
    }
}
