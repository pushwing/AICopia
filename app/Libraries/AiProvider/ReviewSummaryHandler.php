<?php

namespace App\Libraries\AiProvider;

use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Models\ProductModel;
use App\Models\ProductReviewModel;

/**
 * ai_jobs 'review_summary' 핸들러.
 *
 * 상품 리뷰를 AI로 요약해 캐시에 적재하고, 부정 리뷰를 표시한다.
 * 프론트(상품 상세)는 이 캐시만 읽으므로 페이지 로딩이 블로킹되지 않는다.
 */
class ReviewSummaryHandler
{
    /** 요약을 생성할 최소 리뷰 수 (이 미만이면 요약 생략) */
    public const MIN_REVIEWS = 3;

    /** 분석 대상 최대 리뷰 수 */
    public const MAX_REVIEWS = 50;

    private ProductReviewModel $reviewModel;
    private ProductModel $productModel;

    public function __construct(
        private ?AiProviderInterface $provider = null,
        ?ProductReviewModel $reviewModel = null,
        ?ProductModel $productModel = null
    ) {
        $this->reviewModel  = $reviewModel ?? new ProductReviewModel();
        $this->productModel = $productModel ?? new ProductModel();
    }

    /** 상품별 리뷰 요약 캐시 키 (컨트롤러의 읽기·무효화와 공유). */
    public static function cacheKey(int $productId): string
    {
        return AiCache::key('review_summary', (string) $productId);
    }

    /** 리뷰 변경 시 요약 재생성 작업을 큐에 적재한다 (리뷰 작성/삭제/숨김 후 호출). */
    public static function enqueue(int $productId): void
    {
        if ($productId > 0) {
            model('AiJobModel')->enqueue('review_summary', ['product_id' => $productId]);
        }
    }

    /**
     * @param  array<string,mixed> $payload ['product_id' => int]
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['skipped' => true, 'reason' => 'invalid_product'];
        }

        $product = $this->productModel->find($productId);
        if (! $product) {
            AiCache::forget(self::cacheKey($productId));
            return ['skipped' => true, 'reason' => 'product_not_found'];
        }

        $reviews = $this->reviewModel->getForSummary($productId, self::MAX_REVIEWS);
        if (count($reviews) < self::MIN_REVIEWS) {
            // 요약 대상 미달 → 기존 캐시 제거
            AiCache::forget(self::cacheKey($productId));
            return ['skipped' => true, 'reason' => 'too_few_reviews', 'count' => count($reviews)];
        }

        try {
            $provider = $this->provider ?? AiCategoryAdvisor::create();
        } catch (AiKeyMissingException) {
            return ['skipped' => true, 'reason' => 'no_api_key'];
        }

        $summary = $provider->summarizeReviews((string) $product['name'], $reviews);
        if ($summary['summary'] === '') {
            // 실패 시 기존 캐시는 보존 (덮어쓰지 않음)
            return ['skipped' => true, 'reason' => 'empty_summary'];
        }

        AiCache::put(self::cacheKey($productId), $summary);
        $this->reviewModel->markNegative($productId, $summary['negative_review_ids']);

        return [
            'ok'             => true,
            'sentiment'      => $summary['sentiment'],
            'negative_count' => count($summary['negative_review_ids']),
        ];
    }
}
