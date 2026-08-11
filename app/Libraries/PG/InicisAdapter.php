<?php

declare(strict_types=1);

namespace App\Libraries\PG;

/**
 * KG이니시스 어댑터 (INIpay Standard / INIApi)
 * 문서: https://manual.inicis.com
 */
class InicisAdapter implements PGInterface
{
    use ResolvesPayableAmount;

    private readonly string $merchantId;
    private readonly string $signKey;
    private string $apiBase = 'https://iniapi.inicis.com/api/v1';

    public function __construct()
    {
        $cfg              = config('PG');
        $this->merchantId = $cfg->inicisMerchantId;
        $this->signKey    = $cfg->inicisSignKey;
    }

    /**
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function buildPaymentParams(array $order): array
    {
        $timestamp = time() * 1000;
        $oid       = $order['order_number'];
        $price     = $this->payableAmount($order);

        return [
            'pg'        => 'inicis',
            // INIStdPay 표준 규격 — version·currency·gopaymethod·acceptmethod 가
            // 빠지면 INIStdPay.pay() 가 결제창을 띄우지 않는다.
            'version'   => '1.0',
            'currency'  => 'WON',
            'gopaymethod' => 'Card',
            // HPP(1): 휴대폰결제 허용, below1000: 1000원 미만 결제 허용,
            // va_receipt: 가상계좌 현금영수증 창 노출
            'acceptmethod' => 'HPP(1):below1000:va_receipt',
            'mid'       => $this->merchantId,
            'oid'       => $oid,
            'price'     => $price,
            'timestamp' => $timestamp,
            'signature' => hash('sha256', "oid={$oid}&price={$price}&timestamp={$timestamp}"),
            'mKey'      => hash('sha256', $this->signKey),
            'goodname'  => $this->buildOrderName($order),
            'buyername' => $order['receiver_name'],
            'buyertel'  => (string) ($order['receiver_phone'] ?? ''),
            'buyeremail' => (string) ($order['receiver_email'] ?? ''),
            // 인증 완료 후 이니시스가 POST 로 돌아오는 곳. attempt_id 가 없으면
            // PaymentController::callback() 이 주문 시도를 찾지 못해 결제가 끊긴다.
            'returnUrl' => base_url('payment/callback/inicis?attempt_id=' . $order['id']),
            // 사용자가 결제창을 그냥 닫은 것은 실패가 아니다 — order/fail(결제 실패 화면)로
            // 보내지 않고 주문서로 돌려보낸다. 장바구니는 결제 확정 전까지 비워지지 않으므로
            // (OrderController::create() 참고) 주문서를 그대로 다시 보여줄 수 있다.
            'closeUrl'  => base_url('order'),
        ];
    }

    /** @return array<string, mixed> */
    public function confirm(string $pgToken, int $expectedAmount): array
    {
        // pgToken = authToken (이니시스 결제 후 전달되는 인증 토큰)
        $timestamp = time() * 1000;
        $signature = hash('sha256', "authToken={$pgToken}&timestamp={$timestamp}");

        $response = $this->request('POST', '/payment', [
            'mid'       => $this->merchantId,
            'authToken' => $pgToken,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'charset'   => 'UTF-8',
            'format'    => 'JSON',
        ]);

        $success = ($response['resultCode'] ?? '') === '0000';
        if (! $success) {
            return ['success' => false, 'message' => $response['resultMsg'] ?? 'PG 확인 실패'];
        }

        if ((int) ($response['amt'] ?? 0) !== $expectedAmount) {
            return ['success' => false, 'message' => '결제 금액 불일치'];
        }

        return [
            'success' => true,
            'tid'     => $response['tid'],
            'method'  => $this->mapMethod($response['payMethod'] ?? ''),
            'amount'  => (int) $response['amt'],
            'raw'     => $response,
        ];
    }

    /** @return array{success: bool, message: string} */
    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        $timestamp = time() * 1000;
        $response  = $this->request('POST', '/cancel', [
            'mid'       => $this->merchantId,
            'tid'       => $pgTid,
            'msg'       => $reason,
            'timestamp' => $timestamp,
            'signature' => hash('sha256', "mid={$this->merchantId}&tid={$pgTid}&timestamp={$timestamp}"),
            'type'      => '0',
            'cancelprice' => $amount,
            'charset'   => 'UTF-8',
            'format'    => 'JSON',
        ]);

        $success = ($response['resultCode'] ?? '') === '0000';
        return [
            'success' => $success,
            'message' => $success ? '취소 완료' : ($response['resultMsg'] ?? '취소 실패'),
        ];
    }

    public function getProviderName(): string
    {
        return 'inicis';
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
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => http_build_query($body),
        ]);
        $result = curl_exec($ch);
        return json_decode($result ?: '{}', true) ?? [];
    }

    private function mapMethod(string $pgMethod): string
    {
        return match ($pgMethod) {
            'Card'         => 'card',
            'VBank'        => 'virtual_account',
            'Bank'         => 'transfer',
            'HPP'          => 'phone',
            'KAKAO'        => 'kakaopay',
            'NAVER'        => 'naverpay',
            default        => 'card',
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
