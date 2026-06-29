<?php

namespace App\Libraries;

class NaverShoppingProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $endpoint = 'https://openapi.naver.com/v1/search/shop.json';

    public function __construct()
    {
        $settings           = model('SettingModel')->getAllAsMap();
        $this->clientId     = $settings['naver_shopping_client_id']     ?? '';
        $this->clientSecret = $settings['naver_shopping_client_secret'] ?? '';
    }

    /**
     * 네이버 쇼핑 상품 검색
     *
     * @return array{items: list<array>, total: int, error?: string}
     */
    public function search(string $keyword, int $display = 10, int $start = 1): array
    {
        if ($keyword === '' || $this->clientId === '' || $this->clientSecret === '') {
            return ['items' => [], 'total' => 0];
        }

        $url = $this->endpoint . '?' . http_build_query([
            'query'   => $keyword,
            'display' => $display,
            'start'   => $start,
            'sort'    => 'sim',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'X-Naver-Client-Id: '     . $this->clientId,
                'X-Naver-Client-Secret: ' . $this->clientSecret,
            ],
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || $raw === false) {
            log_message('error', "NaverShopping curl error: {$curlErr}");
            return ['items' => [], 'total' => 0, 'error' => '네트워크 오류가 발생했습니다.'];
        }

        $data = json_decode($raw, true);

        if ($httpCode !== 200) {
            $msg = $data['errorMessage'] ?? $data['message'] ?? "HTTP {$httpCode}";
            log_message('error', "NaverShopping API error [{$httpCode}]: {$msg}");
            return ['items' => [], 'total' => 0, 'error' => "API 오류: {$msg}"];
        }

        if (! is_array($data) || ! isset($data['items'])) {
            log_message('error', 'NaverShopping unexpected response: ' . $raw);
            return ['items' => [], 'total' => 0, 'error' => '응답 형식 오류'];
        }

        $items = array_map(function (array $item): array {
            return [
                'title'     => html_entity_decode(strip_tags($item['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'image'     => $item['image']     ?? '',
                'lprice'    => $item['lprice']    ?? '0',
                'hprice'    => $item['hprice']    ?? '0',
                'mallName'  => $item['mallName']  ?? '',
                'brand'     => $item['brand']     ?? '',
                'category1' => $item['category1'] ?? '',
                'link'      => $item['link']      ?? '',
            ];
        }, $data['items']);

        return [
            'items' => $items,
            'total' => (int) ($data['total'] ?? 0),
        ];
    }
}
