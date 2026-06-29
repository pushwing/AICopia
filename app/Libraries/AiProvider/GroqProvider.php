<?php

namespace App\Libraries\AiProvider;

class GroqProvider implements AiProviderInterface
{
    use ReviewSummaryParsing;
    use InquiryParsing;
    use SearchExpandParsing;

    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL   = 'llama-3.1-8b-instant';

    private string $apiKey;

    public function __construct()
    {
        $settings     = model('SettingModel')->getAllAsMap();
        $this->apiKey = ($settings['groq_api_key'] ?? '') ?: env('GROQ_API_KEY', '');
    }

    public function suggestCategories(string $name, string $description, array $tree): array
    {
        $prompt = $this->buildPrompt($name, $description, $tree);

        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.1,
            'max_tokens'  => 128,
            'messages'    => [
                ['role' => 'system', 'content' => $this->systemPrompt($tree)],
                ['role' => 'user',   'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
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

    protected function systemPrompt(array $tree): string
    {
        return AiPrompts::render('category', ['categories' => $this->flattenTree($tree)]);
    }

    protected function buildPrompt(string $name, string $description, array $tree): string
    {
        $desc = mb_substr(strip_tags($description), 0, 500);
        return "상품명: {$name}\n상품 설명: {$desc}";
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
        $data = json_decode($raw, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $parsed  = json_decode($content, true);
        $ids     = $parsed['category_ids'] ?? [];
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    public function generateDescription(string $name, string $description): string
    {
        $cleanDesc = mb_substr(strip_tags($description), 0, 1000);

        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.7,
            'max_tokens'  => 800,
            'messages'    => [
                ['role' => 'system', 'content' => AiPrompts::get('description')],
                ['role' => 'user',   'content' => "상품명: {$name}\n기존 설명 참고: {$cleanDesc}"],
            ],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        return $text !== '' ? $this->convertToHtml($text) : '';
    }

    public function generateQnaAnswer(string $productName, string $productDescription, string $questionTitle, string $questionContent): string
    {
        $cleanDesc = mb_substr(strip_tags($productDescription), 0, 500);

        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.4,
            'max_tokens'  => 400,
            'messages'    => [
                ['role' => 'system', 'content' => AiPrompts::get('qna')],
                ['role' => 'user',   'content' => "상품명: {$productName}\n상품 설명: {$cleanDesc}\n\n문의 제목: {$questionTitle}\n문의 내용: {$questionContent}"],
            ],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    public function summarizeReviews(string $productName, array $reviews): array
    {
        if ($reviews === []) {
            return $this->emptySummary();
        }

        $payload = json_encode([
            'model'           => self::MODEL,
            'temperature'     => 0.3,
            'max_tokens'      => 1024,
            'messages'        => [
                ['role' => 'system', 'content' => AiPrompts::get('review_summary')],
                ['role' => 'user',   'content' => $this->buildReviewMessage($productName, $reviews)],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return $this->emptySummary();
        }

        $data = json_decode($raw, true);
        return $this->parseSummary($data['choices'][0]['message']['content'] ?? '');
    }

    public function classifyInquiry(string $subject, string $message): array
    {
        $payload = json_encode([
            'model'           => self::MODEL,
            'temperature'     => 0.1,
            'max_tokens'      => 128,
            'messages'        => [
                ['role' => 'system', 'content' => AiPrompts::get('inquiry_classify')],
                ['role' => 'user',   'content' => $this->buildInquiryMessage($subject, $message)],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return $this->emptyClassification();
        }

        $data = json_decode($raw, true);
        return $this->parseClassification($data['choices'][0]['message']['content'] ?? '');
    }

    public function generateInquiryReply(string $name, string $subject, string $message): string
    {
        $cleanMsg = mb_substr(strip_tags($message), 0, 1000);

        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.4,
            'max_tokens'  => 600,
            'messages'    => [
                ['role' => 'system', 'content' => AiPrompts::get('inquiry_reply')],
                ['role' => 'user',   'content' => "고객명: {$name}\n제목: {$subject}\n문의 내용: {$cleanMsg}"],
            ],
        ]);

        $raw = $this->callApi($payload, 30);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    public function expandSearchQuery(string $query): array
    {
        $payload = json_encode([
            'model'           => self::MODEL,
            'temperature'     => 0.2,
            'max_tokens'      => 200,
            'messages'        => [
                ['role' => 'system', 'content' => AiPrompts::get('search_expand')],
                ['role' => 'user',   'content' => "검색어: " . mb_substr($query, 0, 50)],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $raw = $this->callApi($payload, 15);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        return $this->parseTerms($data['choices'][0]['message']['content'] ?? '');
    }

    public function generateRestockMessage(string $productName, string $productDescription): string
    {
        $cleanDesc = mb_substr(strip_tags($productDescription), 0, 500);

        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.6,
            'max_tokens'  => 300,
            'messages'    => [
                ['role' => 'system', 'content' => AiPrompts::get('restock_message')],
                ['role' => 'user',   'content' => "상품명: {$productName}\n상품 설명: {$cleanDesc}"],
            ],
        ]);

        $raw = $this->callApi($payload, 20);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    }

    public function generateSalesReport(array $stats): string
    {
        $payload = json_encode([
            'model'       => self::MODEL,
            'temperature' => 0.4,
            'max_tokens'  => 1024,
            'messages'    => [
                ['role' => 'system', 'content' => AiPrompts::get('sales_report')],
                ['role' => 'user',   'content' => json_encode($stats, JSON_UNESCAPED_UNICODE)],
            ],
        ]);

        $raw = $this->callApi($payload, 40);
        if ($raw === false) {
            return '';
        }

        $data = json_decode($raw, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function convertToHtml(string $text): string
    {
        // 코드블록 제거
        $text = preg_replace('/```[\s\S]*?```/', '', $text);

        // **bold** / __bold__ → <strong>
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/u',     '<strong>$1</strong>', $text);

        $lines      = explode("\n", $text);
        $result     = [];
        $listItems  = [];

        $flushList = static function (array &$items, array &$out): void {
            if ($items !== []) {
                $out[]  = '<ul>' . implode('', array_map(fn ($i) => "<li>{$i}</li>", $items)) . '</ul>';
                $items  = [];
            }
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { $flushList($listItems, $result); continue; }

            // ## 헤딩 → <p><strong>
            if (preg_match('/^#{1,3}\s+(.+)/u', $line, $m)) {
                $flushList($listItems, $result);
                $result[] = '<p><strong>' . $m[1] . '</strong></p>';
                continue;
            }

            // - 또는 * 리스트 항목
            if (preg_match('/^[-*]\s+(.+)/u', $line, $m)) {
                $listItems[] = $m[1];
                continue;
            }

            $flushList($listItems, $result);

            // 이미 HTML 태그가 있으면 그대로
            if (preg_match('/<(p|ul|li|strong|br)[^>]*>/i', $line)) {
                $result[] = $line;
            } else {
                $result[] = "<p>{$line}</p>";
            }
        }

        $flushList($listItems, $result);

        return implode("\n", $result);
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
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 && $response !== false) ? $response : false;
    }
}
