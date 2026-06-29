<?php

namespace App\Libraries;

use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiProvider\AiCache;

/**
 * 시맨틱 검색 — LLM 쿼리 확장(오타 교정·동의어·관련어).
 *
 * 임베딩/벡터 인프라 없이, 기존 AI 제공자로 검색어를 의미 기반 확장한 뒤
 * 기존 상품 검색(LIKE)에 OR 조건으로 더해 재현율(recall)을 높인다.
 * 결과는 검색어별로 캐시하고, AI 미설정·실패 시 빈 배열을 반환해 일반 검색으로 폴백한다.
 */
class SemanticSearchService
{
    /** 확장을 시도할 검색어 최대 길이 */
    private const MAX_QUERY_LEN = 50;

    /** 확장 결과 캐시 TTL(초) — 1일 */
    private const CACHE_TTL = 86400;

    /**
     * 검색어를 확장한 키워드 목록을 반환한다 (원본 제외, 실패 시 빈 배열).
     *
     * @return string[]
     */
    public function expand(string $query): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LEN) {
            return [];
        }

        $key = AiCache::key('search_expand', mb_strtolower($query));

        $terms = AiCache::remember($key, function () use ($query) {
            try {
                return AiCategoryAdvisor::create()->expandSearchQuery($query);
            } catch (AiKeyMissingException $e) {
                // AI 미설정은 정상 폴백 경로 — 로그를 남기지 않는다 (검색마다 누적 방지)
                return [];
            } catch (\Throwable $e) {
                log_message('error', 'SemanticSearch: ' . $e->getMessage());
                return [];
            }
        }, self::CACHE_TTL);

        // 원본 검색어와 동일한 항목은 제거 (중복 LIKE 방지)
        $lower = mb_strtolower($query);
        return array_values(array_filter(
            $terms,
            fn ($t) => mb_strtolower(trim((string) $t)) !== $lower
        ));
    }
}
