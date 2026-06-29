<?php

namespace App\Libraries\PG;

/**
 * 나이스페이먼츠 어댑터 (NICE Payments API v2)
 * 문서: https://docs.nicepay.co.kr
 */
class NicePayAdapter implements PGInterface
{
    private string $clientId;
    private string $secretKey;
    private string $apiBase = 'https://api.nicepay.co.kr/v1';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->clientId  = $cfg->nicepayClientId;
        $this->secretKey = $cfg->nicepaySecretKey;
    }

    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'         => 'nicepay',
            'clientId'   => $this->clientId,
            'orderId'    => $order['order_number'],
            'amount'     => (int) $order['total_amount'],
            'goodsName'  => $this->buildOrderName($order),
            'buyerName'  => $order['receiver_name'],
        ];
    }

    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // pgToken = tid (나이스페이 결제 후 authResultCode=0000과 함께 전달)
        $response = $this->request('POST', "/payments/{$pgToken}", [
            'amount' => $expectedAmount,
        ]);

        $success = ($response['resultCode'] ?? '') === '0000';
        if (! $success) {
            return ['success' => false, 'message' => $response['resultMsg'] ?? 'PG 확인 실패'];
        }

        if ((int) ($response['amount'] ?? 0) !== $expectedAmount) {
            return ['success' => false, 'message' => '결제 금액 불일치'];
        }

        return [
            'success' => true,
            'tid'     => $response['tid'],
            'method'  => $this->mapMethod($response['payMethod'] ?? ''),
            'amount'  => (int) $response['amount'],
            'raw'     => $response,
        ];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $response = $this->request('POST', "/payments/{$pgTid}/cancel", [
            'reason' => $reason,
            'amount' => $amount,
        ]);

        $success = ($response['resultCode'] ?? '') === '0000';
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['resultMsg'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'nicepay';
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->secretKey),
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
            'card'     => 'card',
            'vbank'    => 'virtual_account',
            'bank'     => 'transfer',
            'cellphone' => 'phone',
            default    => 'card',
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
