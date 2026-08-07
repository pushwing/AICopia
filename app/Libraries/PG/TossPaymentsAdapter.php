<?php

declare(strict_types=1);

namespace App\Libraries\PG;

/**
 * 토스페이먼츠 v2 API 어댑터
 * 문서: https://docs.tosspayments.com/reference
 */
class TossPaymentsAdapter implements PGInterface
{
    private readonly string $clientKey;
    private readonly string $secretKey;
    private string $apiBase = 'https://api.tosspayments.com/v1';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->clientKey = $cfg->tossClientKey;
        $this->secretKey = $cfg->tossSecretKey;
    }

    /**
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function buildPaymentParams(array $order): array
    {
        return [
            'pg'          => 'toss',
            'clientKey'   => $this->clientKey,
            'orderNumber' => $order['order_number'],

            // 토스 규격: orderId 는 영문·숫자·`-`·`_` 만 허용하고 6자 이상 64자 이하다.
            // DB PK 를 그대로 넘기면 초기 주문은 6자 미만이라 결제창을 열기 전
            // apigw 검증에서 400(INVALID_ORDER_ID)으로 막힌다.
            // 주문번호(ORD-YYYYMMDD-NNNNN)는 18자 + 허용 문자만 써서 규격을 만족한다.
            'orderId'     => (string) $order['order_number'],

            'orderName'   => $this->buildOrderName($order),
            'amount'      => (int) $order['total_amount'],
            'customerName' => $order['receiver_name'],

            // 콜백 URL 은 다른 PG 어댑터와 동일하게 어댑터가 만든다.
            // 콜백은 주문을 DB PK 로 조회하므로 order_id 에는 반드시 id 를 넘긴다
            // (토스가 successUrl 에 덧붙이는 orderId 는 위 주문번호라 이름이 겹치지 않는다).
            'successUrl'  => base_url('payment/callback/toss?order_id=' . $order['id']),
            'failUrl'     => base_url('order/fail/' . $order['order_number']),
        ];
    }

    /** @return array<string, mixed> */
    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // pgToken = paymentKey (토스페이먼츠 결제창에서 전달)
        // orderId 는 결제창에 넘긴 값과 정확히 같아야 승인된다
        // (OrderController 가 buildPaymentParams()의 orderId 를 그대로 세션에 담아 둔다).
        $response = $this->request('POST', '/payments/confirm', [
            'paymentKey' => $pgToken,
            'amount'     => $expectedAmount,
            'orderId'    => session()->get('toss_order_id') ?? '',
        ]);

        if ($response === [] || ($response['status'] ?? '') !== 'DONE') {
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

    /** @return array{success: bool, message: string} */
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

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
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

    /** @param array<string, mixed> $order */
    private function buildOrderName(array $order): string
    {
        $items = $order['items'] ?? [];
        if (empty($items)) {
            return '주문 ' . $order['order_number'];
        }

        $first = $items[0]['product_name'] ?? '';
        $extra = count($items) > 1 ? ' 외 ' . (count($items) - 1) . '건' : '';
        return $first . $extra;
    }
}
