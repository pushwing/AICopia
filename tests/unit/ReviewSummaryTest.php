<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\AiCache;
use App\Libraries\AiProvider\AiProviderInterface;
use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use App\Libraries\AiProvider\ReviewSummaryHandler;
use App\Libraries\AiProvider\ReviewSummaryParsing;
use App\Models\ProductModel;
use App\Models\ProductReviewModel;
use CodeIgniter\Test\CIUnitTestCase;

// ── 트레이트 파싱 로직 노출용 호스트 ─────────────────────────────────────────
class SummaryTraitHost
{
    use ReviewSummaryParsing;

    public function parse(string $t): array
    {
        return $this->parseSummary($t);
    }

    public function build(string $name, array $reviews): string
    {
        return $this->buildReviewMessage($name, $reviews);
    }

    public function emptyOne(): array
    {
        return $this->emptySummary();
    }
}

// ── callApi를 모킹한 Provider ───────────────────────────────────────────────
class MockGroqSummaryProvider extends GroqProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

class MockClaudeSummaryProvider extends ClaudeProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

// ── 핸들러용 가짜 의존성 (DB 불필요) ─────────────────────────────────────────
class StubSummaryProvider implements AiProviderInterface
{
    public function __construct(private array $canned) {}

    public function suggestCategories(string $name, string $description, array $tree): array { return []; }
    public function generateDescription(string $name, string $description): string { return ''; }
    public function generateQnaAnswer(string $p, string $d, string $t, string $c): string { return ''; }
    public function summarizeReviews(string $productName, array $reviews): array { return $this->canned; }
    public function classifyInquiry(string $subject, string $message): array { return []; }
    public function generateInquiryReply(string $name, string $subject, string $message): string { return ''; }
    public function generateSalesReport(array $stats): string { return ''; }
    public function generateRestockMessage(string $productName, string $productDescription): string { return ''; }
    public function expandSearchQuery(string $query): array { return []; }
}

class FakeProductModel extends ProductModel
{
    public function __construct(private ?array $product) {}
    public function find($id = null) { return $this->product; }
}

class FakeReviewModel extends ProductReviewModel
{
    public array $markedNegative = [];
    public function __construct(private array $reviews) {}
    public function getForSummary(int $productId, int $limit = 50): array { return $this->reviews; }
    public function markNegative(int $productId, array $negativeIds): void
    {
        $this->markedNegative = $negativeIds;
    }
}

