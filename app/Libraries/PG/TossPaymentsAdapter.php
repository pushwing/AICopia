<?php

namespace App\Libraries\PG;

/**
 * 토스페이먼츠 v2 API 어댑터
 * 문서: https://docs.tosspayments.com/reference
 */
class TossPaymentsAdapter implements PGInterface
{
    private string $clientKey;
    private string $secretKey;
    private string $apiBase = 'https://api.tosspayments.com/v1';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->clientKey = $cfg->tossClientKey;
        $this->secretKey = $cfg->tossSecretKey;
    }

    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'          => 'toss',
            'clientKey'   => $this->clientKey,
            'orderNumber' => $order['order_number'],
            'orderId'     => (string) $order['id'],
            'orderName'   => $this->buildOrderName($order),
            'amount'      => (int) $order['total_amount'],
            'customerName' => $order['receiver_name'],
        ];
    }

    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // pgToken = paymentKey (토스페이먼츠 결제창에서 전달)
        $response = $this->request('POST', '/payments/confirm', [
            'paymentKey' => $pgToken,
            'amount'     => $expectedAmount,
            'orderId'    => session()->get('toss_order_id') ?? '',
        ]);

        if (empty($response) || ($response['status'] ?? '') !== 'DONE') {
            return ['success' => false, 'message' => $response['message'] ?? 'PG 확인 실패'];
        }

        return [
            'success' => true,
            'tid'     => $response['paymentKey'],
            'method'  => $this->mapMethod($response['method'] ?? ''),
            'amount'  => (int) $response['totalAmount'],
            'raw'     => $response,
        ];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $response = $this->request('POST', "/payments/{$pgTid}/cancel", [
            'cancelReason' => $reason,
            'cancelAmount' => $amount,
        ]);

        $success = isset($response['cancels']);
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['message'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'toss';
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result ?: '{}', true) ?? [];
    }

    private function mapMethod(string $pgMethod): string
    {
        return match ($pgMethod) {
            '카드'       => 'card',
            '가상계좌'    => 'virtual_account',
            '계좌이체'    => 'transfer',
            '휴대폰'     => 'phone',
            '카카오페이'  => 'kakaopay',
            '네이버페이'  => 'naverpay',
            'PAYCO'      => 'payco',
            default      => 'card',
        };
    }

    private function buildOrderName(array $order): string
    {
        $items = $order['items'] ?? [];
        if (empty($items)) return '주문 ' . $order['order_number'];

        $first = $items[0]['product_name'] ?? '';
        $extra = count($items) > 1 ? ' 외 ' . (count($items) - 1) . '건' : '';
        return $first . $extra;
    }
}
