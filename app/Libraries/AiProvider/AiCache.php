<?php

namespace App\Libraries\AiProvider;

/**
 * AI 호출 결과 캐시 헬퍼.
 *
 * 동일 입력 반복 호출의 API 비용·지연을 제거한다. CI4 File Cache를 사용하며,
 * 무효화는 호출처(모델 콜백 등)에서 키를 알고 직접 forget() 한다.
 *
 * 사용 예 — 결정적 입력(카테고리 추천):
 *   AiCache::remember(AiCache::key('category', $name, $desc), fn () => $provider->...());
 *
 * 사용 예 — 엔티티 단위(리뷰 요약): 무효화를 위해 안정 키 사용
 *   $key = AiCache::key('review_summary', (string) $productId);
 *   AiCache::remember($key, fn () => $provider->summarizeReviews(...));
 *   // 리뷰 변경 시: AiCache::forget(AiCache::key('review_summary', (string) $productId));
 */
class AiCache
{
    /** 캐시 키 접두사 */
    public const PREFIX = 'ai_';

    /** 기본 TTL (초) — 24시간 */
    public const DEFAULT_TTL = 86400;

    /**
     * 캐시에 있으면 반환, 없으면 콜백 실행 후 저장한다.
     * 콜백이 '빈 결과'(빈 배열·빈 문자열·null)를 반환하면 캐시하지 않는다
     * — 일시적 API 실패가 장시간 캐시되는 것을 방지.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL)
    {
        $cache  = service('cache');
        $cached = $cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null && $value !== [] && $value !== '') {
            $cache->save($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * feature명과 입력 조각으로 결정적 캐시 키를 생성한다.
     * 엔티티 단위 무효화가 필요하면 $parts에 엔티티 ID만 넘겨 안정 키를 만든다.
     */
    public static function key(string $feature, string ...$parts): string
    {
        return self::PREFIX . $feature . '_' . substr(md5(implode('|', $parts)), 0, 16);
    }

    /** 결과를 직접 저장한다 (워커가 백그라운드에서 생성한 결과 적재용). */
    public static function put(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): void
    {
        service('cache')->save($key, $value, $ttl);
    }

    /** 캐시된 값을 반환한다 (없으면 null). */
    public static function get(string $key): mixed
    {
        return service('cache')->get($key);
    }

    /** 특정 캐시 키 무효화 */
    public static function forget(string $key): void
    {
        service('cache')->delete($key);
    }
}