final class ReviewSummaryTest extends CIUnitTestCase
{
    private function validJson(): string
    {
        return json_encode([
            'summary'             => '전반적으로 만족도가 높습니다.',
            'pros'                => ['빠른 배송', '좋은 품질', '가성비', '포장 꼼꼼', '초과항목'],
            'cons'                => ['사이즈가 작음'],
            'sentiment'           => 'positive',
            'negative_review_ids' => [3, '5'],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── parseSummary ──────────────────────────────────────────────────────────

    public function testParseSummaryExtractsAllFields(): void
    {
        $r = (new SummaryTraitHost())->parse($this->validJson());

        $this->assertSame('전반적으로 만족도가 높습니다.', $r['summary']);
        $this->assertSame('positive', $r['sentiment']);
        $this->assertSame([3, 5], $r['negative_review_ids']);
    }

    public function testParseSummaryTruncatesProsToFour(): void
    {
        $r = (new SummaryTraitHost())->parse($this->validJson());
        $this->assertCount(4, $r['pros'], 'pros는 최대 4개');
        $this->assertCount(1, $r['cons']);
    }

    public function testParseSummaryExtractsEmbeddedJson(): void
    {
        $raw = '분석 결과입니다: ' . $this->validJson() . ' 이상입니다.';
        $r   = (new SummaryTraitHost())->parse($raw);
        $this->assertSame('positive', $r['sentiment']);
    }

    public function testParseSummaryNormalizesUnknownSentiment(): void
    {
        $raw = json_encode(['summary' => '요약', 'sentiment' => 'weird']);
        $r   = (new SummaryTraitHost())->parse($raw);
        $this->assertSame('mixed', $r['sentiment']);
    }

    public function testParseSummaryReturnsEmptyOnBlankSummary(): void
    {
        $raw = json_encode(['summary' => '', 'pros' => ['x']]);
        $r   = (new SummaryTraitHost())->parse($raw);
        $this->assertSame('', $r['summary']);
        $this->assertSame([], $r['pros']);
    }

    public function testParseSummaryReturnsEmptyOnMalformed(): void
    {
        $r = (new SummaryTraitHost())->parse('not json at all');
        $this->assertSame('', $r['summary']);
    }

    public function testBuildReviewMessageIncludesIdsAndStripsHtml(): void
    {
        $msg = (new SummaryTraitHost())->build('티셔츠', [
            ['id' => 7, 'content' => '<p>아주 <b>좋아요</b></p>'],
            ['id' => 9, 'content' => '  '],
        ]);
        $this->assertStringContainsString('상품명: 티셔츠', $msg);
        $this->assertStringContainsString('[id:7]', $msg);
        $this->assertStringContainsString('아주 좋아요', $msg);
        $this->assertStringNotContainsString('<p>', $msg);
        $this->assertStringNotContainsString('[id:9]', $msg, '빈 리뷰는 제외');
    }

    // ── summarizeReviews (Provider) ─────────────────────────────────────────────

    public function testGroqSummarizeReturnsStructure(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => $this->validJson()]]]]);
        $provider = new MockGroqSummaryProvider($raw);
        $r        = $provider->summarizeReviews('상품', [['id' => 1, 'content' => '좋음']]);
        $this->assertSame('positive', $r['sentiment']);
        $this->assertSame([3, 5], $r['negative_review_ids']);
    }

    public function testClaudeSummarizeReturnsStructure(): void
    {
        $raw      = json_encode(['content' => [['text' => $this->validJson()]]]);
        $provider = new MockClaudeSummaryProvider($raw);
        $r        = $provider->summarizeReviews('상품', [['id' => 1, 'content' => '좋음']]);
        $this->assertSame('전반적으로 만족도가 높습니다.', $r['summary']);
    }

    public function testSummarizeReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockGroqSummaryProvider('', false);
        $r        = $provider->summarizeReviews('상품', [['id' => 1, 'content' => '좋음']]);
        $this->assertSame('', $r['summary']);
    }

    public function testSummarizeReturnsEmptyOnNoReviews(): void
    {
        $provider = new MockGroqSummaryProvider('whatever');
        $this->assertSame('', $provider->summarizeReviews('상품', [])['summary']);
    }

    // ── ReviewSummaryHandler (가짜 의존성) ──────────────────────────────────────

    private function reviews(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['id' => $i, 'content' => "리뷰 {$i}"];
        }
        return $out;
    }

    public function testHandlerSkipsWhenTooFewReviews(): void
    {
        $handler = new ReviewSummaryHandler(
            new StubSummaryProvider([]),
            new FakeReviewModel($this->reviews(2)),
            new FakeProductModel(['id' => 1, 'name' => '상품'])
        );
        $r = $handler->handle(['product_id' => 1]);
        $this->assertTrue($r['skipped']);
        $this->assertSame('too_few_reviews', $r['reason']);
    }

    public function testHandlerSkipsWhenProductMissing(): void
    {
        $handler = new ReviewSummaryHandler(
            new StubSummaryProvider([]),
            new FakeReviewModel($this->reviews(5)),
            new FakeProductModel(null)
        );
        $r = $handler->handle(['product_id' => 99]);
        $this->assertSame('product_not_found', $r['reason']);
    }

    public function testHandlerCachesSummaryAndMarksNegative(): void
    {
        $canned = [
            'summary'             => '요약입니다.',
            'pros'                => ['장점'],
            'cons'                => [],
            'sentiment'           => 'mixed',
            'negative_review_ids' => [2, 4],
        ];
        $reviewModel = new FakeReviewModel($this->reviews(5));
        $handler     = new ReviewSummaryHandler(
            new StubSummaryProvider($canned),
            $reviewModel,
            new FakeProductModel(['id' => 1, 'name' => '상품'])
        );

        $key = ReviewSummaryHandler::cacheKey(1);
        AiCache::forget($key);

        $r = $handler->handle(['product_id' => 1]);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['negative_count']);
        $this->assertSame([2, 4], $reviewModel->markedNegative, '부정 리뷰 id가 모델에 전달돼야 한다');
        $this->assertSame($canned, AiCache::get($key), '요약이 캐시에 저장돼야 한다');

        AiCache::forget($key);
    }

    public function testDefaultRunnerRegistersReviewSummary(): void
    {
        $runner = new \App\Libraries\AiProvider\AiJobRunner();
        $this->assertTrue($runner->supports('review_summary'), '기본 러너가 review_summary 핸들러를 매핑해야 한다');
    }

    public function testHandlerDoesNotOverwriteCacheOnEmptySummary(): void
    {
        $handler = new ReviewSummaryHandler(
            new StubSummaryProvider(['summary' => '', 'pros' => [], 'cons' => [], 'sentiment' => 'mixed', 'negative_review_ids' => []]),
            new FakeReviewModel($this->reviews(5)),
            new FakeProductModel(['id' => 1, 'name' => '상품'])
        );
        $r = $handler->handle(['product_id' => 1]);
        $this->assertSame('empty_summary', $r['reason']);
    }
}
