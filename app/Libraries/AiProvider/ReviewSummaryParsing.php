<?php

namespace App\Libraries\AiProvider;

/**
 * 리뷰 요약 입력 생성 + 응답 파싱 공유 로직 (Claude·Groq Provider 공용).
 */
trait ReviewSummaryParsing
{
    /** summarizeReviews 실패/빈 입력 시 반환할 기본 구조. */
    protected function emptySummary(): array
    {
        return ['summary' => '', 'pros' => [], 'cons' => [], 'sentiment' => 'mixed', 'negative_review_ids' => []];
    }

    /**
     * 리뷰 배열을 AI에 전달할 사용자 메시지로 변환한다.
     *
     * @param array $reviews [['id' => int, 'content' => string], ...]
     */
    protected function buildReviewMessage(string $productName, array $reviews): string
    {
        $lines = ["상품명: {$productName}", '', '리뷰 목록:'];
        foreach ($reviews as $r) {
            $id      = (int) ($r['id'] ?? 0);
            $content = trim(mb_substr(strip_tags((string) ($r['content'] ?? '')), 0, 300));
            if ($content === '') {
                continue;
            }
            $lines[] = "- [id:{$id}] {$content}";
        }
        return implode("\n", $lines);
    }

    /**
     * AI 응답 텍스트에서 JSON을 추출해 요약 구조로 정규화한다.
     *
     * @return array{summary:string, pros:string[], cons:string[], sentiment:string, negative_review_ids:int[]}
     */
    protected function parseSummary(string $text): array
    {
        $empty = $this->emptySummary();
        if ($text === '') {
            return $empty;
        }

        // 본문에 섞인 JSON 블록만 추출
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $parsed = json_decode($text, true);
        if (! is_array($parsed)) {
            return $empty;
        }

        $summary = trim((string) ($parsed['summary'] ?? ''));
        if ($summary === '') {
            return $empty;
        }

        $toStringList = static fn ($v): array => array_values(array_filter(
            array_map(static fn ($i) => trim((string) $i), is_array($v) ? $v : []),
            static fn ($i) => $i !== ''
        ));

        $sentiment = (string) ($parsed['sentiment'] ?? 'mixed');
        if (! in_array($sentiment, ['positive', 'mixed', 'negative'], true)) {
            $sentiment = 'mixed';
        }

        $negativeIds = array_values(array_filter(array_map(
            'intval',
            (array) ($parsed['negative_review_ids'] ?? [])
        )));

        return [
            'summary'             => $summary,
            'pros'                => array_slice($toStringList($parsed['pros'] ?? []), 0, 4),
            'cons'                => array_slice($toStringList($parsed['cons'] ?? []), 0, 4),
            'sentiment'           => $sentiment,
            'negative_review_ids' => $negativeIds,
        ];
    }
}
