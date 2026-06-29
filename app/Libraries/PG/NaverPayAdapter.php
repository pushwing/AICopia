<?php

namespace App\Libraries\PG;

/**
 * 네이버페이 어댑터 (네이버페이 주문형)
 * 문서: https://developer.pay.naver.com/docs/v2/api
 */
class NaverPayAdapter implements PGInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $chainId;
    private string $apiBase = 'https://dev.apis.naver.com/naverpay-partner/naverpay/payments/v2';

    public function __construct()
    {
        $cfg                = config('PG');
        $this->clientId     = $cfg->naverpayClientId;
        $this->clientSecret = $cfg->naverpayClientSecret;
        $this->chainId      = $cfg->naverpayChainId;
    }

    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'          => 'naverpay',
            'clientId'    => $this->clientId,
            'chainId'     => $this->chainId,
            'orderId'     => $order['order_number'],
            'productName' => $this->buildOrderName($order),
            'totalPayAmount' => (int) $order['total_amount'],
            'taxScopeAmount' => (int) $order['total_amount'],
            'taxExScopeAmount' => 0,
            'returnUrl'   => base_url('payment/callback/naverpay?order_id=' . $order['id']),
        ];
    }

    /** pgToken = paymentId (네이버페이 결제 후 전달) */
    public function confirm(string $pgToken, int $expectedAmount): array
    {
        $response = $this->request('POST', '/apply', [
            'paymentId' => $pgToken,
        ]);

        $code    = $response['code'] ?? '';
        $detail  = $response['body'] ?? [];
        $success = ($code === 'Success') && isset($detail['paymentId']);

        if (! $success) {
            return ['success' => false, 'message' => $response['message'] ?? '승인 실패'];
        }

        if ((int) ($detail['totalPayAmount'] ?? 0) !== $expectedAmount) {
            return ['success' => false, 'message' => '결제 금액 불일치'];
        }

        return [
            'success' => true,
            'tid'     => $detail['paymentId'],
            'method'  => 'naverpay',
            'amount'  => (int) $detail['totalPayAmount'],
            'raw'     => $response,
        ];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $response = $this->request('POST', '/cancel', [
            'paymentId'    => $pgTid,
            'cancelAmount' => $amount,
            'cancelReason' => $reason,
            'cancelRequester' => '2',
        ]);

        $success = ($response['code'] ?? '') === 'Success';
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['message'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'naverpay';
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-Naver-Client-Id: ' . $this->clientId,
                'X-Naver-Client-Secret: ' . $this->clientSecret,
                'Content-Type: application/json',
            ],
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
