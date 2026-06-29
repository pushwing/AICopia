<?php

namespace App\Libraries\PG;

/**
 * PAYCO 어댑터
 * 문서: https://developers.payco.com/guide
 */
class PaycoAdapter implements PGInterface
{
    private string $sellerKey;
    private string $secretKey;
    private string $apiBase = 'https://api-pay.payco.com/v2.1';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->sellerKey = $cfg->paycoSellerKey;
        $this->secretKey = $cfg->paycoSecretKey;
    }

    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'            => 'payco',
            'sellerKey'     => $this->sellerKey,
            'orderId'       => $order['order_number'],
            'productName'   => $this->buildOrderName($order),
            'totalAmount'   => (int) $order['total_amount'],
            'taxFreeAmount' => 0,
            'returnUrl'     => base_url('payment/callback/payco?order_id=' . $order['id']),
            'cancelUrl'     => base_url('order/fail/' . $order['order_number']),
        ];
    }

    /** pgToken = reserveOrderNo (PAYCO 결제 후 전달) */
    public function confirm(string $pgToken, int $expectedAmount): array
    {
        $timestamp = (int) (microtime(true) * 1000);
        $signature = hash_hmac('sha256', $this->sellerKey . $pgToken . $timestamp, $this->secretKey);

        $response = $this->request('POST', '/payment/approval', [
            'sellerKey'      => $this->sellerKey,
            'reserveOrderNo' => $pgToken,
            'totalAmount'    => $expectedAmount,
            'timestamp'      => $timestamp,
            'signature'      => $signature,
        ]);

        $success = ($response['header']['isSuccessful'] ?? false) === true;
        if (! $success) {
            return ['success' => false, 'message' => $response['header']['resultMessage'] ?? '승인 실패'];
        }

        $body = $response['body'] ?? [];
        if ((int) ($body['totalAmount'] ?? 0) !== $expectedAmount) {
            return ['success' => false, 'message' => '결제 금액 불일치'];
        }

        return [
            'success' => true,
            'tid'     => $body['orderNo'] ?? $pgToken,
            'method'  => 'payco',
            'amount'  => (int) $body['totalAmount'],
            'raw'     => $response,
        ];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $timestamp = (int) (microtime(true) * 1000);
        $signature = hash_hmac('sha256', $this->sellerKey . $pgTid . $timestamp, $this->secretKey);

        $response = $this->request('POST', '/payment/refund', [
            'sellerKey' => $this->sellerKey,
            'orderNo'   => $pgTid,
            'refundAmount' => $amount,
            'reason'    => $reason,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);

        $success = ($response['header']['isSuccessful'] ?? false) === true;
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['header']['resultMessage'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'payco';
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result ?: '{}', true) ?? [];
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
