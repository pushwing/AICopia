<?php

declare(strict_types=1);

namespace App\Libraries\PG;

/**
 * 카카오페이 어댑터
 * 문서: https://developers.kakaopay.com/docs/payment/online/ready
 */
class KakaoPayAdapter implements PGInterface
{
    use ResolvesPayableAmount;

    private readonly string $secretKey;
    private readonly string $cid;
    private string $apiBase = 'https://open-api.kakaopay.com/online/v1/payment';

    public function __construct()
    {
        $cfg             = config('PG');
        $this->secretKey = $cfg->kakaoSecretKey;
        $this->cid       = $cfg->kakaoCid;
    }

    /**
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function buildPaymentParams(array $order): array
    {
        $keyError = $this->validateKeys();
        if ($keyError !== null) {
            return ['pg' => 'kakaopay', 'error' => $keyError];
        }

        $ready = $this->ready($order);
        if (empty($ready['next_redirect_pc_url'])) {
            return $this->buildReadyFailureResult(is_string($ready['msg'] ?? null) ? $ready['msg'] : null);
        }

        return [
            'pg'          => 'kakaopay',
            'redirectUrl' => $ready['next_redirect_pc_url'],
            'mobileUrl'   => $ready['next_redirect_mobile_url'] ?? '',
            'tid'         => $ready['tid'],
        ];
    }

    /**
     * ready() 실패 시 반환값에도 'pg' 키를 넣어야 한다 — checkout.php 의 launchPG() 가
     * p.pg 값으로 PG별 처리를 분기하므로, 'pg' 키가 빠지면 카카오페이 전용 에러 처리로
     * 가지 못하고 "지원하지 않는 PG입니다" 로 떨어져 실제 원인을 가린다.
     *
     * 카카오 API 가 실패 사유(msg)를 내려주면 뭉뚱그린 기본 문구 대신 그대로 노출해
     * 운영자가 원인(키 오류·CID 미등록 등)을 바로 알 수 있게 한다.
     *
     * @return array<string, mixed>
     */
    private function buildReadyFailureResult(?string $apiMessage = null): array
    {
        return ['pg' => 'kakaopay', 'error' => $apiMessage ?: '카카오페이 결제 준비 실패'];
    }

    /**
     * 시크릿 키가 비어 있으면 카카오 API 가 401 을 던지고, ready() 응답에는
     * next_redirect_pc_url 이 없어 무조건 "결제 준비 실패"로만 보인다.
     * 토스 어댑터(TossPaymentsAdapter::validateKeys())와 동일하게 네트워크를 타기
     * 전에 키 존재를 먼저 걸러 실제 설정 문제를 바로 보여준다.
     *
     * @return string|null 문제가 없으면 null, 있으면 사용자에게 보여줄 메시지
     */
    private function validateKeys(): ?string
    {
        if ($this->secretKey === '') {
            return '카카오페이 시크릿 키가 설정되지 않았습니다. (.env 의 KAKAOPAY_SECRET_KEY)';
        }

        return null;
    }

    /**
     * 카카오페이는 준비(ready) → 승인(approve) 2단계
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function ready(array $order): array
    {
        return $this->request('POST', '/ready', $this->buildReadyPayload($order));
    }

    /**
     * ready 요청 본문 조립 — 네트워크 호출과 분리해 두어야 금액 산출을 단위 테스트할 수 있다.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildReadyPayload(array $order): array
    {
        $baseUrl = base_url();

        return [
            'cid'              => $this->cid,
            'partner_order_id' => $order['order_number'],
            'partner_user_id'  => (string) $order['user_id'],
            'item_name'        => $this->buildOrderName($order),
            'quantity'         => array_sum(array_column($order['items'] ?? [], 'qty')) ?: 1,
            'total_amount'     => $this->payableAmount($order),
            'tax_free_amount'  => 0,
            'approval_url'     => $baseUrl . 'payment/callback/kakaopay?order_id=' . $order['id'],
            // 카드 승인 거절 등 진짜 결제 실패는 fail_url — 결제 실패 화면으로 안내한다.
            'fail_url'         => $baseUrl . 'order/fail/' . $order['order_number'],
            // 사용자가 카카오페이 결제창에서 그냥 취소한 것은 실패가 아니다 — 주문서로
            // 돌려보낸다. 장바구니는 결제 확정 전까지 비워지지 않는다(OrderController::create()).
            'cancel_url'       => $baseUrl . 'order',
        ];
    }

    /**
     * pgToken = pg_token (카카오페이 승인 콜백에서 전달)
     *
     * @return array<string, mixed>
     */
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

    /** @return array{success: bool, message: string} */
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
                'Authorization: SECRET_KEY ' . $this->secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $result = curl_exec($ch);
        return json_decode($result ?: '{}', true) ?? [];
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
