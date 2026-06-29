<?php

namespace App\Libraries\PG;

/**
 * 카카오페이 어댑터
 * 문서: https://developers.kakaopay.com/docs/payment/online/ready
 */
class KakaoPayAdapter implements PGInterface
{
    private string $secretKey;
    private string $cid;
    private string $apiBase = 'https://open-api.kakaopay.com/online/v1/payment';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->secretKey = $cfg->kakaoSecretKey;
        $this->cid       = $cfg->kakaoCid;
    }

    public function buildPaymentParams(array $order): array
    {
        $ready = $this->ready($order);
        if (empty($ready['next_redirect_pc_url'])) {
            return ['error' => '카카오페이 결제 준비 실패'];
        }

        return [
            'pg'          => 'kakaopay',
            'redirectUrl' => $ready['next_redirect_pc_url'],
            'mobileUrl'   => $ready['next_redirect_mobile_url'] ?? '',
            'tid'         => $ready['tid'],
        ];
    }

    /** 카카오페이는 준비(ready) → 승인(approve) 2단계 */
    private function ready(array $order): array
    {
        $baseUrl = base_url();
        return $this->request('POST', '/ready', [
            'cid'              => $this->cid,
            'partner_order_id' => $order['order_number'],
            'partner_user_id'  => (string) $order['user_id'],
            'item_name'        => $this->buildOrderName($order),
            'quantity'         => array_sum(array_column($order['items'] ?? [], 'qty')) ?: 1,
            'total_amount'     => (int) $order['total_amount'],
            'tax_free_amount'  => 0,
            'approval_url'     => $baseUrl . 'payment/callback/kakaopay?order_id=' . $order['id'],
            'fail_url'         => $baseUrl . 'order/fail/' . $order['order_number'],
            'cancel_url'       => $baseUrl . 'order/fail/' . $order['order_number'],
        ]);
    }

    /** pgToken = pg_token (카카오페이 승인 콜백에서 전달) */
    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // tid는 호출자(PaymentController)가 세션에서 꺼내서 주입
        $tid    = session()->get('kakaopay_tid') ?? '';
        $userId = (int) session()->get('user_id');

        $response = $this->request('POST', '/approve', [
            'cid'              => $this->cid,
            'tid'              => $tid,
            'partner_order_id' => session()->get('kakaopay_order_number') ?? '',
            'partner_user_id'  => (string) $userId,
            'pg_token'         => $pgToken,
        ]);

        $success = isset($response['aid']);
        if (! $success) {
            return ['success' => false, 'message' => $response['msg'] ?? '승인 실패'];
        }

        if ((int) ($response['amount']['total'] ?? 0) !== $expectedAmount) {
            return ['success' => false, 'message' => '결제 금액 불일치'];
        }

        return [
            'success' => true,
            'tid'     => $response['tid'],
            'method'  => 'kakaopay',
            'amount'  => (int) $response['amount']['total'],
            'raw'     => $response,
        ];
    }

    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $response = $this->request('POST', '/cancel', [
            'cid'              => $this->cid,
            'tid'              => $pgTid,
            'cancel_amount'    => $amount,
            'cancel_tax_free_amount' => 0,
        ]);

        $success = isset($response['status']) && $response['status'] === 'CANCEL_PAYMENT';
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['msg'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'kakaopay';
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: SECRET_KEY ' . $this->secretKey,
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
