<?php

namespace Tests\Unit;

use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use App\Libraries\AiProvider\OpenRouterProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AI 카테고리 추천 기능 검증
 *
 * - AiCategoryAdvisor Factory: env 값에 따른 Provider 선택
 * - GroqProvider: flattenTree, parseResponse 순수 로직
 * - ClaudeProvider: flattenTree, parseResponse 순수 로직
 * - 실제 Groq API 통합 테스트 (GROQ_API_KEY 설정 시)
 */

// ── 테스트용 서브클래스 — protected 메서드 노출 ──────────────────────────────
class TestableGroqProvider extends GroqProvider
{
    public function exposeFlattenTree(array $tree): string
    {
        return $this->flattenTree($tree);
    }

    public function exposeParseResponse(string $raw): array
    {
        return $this->parseResponse($raw);
    }

    public function exposeBuildPrompt(string $name, string $desc, array $tree): string
    {
        return $this->buildPrompt($name, $desc, $tree);
    }
}

class TestableClaudeProvider extends ClaudeProvider
{
    public function exposeFlattenTree(array $tree): string
    {
        return $this->flattenTree($tree);
    }

    public function exposeParseResponse(string $raw): array
    {
        return $this->parseResponse($raw);
    }
}

class MockGroqProvider extends GroqProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }

    public function exposeDescriptionSystemPrompt(): string
    {
        return \App\Libraries\AiProvider\AiPrompts::defaults()['description'];
    }
}

class MockClaudeProvider extends ClaudeProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

class MockOpenRouterProvider extends OpenRouterProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true)
    {
        parent::__construct();
    }

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $data          = json_decode($payload, true);
        $data['model'] = $this->model;
        $this->lastPayload = (string) json_encode($data);
        return $this->success ? $this->mockRaw : false;
    }
}

// ── 테스트 클래스 ─────────────────────────────────────────────────────────────
final class AiCategoryAdvisorTest extends CIUnitTestCase
{
    private static bool $tableCreated = false;

    private string $originalProvider       = '';
    private string $originalGroqKey        = '';
    private string $originalClaudeKey      = '';
    private string $originalOpenRouterKey  = '';

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory 테스트 DB에 settings 테이블 생성 (한 번만)
        if (! self::$tableCreated) {
            $db    = \Config\Database::connect();
            $table = $db->getPrefix() . 'settings';
            $db->query("CREATE TABLE IF NOT EXISTS {$table} (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                \"group\" VARCHAR(30)  NOT NULL DEFAULT 'general',
                key      VARCHAR(100) NOT NULL,
                value    TEXT,
                label    VARCHAR(255) DEFAULT NULL,
                type     VARCHAR(50)  DEFAULT 'text',
                updated_at DATETIME   DEFAULT NULL
            )");
            self::$tableCreated = true;
        }

