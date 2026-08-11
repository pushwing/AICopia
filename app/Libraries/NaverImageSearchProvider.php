<?php

declare(strict_types=1);

namespace App\Libraries;

class NaverImageSearchProvider
{
    private readonly string $clientId;
    private readonly string $clientSecret;
    private string $endpoint = 'https://openapi.naver.com/v1/search/image.json';

    public function __construct()
    {
        $settings           = model('SettingModel')->getAllAsMap();
        $this->clientId     = $settings['naver_shopping_client_id']     ?? '';
        $this->clientSecret = $settings['naver_shopping_client_secret'] ?? '';
    }

    /**
     * 네이버 이미지 검색
     *
     * 네이버 쇼핑 검색 API(v1/search/shop.json)는 2026-07-31부로 대체 API 없이
     * 종료되었다(https://developers.naver.com/notice/article/32530). 같은 "검색" API
     * 애플리케이션에 포함된 이미지 검색(v1/search/image.json)은 계속 사용 가능해
     * 상품 이미지 수집 용도로 대체 적용한다. 상품 가격·판매처 등은 이미지 검색
     * 응답에 없으므로 제공하지 않는다.
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
            'filter'  => 'large',
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

        if ($curlErr || $raw === false) {
            log_message('error', "NaverImageSearch curl error: {$curlErr}");
            return ['items' => [], 'total' => 0, 'error' => '네트워크 오류가 발생했습니다.'];
        }

        $data = json_decode($raw, true);

        if ($httpCode !== 200) {
            $msg = $data['errorMessage'] ?? $data['message'] ?? "HTTP {$httpCode}";
            log_message('error', "NaverImageSearch API error [{$httpCode}]: {$msg}");
            return ['items' => [], 'total' => 0, 'error' => "API 오류: {$msg}"];
        }

        if (! is_array($data) || ! isset($data['items'])) {
            log_message('error', 'NaverImageSearch unexpected response: ' . $raw);
            return ['items' => [], 'total' => 0, 'error' => '응답 형식 오류'];
        }

        // thumbnail 은 네이버가 프록시하는 search.pstatic.net 이미지라 CSP img-src에
        // 안전하게 허용해 미리보기로 쓴다. image(원본)는 임의 도메인일 수 있어
        // 브라우저에 직접 노출하지 않고 서버 사이드 다운로드(import-image)에만 쓴다.
        $items = array_map(fn (array $item): array => [
            'title'     => html_entity_decode(strip_tags($item['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'thumbnail' => $item['thumbnail'] ?? '',
            'image'     => $item['link']      ?? '',
            'width'     => (int) ($item['sizewidth']  ?? 0),
            'height'    => (int) ($item['sizeheight'] ?? 0),
        ], $data['items']);

        return [
            'items' => $items,
            'total' => (int) ($data['total'] ?? 0),
        ];
    }
}
