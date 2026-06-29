<?php

namespace App\Libraries\PG;

/**
 * KG이니시스 어댑터 (INIpay Standard / INIApi)
 * 문서: https://manual.inicis.com
 */
class InicisAdapter implements PGInterface
{
    private string $merchantId;
    private string $signKey;
    private string $apiBase = 'https://iniapi.inicis.com/api/v1';

    public function __construct()
    {
        $cfg              = config('PG');
        $this->merchantId = $cfg->inicisMerchantId;
        $this->signKey    = $cfg->inicisSignKey;
    }

    public function buildPaymentParams(array $order): array
    {
        $timestamp = time() * 1000;
        $oid       = $order['order_number'];
        $price     = (int) $order['total_amount'];

        return [
            'pg'         => 'inicis',
            'mid'        => $this->merchantId,
            'oid'        => $oid,
            'price'      => $price,
            'timestamp'  => $timestamp,
            'signature'  => hash('sha256', "oid={$oid}&price={$price}&timestamp={$timestamp}"),
            'mKey'       => hash('sha256', $this->signKey),
            'goodname'   => $this->buildOrderName($order),
            'buyername'  => $order['receiver_name'],
        ];
    }

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

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => http_build_query($body),
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
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

    private function buildOrderName(array $order): string
    {
        $items = $order['items'] ?? [];
        if (empty($items)) return '주문 ' . $order['order_number'];
        $first = $items[0]['product_name'] ?? '';
        $extra = count($items) > 1 ? ' 외 ' . (count($items) - 1) . '건' : '';
        return $first . $extra;
    }
}
