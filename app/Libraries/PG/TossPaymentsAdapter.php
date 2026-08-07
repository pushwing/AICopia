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
        $keyError = $this->validateKeys();
        if ($keyError !== null) {
            // 잘못된 키로 결제창을 열면 apigw 가 400 만 던져 원인을 알 수 없다.
            // 뷰(launchPG)가 error 를 그대로 노출하도록 pg 판별자와 함께 돌려준다.
            return ['pg' => 'toss', 'error' => $keyError];
        }

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

    /**
     * 설정된 키가 "결제창(API 개별 연동)" 용인지 확인한다.
     *
     * 토스는 연동 방식마다 키 종류를 나눠 발급한다.
     *   결제창·브랜드페이(API 개별 연동) → test_ck_ / live_ck_ , test_sk_ / live_sk_
     *   결제위젯                          → test_gck_ / live_gck_, test_gsk_ / live_gsk_
     *
     * 이 어댑터는 결제창 SDK(js.tosspayments.com/v1/payment 의 requestPayment)를 쓰므로
     * `ck`/`sk` 키가 필요하다. 결제위젯 키를 넣으면 결제창을 열기 전 파라미터 검증
     * 단계에서 400 이 떨어지고, 시크릿 키가 어긋나면 승인(confirm)이 INVALID_API_KEY 로
     * 실패한다 — 후자는 고객은 결제됐는데 주문만 미완료로 남아 더 나쁘다.
     *
     * @return string|null 문제가 없으면 null, 있으면 사용자에게 보여줄 메시지
     */
    private function validateKeys(): ?string
    {
        if ($this->clientKey === '') {
            return '토스페이먼츠 클라이언트 키가 설정되지 않았습니다. (.env 의 TOSS_CLIENT_KEY)';
        }

        if ($this->secretKey === '') {
            return '토스페이먼츠 시크릿 키가 설정되지 않았습니다. (.env 의 TOSS_SECRET_KEY)';
        }

        if ($this->isWidgetKey($this->clientKey)) {
            return '토스페이먼츠 클라이언트 키가 결제위젯용(gck)입니다. '
                . '결제창 연동에는 test_ck_ / live_ck_ 로 시작하는 API 개별 연동 키가 필요합니다.';
        }

        if ($this->isWidgetKey($this->secretKey)) {
            return '토스페이먼츠 시크릿 키가 결제위젯용(gsk)입니다. '
                . '결제창 연동에는 test_sk_ / live_sk_ 로 시작하는 API 개별 연동 키가 필요합니다.';
        }

        return null;
    }

    /** 결제위젯 키는 test/live 뒤 구분자에 `g` 가 붙는다(gck·gsk). */
    private function isWidgetKey(string $key): bool
    {
        return (bool) preg_match('/^(?:test|live)_g(?:ck|sk)_/', $key);
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
