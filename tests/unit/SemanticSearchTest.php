<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 시맨틱 검색 검증 (#11) — LLM 쿼리 확장 + getList OR 매칭
 */
class MockGroqSearchProvider extends GroqProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

class MockClaudeSearchProvider extends ClaudeProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

final class SemanticSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanupProducts = [];

    protected function tearDown(): void
    {
        if ($this->cleanupProducts !== []) {
            db_connect()->table('products')->whereIn('id', $this->cleanupProducts)->delete();
        }
        $this->cleanupProducts = [];
        parent::tearDown();
    }

    // ── Provider: expandSearchQuery ─────────────────────────────────────────

    public function testGroqExpandReturnsTerms(): void
    {
        $raw   = json_encode(['choices' => [['message' => ['content' => '{"terms":["운동화","러닝화","스니커즈"]}']]]]);
        $terms = (new MockGroqSearchProvider($raw))->expandSearchQuery('운동화');
        $this->assertSame(['운동화', '러닝화', '스니커즈'], $terms);
    }

    public function testClaudeExpandReturnsTerms(): void
    {
        $raw   = json_encode(['content' => [['text' => '확장: {"terms":["청바지","데님"]}']]]);
        $terms = (new MockClaudeSearchProvider($raw))->expandSearchQuery('청바지');
        $this->assertSame(['청바지', '데님'], $terms);
    }

    public function testReturnsEmptyOnApiFailure(): void
    {
        $this->assertSame([], (new MockGroqSearchProvider('', false))->expandSearchQuery('x'));
    }

    public function testReturnsEmptyOnMalformed(): void
    {
        $raw = json_encode(['choices' => [['message' => ['content' => 'no json']]]]);
        $this->assertSame([], (new MockGroqSearchProvider($raw))->expandSearchQuery('x'));
    }

    public function testParseDedupesAndLimits(): void
    {
        $many = json_encode(['terms' => ['A', 'a', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']]);
        $raw  = json_encode(['choices' => [['message' => ['content' => $many]]]]);
        $terms = (new MockGroqSearchProvider($raw))->expandSearchQuery('q');
        $this->assertLessThanOrEqual(8, count($terms));
        $this->assertContains('A', $terms);
        $this->assertNotContains('a', $terms, '대소문자 중복은 제거돼야 한다');
    }

    public function testPayloadIncludesQuery(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => '{"terms":[]}']]]]);
        $provider = new MockGroqSearchProvider($raw);
        $provider->expandSearchQuery('겨울 패딩');

        $payload = json_decode($provider->lastPayload, true);
        $this->assertStringContainsString('겨울 패딩', $payload['messages'][1]['content']);
    }

    // ── getList: 확장어 OR 매칭 (DB) ─────────────────────────────────────────

    private function insertProduct(string $name, string $description): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name' => $name, 'slug' => 'ss-' . $uid,
            'price' => 10000, 'cost_price' => 5000, 'stock' => 5, 'status' => 'on_sale',
            'is_featured' => 0, 'shipping_type' => 'free', 'shipping_fee' => 0,
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanupProducts[] = $id;
        return $id;
    }

    public function testGetListMatchesExpandedTermInDescription(): void
    {
        $rand       = random_int(1000, 9999);
        $searchTok  = 'qry' . $rand;   // 이름·설명 어디에도 없는 원본 검색어
        $descTok    = 'desc' . $rand;  // 설명에만 있는 확장어
        $pid = $this->insertProduct('NoMatchName' . $rand, '편안한 ' . $descTok . ' 소재');

        $model = new ProductModel();

        // 확장어 없이 원본 검색어로는 매칭 안 됨 (이름·설명에 없음)
        $base = $model->getList(['keyword' => $searchTok, 'per_page' => 50]);
        $this->assertNotContains($pid, array_map('intval', array_column($base['items'], 'id')));

        // 확장어(설명에 존재)를 넘기면 매칭됨
        $expanded = $model->getList(['keyword' => $searchTok, 'expanded_terms' => [$descTok], 'per_page' => 50]);
        $this->assertContains($pid, array_map('intval', array_column($expanded['items'], 'id')));
    }
}
