<?php

namespace App\Libraries\AiProvider;

/**
 * 문의 분류 응답 파싱·정규화 공유 로직 (Claude·Groq Provider 공용).
 */
trait InquiryParsing
{
    /** 분류 실패 시 기본값 (미분류로 두지 않고 안전한 기본값). */
    protected function emptyClassification(): array
    {
        return ['category' => 'etc', 'priority' => 'normal', 'sentiment' => 'neutral'];
    }

    /** 문의 분류용 사용자 메시지를 구성한다. */
    protected function buildInquiryMessage(string $subject, string $message): string
    {
        $subject = trim(mb_substr(strip_tags($subject), 0, 200));
        $message = trim(mb_substr(strip_tags($message), 0, 1000));

        return "제목: {$subject}\n내용: {$message}";
    }

    /**
     * AI 응답 텍스트에서 분류 JSON을 추출해 enum으로 정규화한다.
     *
     * @return array{category:string, priority:string, sentiment:string}
     */
    protected function parseClassification(string $text): array
    {
        if ($text === '') {
            return $this->emptyClassification();
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $parsed = json_decode($text, true);
        if (! is_array($parsed)) {
            return $this->emptyClassification();
        }

        return [
            'category'  => $this->normalize($parsed['category'] ?? '', InquiryTaxonomy::CATEGORIES, 'etc'),
            'priority'  => $this->normalize($parsed['priority'] ?? '', InquiryTaxonomy::PRIORITIES, 'normal'),
            'sentiment' => $this->normalize($parsed['sentiment'] ?? '', InquiryTaxonomy::SENTIMENTS, 'neutral'),
        ];
    }

    /** 값이 허용 목록에 없으면 기본값으로 정규화한다. */
    private function normalize(mixed $value, array $allowed, string $default): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