        $this->originalProvider      = $_ENV['AI_PROVIDER']        ?? getenv('AI_PROVIDER')        ?: '';
        $this->originalGroqKey       = $_ENV['GROQ_API_KEY']       ?? getenv('GROQ_API_KEY')       ?: '';
        $this->originalClaudeKey     = $_ENV['ANTHROPIC_API_KEY']  ?? getenv('ANTHROPIC_API_KEY')  ?: '';
        $this->originalOpenRouterKey = $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
        // factory 테스트가 AiKeyMissingException 없이 동작하도록 dummy key 설정
        $this->setApiKey('GROQ_API_KEY',       'test_groq_dummy');
        $this->setApiKey('ANTHROPIC_API_KEY',  'test_claude_dummy');
        $this->setApiKey('OPENROUTER_API_KEY', 'test_openrouter_dummy');
    }

    protected function tearDown(): void
    {
        $this->setProvider($this->originalProvider);
        $this->setApiKey('GROQ_API_KEY',       $this->originalGroqKey);
        $this->setApiKey('ANTHROPIC_API_KEY',  $this->originalClaudeKey);
        $this->setApiKey('OPENROUTER_API_KEY', $this->originalOpenRouterKey);
        parent::tearDown();
    }

    private function setProvider(string $value): void
    {
        putenv("AI_PROVIDER={$value}");
        $_ENV['AI_PROVIDER'] = $value;
    }

    private function setApiKey(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }

    private function sampleTree(): array
    {
        return [
            ['id' => 1, 'name' => '상의', 'children' => [
                ['id' => 11, 'name' => '티셔츠'],
                ['id' => 12, 'name' => '셔츠'],
            ]],
            ['id' => 2, 'name' => '하의', 'children' => [
                ['id' => 21, 'name' => '청바지'],
            ]],
        ];
    }

    // ── AiKeyMissingException ─────────────────────────────────────────────────

    public function testExceptionMessageContainsProviderName(): void
    {
        $e = new AiKeyMissingException('Groq');
        $this->assertStringContainsString('Groq', $e->getMessage());
        $this->assertStringContainsString('API 키', $e->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new AiKeyMissingException('Claude'));
    }

    public function testFactoryThrowsWhenGroqKeyEmpty(): void
    {
        $dbSettings = model('SettingModel')->getAllAsMap();
        if (! empty($dbSettings['groq_api_key'])) {
            $this->markTestSkipped('settings DB에 groq_api_key가 저장돼 있어 건너뜀 — 키 없는 환경에서만 유효');
        }
        $this->setProvider('groq');
        $this->setApiKey('GROQ_API_KEY', '');
        $this->expectException(AiKeyMissingException::class);
        $this->expectExceptionMessageMatches('/Groq/');
        AiCategoryAdvisor::create();
    }

    public function testFactoryThrowsWhenClaudeKeyEmpty(): void
    {
        $dbSettings = model('SettingModel')->getAllAsMap();
        if (! empty($dbSettings['anthropic_api_key'])) {
            $this->markTestSkipped('settings DB에 anthropic_api_key가 저장돼 있어 건너뜀 — 키 없는 환경에서만 유효');
        }
        $this->setProvider('claude');
        $this->setApiKey('ANTHROPIC_API_KEY', '');
        $this->expectException(AiKeyMissingException::class);
        $this->expectExceptionMessageMatches('/Claude/');
        AiCategoryAdvisor::create();
    }

    public function testFactoryThrowsGroqExceptionWhenUnknownProviderAndKeyEmpty(): void
    {
        $dbSettings = model('SettingModel')->getAllAsMap();
        if (! empty($dbSettings['groq_api_key'])) {
            $this->markTestSkipped('settings DB에 groq_api_key가 저장돼 있어 건너뜀 — 키 없는 환경에서만 유효');
        }
        $this->setProvider('unknown');
        $this->setApiKey('GROQ_API_KEY', '');
        $this->expectException(AiKeyMissingException::class);
        $this->expectExceptionMessageMatches('/Groq/');
        AiCategoryAdvisor::create();
    }

    public function testFactoryDoesNotThrowWhenGroqKeyPresent(): void
    {
        $this->setProvider('groq');
        $this->setApiKey('GROQ_API_KEY', 'valid_dummy_key');
        $provider = AiCategoryAdvisor::create();
        $this->assertInstanceOf(GroqProvider::class, $provider);
    }

    public function testFactoryDoesNotThrowWhenClaudeKeyPresent(): void
    {
        $this->setProvider('claude');
        $this->setApiKey('ANTHROPIC_API_KEY', 'sk-ant-dummy');
        $provider = AiCategoryAdvisor::create();
        $this->assertInstanceOf(ClaudeProvider::class, $provider);
    }

    public function testFactoryThrowsWhenOpenRouterKeyEmpty(): void
    {
        $dbSettings = model('SettingModel')->getAllAsMap();
        if (! empty($dbSettings['openrouter_api_key'])) {
            $this->markTestSkipped('settings DB에 openrouter_api_key가 저장돼 있어 건너뜀 — 키 없는 환경에서만 유효');
        }
        $this->setProvider('openrouter');
        $this->setApiKey('OPENROUTER_API_KEY', '');
        $this->expectException(AiKeyMissingException::class);
        $this->expectExceptionMessageMatches('/OpenRouter/');
        AiCategoryAdvisor::create();
    }

    public function testFactoryDoesNotThrowWhenOpenRouterKeyPresent(): void
    {
        $this->setProvider('openrouter');
        $this->setApiKey('OPENROUTER_API_KEY', 'sk-or-dummy');
        $provider = AiCategoryAdvisor::create();
        $this->assertInstanceOf(OpenRouterProvider::class, $provider);
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public function testFactoryReturnsGroqProviderByDefault(): void
    {
        $this->setProvider('groq');
        $this->assertInstanceOf(GroqProvider::class, AiCategoryAdvisor::create());
    }

    public function testFactoryReturnsGroqProviderWhenEnvNotSet(): void
    {
        $this->setProvider('');
        $this->assertInstanceOf(GroqProvider::class, AiCategoryAdvisor::create());
    }

    public function testFactoryReturnsClaudeProviderWhenEnvIsClaude(): void
    {
        $this->setProvider('claude');
        $this->assertInstanceOf(ClaudeProvider::class, AiCategoryAdvisor::create());
    }

    public function testFactoryReturnsGroqForUnknownProvider(): void
    {
        $this->setProvider('unknown');
        $this->assertInstanceOf(GroqProvider::class, AiCategoryAdvisor::create());
    }

    public function testFactoryReturnsOpenRouterProviderWhenEnvIsOpenRouter(): void
    {
        $this->setProvider('openrouter');
        $this->assertInstanceOf(OpenRouterProvider::class, AiCategoryAdvisor::create());
    }

    // ── GroqProvider::flattenTree ─────────────────────────────────────────────

    public function testGroqFlattenTreeFormatsParentChildren(): void
    {
        $provider = new TestableGroqProvider();
        $result   = $provider->exposeFlattenTree($this->sampleTree());

        $this->assertStringContainsString('- 1: 상의', $result);
        $this->assertStringContainsString('  - 11: 티셔츠', $result);
        $this->assertStringContainsString('  - 12: 셔츠', $result);
        $this->assertStringContainsString('- 2: 하의', $result);
        $this->assertStringContainsString('  - 21: 청바지', $result);
    }

    public function testGroqFlattenTreeEmptyReturnsEmptyString(): void
    {
        $provider = new TestableGroqProvider();
        $this->assertSame('', $provider->exposeFlattenTree([]));
    }

    public function testGroqFlattenTreeNoChildrenSkipsChildLines(): void
    {
        $provider = new TestableGroqProvider();
        $tree     = [['id' => 5, 'name' => '잡화', 'children' => []]];
        $result   = $provider->exposeFlattenTree($tree);

        $this->assertStringContainsString('- 5: 잡화', $result);
        $this->assertStringNotContainsString('  -', $result);
    }

    // ── GroqProvider::parseResponse ───────────────────────────────────────────

    public function testGroqParseResponseExtractsIds(): void
    {
        $provider = new TestableGroqProvider();
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '{"category_ids":[1,11]}'],
            ]],
        ]);

        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([1, 11], $ids);
    }

    public function testGroqParseResponseReturnsEmptyOnMissingChoices(): void
    {
        $provider = new TestableGroqProvider();
        $ids = $provider->exposeParseResponse('{}');
        $this->assertSame([], $ids);
    }

    public function testGroqParseResponseReturnsEmptyOnMalformedContent(): void
    {
        $provider = new TestableGroqProvider();
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => 'not json'],
            ]],
        ]);
        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([], $ids);
    }

    public function testGroqParseResponseFiltersZeroIds(): void
    {
        $provider = new TestableGroqProvider();
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '{"category_ids":[0,5,0]}'],
            ]],
        ]);
        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([5], $ids);
    }

    public function testGroqParseResponseCastsToInt(): void
    {
        $provider = new TestableGroqProvider();
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '{"category_ids":["3","7"]}'],
            ]],
        ]);
        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([3, 7], $ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
        }
    }

    // ── GroqProvider::buildPrompt ─────────────────────────────────────────────

    public function testGroqBuildPromptContainsNameAndDesc(): void
    {
        $provider = new TestableGroqProvider();
        $prompt   = $provider->exposeBuildPrompt('남성 티셔츠', '면 100% 반팔 티셔츠', []);

        $this->assertStringContainsString('남성 티셔츠', $prompt);
        $this->assertStringContainsString('면 100% 반팔 티셔츠', $prompt);
    }

    public function testGroqBuildPromptStripsHtmlTags(): void
    {
        $provider = new TestableGroqProvider();
        $prompt   = $provider->exposeBuildPrompt('상품', '<p>설명 <b>굵게</b></p>', []);

        $this->assertStringNotContainsString('<p>', $prompt);
        $this->assertStringNotContainsString('<b>', $prompt);
        $this->assertStringContainsString('설명 굵게', $prompt);
    }

    public function testGroqBuildPromptTruncatesLongDescription(): void
    {
        $provider   = new TestableGroqProvider();
        $longDesc   = str_repeat('가', 1000);
        $prompt     = $provider->exposeBuildPrompt('상품', $longDesc, []);

        $this->assertLessThanOrEqual(600, mb_strlen($prompt));
    }

    // ── ClaudeProvider::flattenTree ───────────────────────────────────────────

    public function testClaudeFlattenTreeMatchesGroqFormat(): void
    {
        $groq   = new TestableGroqProvider();
        $claude = new TestableClaudeProvider();

        $this->assertSame(
            $groq->exposeFlattenTree($this->sampleTree()),
            $claude->exposeFlattenTree($this->sampleTree()),
            '두 Provider의 flattenTree 출력이 동일해야 한다'
        );
    }

    // ── ClaudeProvider::parseResponse ────────────────────────────────────────

    public function testClaudeParseResponseExtractsIds(): void
    {
        $provider = new TestableClaudeProvider();
        $raw = json_encode([
            'content' => [['text' => '{"category_ids":[2,21]}']],
        ]);
        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([2, 21], $ids);
    }

    public function testClaudeParseResponseExtractsJsonEmbeddedInText(): void
    {
        $provider = new TestableClaudeProvider();
        $raw = json_encode([
            'content' => [['text' => '추천 카테고리는 다음과 같습니다: {"category_ids":[3]} 참고하세요.']],
        ]);
        $ids = $provider->exposeParseResponse($raw);
        $this->assertSame([3], $ids);
    }

    public function testClaudeParseResponseReturnsEmptyOnMissingContent(): void
    {
        $provider = new TestableClaudeProvider();
        $ids = $provider->exposeParseResponse('{}');
        $this->assertSame([], $ids);
    }

    // ── GroqProvider::generateDescription ────────────────────────────────────

    public function testGroqGenerateDescriptionReturnsContent(): void
    {
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '<p>고품질 면 티셔츠입니다.</p>'],
            ]],
        ]);
        $provider = new MockGroqProvider($raw);
        $result   = $provider->generateDescription('면 티셔츠', '');
        $this->assertSame('<p>고품질 면 티셔츠입니다.</p>', $result);
    }

    public function testGroqGenerateDescriptionReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockGroqProvider('', false);
        $this->assertSame('', $provider->generateDescription('상품', '설명'));
    }

    public function testGroqGenerateDescriptionReturnsEmptyOnMissingContent(): void
    {
        $provider = new MockGroqProvider('{}');
        $this->assertSame('', $provider->generateDescription('상품', ''));
    }

    public function testGroqDescriptionSystemPromptContainsHtmlRules(): void
    {
        $provider = new MockGroqProvider('');
        $prompt   = $provider->exposeDescriptionSystemPrompt();
        $this->assertStringContainsString('<p>', $prompt);
        $this->assertStringContainsString('<strong>', $prompt);
        $this->assertStringContainsString('마크다운', $prompt);
    }

    public function testGroqGenerateDescriptionStripsHtmlFromBaseDescription(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => '<p>결과</p>']]]]);
        $provider = new MockGroqProvider($raw);
        $provider->generateDescription('상품명', '<p>기존 <b>설명</b></p>');

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][1]['content'];
        $this->assertStringNotContainsString('<p>', $content);
        $this->assertStringNotContainsString('<b>', $content);
        $this->assertStringContainsString('기존 설명', $content);
    }

    public function testGroqGenerateDescriptionTruncatesAt1000Chars(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockGroqProvider($raw);
        $longDesc = str_repeat('가', 2000);
        $provider->generateDescription('상품', $longDesc);

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][1]['content'];
        $this->assertLessThanOrEqual(1060, mb_strlen($content));
    }

    public function testClaudeGenerateDescriptionTruncatesAt1000Chars(): void
    {
        $raw      = json_encode(['content' => [['text' => 'ok']]]);
        $provider = new MockClaudeProvider($raw);
        $longDesc = str_repeat('나', 2000);
        $provider->generateDescription('상품', $longDesc);

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][0]['content'];
        $this->assertLessThanOrEqual(1060, mb_strlen($content));
    }

    // ── ClaudeProvider::generateDescription ──────────────────────────────────

    public function testClaudeGenerateDescriptionReturnsContent(): void
    {
        $raw = json_encode([
            'content' => [['text' => '<p>멋진 상품 설명입니다.</p>']],
        ]);
        $provider = new MockClaudeProvider($raw);
        $result   = $provider->generateDescription('상품', '기존 설명');
        $this->assertSame('<p>멋진 상품 설명입니다.</p>', $result);
    }

    public function testClaudeGenerateDescriptionReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockClaudeProvider('', false);
        $this->assertSame('', $provider->generateDescription('상품', ''));
    }

    public function testClaudeGenerateDescriptionReturnsEmptyOnMissingContent(): void
    {
        $provider = new MockClaudeProvider('{}');
        $this->assertSame('', $provider->generateDescription('상품', ''));
    }

    // ── GroqProvider::generateQnaAnswer ──────────────────────────────────────

    public function testGroqGenerateQnaAnswerReturnsContent(): void
    {
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '안녕하세요! M 사이즈를 추천드립니다. 감사합니다.'],
            ]],
        ]);
        $provider = new MockGroqProvider($raw);
        $result   = $provider->generateQnaAnswer('티셔츠', '면 100%', '사이즈 문의', 'M 사이즈 맞나요?');
        $this->assertSame('안녕하세요! M 사이즈를 추천드립니다. 감사합니다.', $result);
    }

    public function testGroqGenerateQnaAnswerReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockGroqProvider('', false);
        $this->assertSame('', $provider->generateQnaAnswer('상품', '설명', '제목', '내용'));
    }

    public function testGroqGenerateQnaAnswerReturnsEmptyOnMissingContent(): void
    {
        $provider = new MockGroqProvider('{}');
        $this->assertSame('', $provider->generateQnaAnswer('상품', '', '제목', '내용'));
    }

    public function testGroqQnaSystemPromptContainsRules(): void
    {
        $raw = json_encode(['choices' => [['message' => ['content' => '답변']]]]);
        $p2  = new MockGroqProvider($raw);
        $this->assertSame('답변', $p2->generateQnaAnswer('상품', '', '제목', '내용'));
    }

    public function testGroqGenerateQnaAnswerTruncatesProductDescAt500Chars(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockGroqProvider($raw);
        $longDesc = str_repeat('다', 1000);
        $provider->generateQnaAnswer('상품', $longDesc, '제목', '내용');

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][1]['content'];
        $this->assertLessThanOrEqual(560, mb_strlen($content));
    }

    public function testGroqGenerateQnaAnswerIncludesAllFields(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockGroqProvider($raw);
        $provider->generateQnaAnswer('블랙 청바지', '데님 소재', '색 빠짐', '세탁하면 색이 빠지나요?');

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][1]['content'];
        $this->assertStringContainsString('블랙 청바지', $content);
        $this->assertStringContainsString('데님 소재', $content);
        $this->assertStringContainsString('색 빠짐', $content);
        $this->assertStringContainsString('세탁하면 색이 빠지나요?', $content);
    }

    public function testClaudeGenerateQnaAnswerTruncatesProductDescAt500Chars(): void
    {
        $raw      = json_encode(['content' => [['text' => 'ok']]]);
        $provider = new MockClaudeProvider($raw);
        $longDesc = str_repeat('라', 1000);
        $provider->generateQnaAnswer('상품', $longDesc, '제목', '내용');

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][0]['content'];
        $this->assertLessThanOrEqual(560, mb_strlen($content));
    }

    // ── ClaudeProvider::generateQnaAnswer ────────────────────────────────────

    public function testClaudeGenerateQnaAnswerReturnsContent(): void
    {
        $raw = json_encode([
            'content' => [['text' => '안녕하세요! 문의 주셔서 감사합니다.']],
        ]);
        $provider = new MockClaudeProvider($raw);
        $result   = $provider->generateQnaAnswer('청바지', '데님 소재', '색 빠짐', '세탁하면 색이 빠지나요?');
        $this->assertSame('안녕하세요! 문의 주셔서 감사합니다.', $result);
    }

    public function testClaudeGenerateQnaAnswerReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockClaudeProvider('', false);
        $this->assertSame('', $provider->generateQnaAnswer('상품', '', '제목', '내용'));
    }

    public function testClaudeGenerateQnaAnswerReturnsEmptyOnMissingContent(): void
    {
        $provider = new MockClaudeProvider('{}');
        $this->assertSame('', $provider->generateQnaAnswer('상품', '', '제목', '내용'));
    }

    // ── OpenRouterProvider ───────────────────────────────────────────────────

    public function testOpenRouterGenerateDescriptionReturnsContent(): void
    {
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '<p>OpenRouter 생성 설명입니다.</p>'],
            ]],
        ]);
        $provider = new MockOpenRouterProvider($raw);
        $result   = $provider->generateDescription('면 티셔츠', '');
        $this->assertSame('<p>OpenRouter 생성 설명입니다.</p>', $result);
    }

    public function testOpenRouterGenerateDescriptionReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockOpenRouterProvider('', false);
        $this->assertSame('', $provider->generateDescription('상품', '설명'));
    }

    public function testOpenRouterGenerateDescriptionReturnsEmptyOnMissingContent(): void
    {
        $provider = new MockOpenRouterProvider('{}');
        $this->assertSame('', $provider->generateDescription('상품', ''));
    }

    public function testOpenRouterGenerateQnaAnswerReturnsContent(): void
    {
        $raw = json_encode([
            'choices' => [[
                'message' => ['content' => '안녕하세요! 문의 주셔서 감사합니다.'],
            ]],
        ]);
        $provider = new MockOpenRouterProvider($raw);
        $result   = $provider->generateQnaAnswer('청바지', '데님 소재', '세탁', '색 빠짐 있나요?');
        $this->assertSame('안녕하세요! 문의 주셔서 감사합니다.', $result);
    }

    public function testOpenRouterGenerateQnaAnswerReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockOpenRouterProvider('', false);
        $this->assertSame('', $provider->generateQnaAnswer('상품', '', '제목', '내용'));
    }

    public function testOpenRouterCallApiInjectsModelIntoPayload(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockOpenRouterProvider($raw);
        $provider->generateDescription('상품', '설명');

        $payload = json_decode($provider->lastPayload, true);
        $this->assertArrayHasKey('model', $payload);
        $this->assertNotEmpty($payload['model']);
    }

    public function testOpenRouterPayloadModelOverridesGroqDefault(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockOpenRouterProvider($raw);
        $provider->generateDescription('상품', '설명');

        $payload = json_decode($provider->lastPayload, true);
        $this->assertNotSame('llama-3.1-8b-instant', $payload['model'], 'OpenRouter는 Groq 기본 모델을 사용하면 안 된다');
    }

    // ── 실제 Groq API 통합 테스트 ─────────────────────────────────────────────

    public function testGroqApiReturnsValidCategoryIds(): void
    {
        $apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '';
        if (empty($apiKey)) {
            $this->markTestSkipped('GROQ_API_KEY 미설정 — 통합 테스트 건너뜀');
        }

        $this->setProvider('groq');
        $provider = AiCategoryAdvisor::create();
        $tree     = $this->sampleTree();

        $ids = $provider->suggestCategories('남성 반팔 티셔츠', '면 100% 반팔 라운드넥 티셔츠입니다.', $tree);

        $this->assertIsArray($ids, 'Groq API 응답이 배열이어야 한다');

        $validIds = [1, 11, 12, 2, 21];
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertContains($id, $validIds, "반환된 ID {$id}는 카테고리 트리에 존재해야 한다");
        }
    }
}
