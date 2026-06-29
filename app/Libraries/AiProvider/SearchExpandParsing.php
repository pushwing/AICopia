<?php

namespace App\Libraries\AiProvider;

/**
 * 검색어 확장 응답 파싱 공유 로직 (Claude·Groq Provider 공용).
 */
trait SearchExpandParsing
{
    /** 확장 결과 최대 개수 (프롬프트의 "최대 6개"와 일치 — LIKE 조건 과다 생성 방지) */
    private int $maxTerms = 6;

    /**
     * AI 응답 텍스트에서 검색어 배열을 추출·정규화한다.
     *
     * @return string[]
     */
    protected function parseTerms(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $parsed = json_decode($text, true);
        if (! is_array($parsed) || ! isset($parsed['terms']) || ! is_array($parsed['terms'])) {
            return [];
        }

        $terms = [];
        foreach ($parsed['terms'] as $t) {
            $t = trim(strip_tags((string) $t));
            // 너무 길거나 빈 값 제외
            $key = mb_strtolower($t);
            if ($t !== '' && mb_strlen($t) <= 30 && ! isset($terms[$key])) {
                $terms[$key] = $t; // 소문자 키로 중복 제거 (첫 항목 유지)
            }
        }

        return array_slice(array_values($terms), 0, $this->maxTerms);
    }
}
