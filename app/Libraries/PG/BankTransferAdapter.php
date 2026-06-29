<?php

namespace App\Libraries\PG;

class BankTransferAdapter implements PGInterface
{
    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'          => 'bank_transfer',
            'redirectUrl' => '/order/bank_transfer/' . $order['order_number'],
        ];
    }

    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // 무통장입금은 PG 콜백 없음 — 관리자가 수동 확인
        return ['success' => false, 'message' => '무통장입금은 관리자 입금 확인이 필요합니다.'];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        return ['success' => true, 'message' => '취소 완료'];
    }

    public function getProviderName(): string
    {
        return '무통장입금';
    }
}
