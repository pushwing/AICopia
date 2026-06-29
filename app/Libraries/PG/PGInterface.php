<?php

namespace App\Libraries\PG;

interface PGInterface
{
    /**
     * 결제창 초기화 파라미터 반환 (프론트 JS에 전달)
     */
    public function buildPaymentParams(array $order): array;

    /**
     * 서버사이드 결제 확인 — PG에 승인 요청 후 결과 반환
     * 반환: ['success' => bool, 'tid' => string, 'method' => string, 'amount' => int, 'raw' => array]
     */
    public function confirm(string $pgToken, int $expectedAmount): array;

    /**
     * 결제 취소/환불
     * 반환: ['success' => bool, 'message' => string]
     */
    public function cancel(string $pgTid, int $amount, string $reason): array;

    public function getProviderName(): string;
}
