<?php

namespace App\Libraries\AiProvider;

class ClaudeProvider implements AiProviderInterface
{
    use ReviewSummaryParsing;
    use InquiryParsing;
    use SearchExpandParsing;

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL   = 'claude-haiku-4-5-20251001';

    private string $apiKey;

    public function __construct()
    {
        $settings     = model('SettingModel')->getAllAsMap();
        $this->apiKey = ($settings['anthropic_api_key'] ?? '') ?: env('ANTHROPIC_API_KEY', '');
    }

    public function suggestCategories(string $name, string $description, array $tree): array
    {
        $list = $this->flattenTree($tree);
        $desc = mb_substr(strip_tags($description), 0, 500);

        $systemPrompt = AiPrompts::render('category', ['categories' => $list]);

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 128,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => "상품명: {$name}\n상품 설명: {$desc}"],
            ],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return [];
        }

        return $this->parseResponse($response);
    }

    protected function flattenTree(array $tree): string
    {
        $lines = [];
        foreach ($tree as $parent) {
            $lines[] = "- {$parent['id']}: {$parent['name']}";
            foreach ($parent['children'] as $child) {
                $lines[] = "  - {$child['id']}: {$child['name']}";
            }
        }
        return implode("\n", $lines);
    }

    protected function parseResponse(string $raw): array
    {
        $data    = json_decode($raw, true);
        $content = $data['content'][0]['text'] ?? '';
        // JSON 블록만 추출
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $parsed = json_decode($content, true);
        $ids    = $parsed['category_ids'] ?? [];
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    public function generateDescription(string $name, string $description): string
    {
        $cleanDesc = mb_substr(strip_tags($description), 0, 1000);

        $systemPrompt = AiPrompts::get('description');

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 800,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => "상품명: {$name}\n기존 설명 참고: {$cleanDesc}"],
            ],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        $text = $data['content'][0]['text'] ?? '';
        return $text !== '' ? $this->convertToHtml($text) : '';
    }

    private function convertToHtml(string $text): string
    {
        $text = preg_replace('/```[\s\S]*?```/', '', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/u',     '<strong>$1</strong>', $text);

        $lines     = explode("\n", $text);
        $result    = [];
        $listItems = [];

        $flushList = static function (array &$items, array &$out): void {
            if ($items !== []) {
                $out[]  = '<ul>' . implode('', array_map(fn ($i) => "<li>{$i}</li>", $items)) . '</ul>';
                $items  = [];
            }
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { $flushList($listItems, $result); continue; }

            if (preg_match('/^#{1,3}\s+(.+)/u', $line, $m)) {
                $flushList($listItems, $result);
                $result[] = '<p><strong>' . $m[1] . '</strong></p>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)/u', $line, $m)) {
                $listItems[] = $m[1];
                continue;
            }

            $flushList($listItems, $result);

            if (preg_match('/<(p|ul|li|strong|br)[^>]*>/i', $line)) {
                $result[] = $line;
            } else {
                $result[] = "<p>{$line}</p>";
            }
        }

        $flushList($listItems, $result);

        return implode("\n", $result);
    }

    public function generateQnaAnswer(string $productName, string $productDescription, string $questionTitle, string $questionContent): string
    {
        $cleanDesc = mb_substr(strip_tags($productDescription), 0, 500);

        $systemPrompt = AiPrompts::get('qna');

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 400,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => "상품명: {$productName}\n상품 설명: {$cleanDesc}\n\n문의 제목: {$questionTitle}\n문의 내용: {$questionContent}"],
            ],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['content'][0]['text'] ?? '';
    }

    public function summarizeReviews(string $productName, array $reviews): array
    {
        if ($reviews === []) {
            return $this->emptySummary();
        }

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 1024,
            'system'     => AiPrompts::get('review_summary'),
            'messages'   => [
                ['role' => 'user', 'content' => $this->buildReviewMessage($productName, $reviews)],
            ],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return $this->emptySummary();
        }

        $data = json_decode($raw, true);
        return $this->parseSummary($data['content'][0]['text'] ?? '');
    }

    public function classifyInquiry(string $subject, string $message): array
    {
        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 128,
            'system'     => AiPrompts::get('inquiry_classify'),
            'messages'   => [
                ['role' => 'user', 'content' => $this->buildInquiryMessage($subject, $message)],
            ],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return $this->emptyClassification();
        }

        $data = json_decode($raw, true);
        return $this->parseClassification($data['content'][0]['text'] ?? '');
    }

    public function generateInquiryReply(string $name, string $subject, string $message): string
    {
        $cleanMsg = mb_substr(strip_tags($message), 0, 1000);

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 600,
            'system'     => AiPrompts::get('inquiry_reply'),
            'messages'   => [
                ['role' => 'user', 'content' => "고객명: {$name}\n제목: {$subject}\n문의 내용: {$cleanMsg}"],
            ],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['content'][0]['text'] ?? '';
    }

    public function expandSearchQuery(string $query): array
    {
        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 256,
            'system'     => AiPrompts::get('search_expand'),
            'messages'   => [
                ['role' => 'user', 'content' => "검색어: " . mb_substr($query, 0, 50)],
            ],
        ]);

        $raw = $this->callApi($payload, 15);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        return $this->parseTerms($data['content'][0]['text'] ?? '');
    }

    public function generateRestockMessage(string $productName, string $productDescription): string
    {
        $cleanDesc = mb_substr(strip_tags($productDescription), 0, 500);

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 300,
            'system'     => AiPrompts::get('restock_message'),
            'messages'   => [
                ['role' => 'user', 'content' => "상품명: {$productName}\n상품 설명: {$cleanDesc}"],
            ],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return trim((string) ($data['content'][0]['text'] ?? ''));
    }

    public function generateSalesReport(array $stats): string
    {
        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 1024,
            'system'     => AiPrompts::get('sales_report'),
            'messages'   => [
                ['role' => 'user', 'content' => json_encode($stats, JSON_UNESCAPED_UNICODE)],
            ],
        ]);

        $raw = $this->callApi($payload, 40);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['content'][0]['text'] ?? '';
    }

    /**
     * 상품 이미지(Vision)를 분석해 상품명·설명(HTML)을 추출한다.
     * Vision은 Claude 전용이라 AiProviderInterface에는 두지 않는다.
     *
     * @param  string $imageData base64 인코딩된 이미지 데이터
     * @param  string $mimeType  image/jpeg | image/png | image/gif | image/webp
     * @return array{name:string, description:string} 실패 시 빈 값
     */
    public function extractProductInfo(string $imageData, string $mimeType): array
    {
        $empty = ['name' => '', 'description' => ''];

        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => 1024,
            'system'     => AiPrompts::get('product_vision'),
            'messages'   => [
                ['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $imageData]],
                    ['type' => 'text', 'text' => '이 상품 이미지를 분석해 상품명과 설명을 작성해주세요.'],
                ]],
            ],
        ]);

        $raw = $this->callApi($payload, 40);
        if ($raw === false) {
            return $empty;
        }

        $data = json_decode($raw, true);
        $text = $data['content'][0]['text'] ?? '';
        if ($text === '') {
            return $empty;
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $parsed = json_decode($text, true);
        if (! is_array($parsed) || trim((string) ($parsed['name'] ?? '')) === '') {
            return $empty;
        }

        $description = trim((string) ($parsed['description'] ?? ''));

        return [
            'name'        => trim(mb_substr((string) $parsed['name'], 0, 100)),
            'description' => $description !== '' ? $this->convertToHtml($description) : '',
        ];
    }

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 && $response !== false) ? $response : false;
    }
}
