<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\AiJobRunner;
use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use App\Libraries\AiProvider\InquiryClassifyHandler;
use App\Libraries\AiProvider\InquiryParsing;
use App\Models\InquiryModel;
use CodeIgniter\Test\CIUnitTestCase;

// ── 트레이트 파싱 노출 호스트 ────────────────────────────────────────────────
class InquiryTraitHost
{
    use InquiryParsing;

    public function parse(string $t): array
    {
        return $this->parseClassification($t);
    }

    public function build(string $s, string $m): string
    {
        return $this->buildInquiryMessage($s, $m);
    }

    public function emptyOne(): array
    {
        return $this->emptyClassification();
    }
}

// ── callApi 모킹 Provider ───────────────────────────────────────────────────
class MockGroqInquiryProvider extends GroqProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

class MockClaudeInquiryProvider extends ClaudeProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

// ── 핸들러용 가짜 의존성 ─────────────────────────────────────────────────────
class StubClassifyProvider implements \App\Libraries\AiProvider\AiProviderInterface
{
    public function __construct(private array $canned) {}

    public function suggestCategories(string $name, string $description, array $tree): array { return []; }
    public function generateDescription(string $name, string $description): string { return ''; }
    public function generateQnaAnswer(string $p, string $d, string $t, string $c): string { return ''; }
    public function summarizeReviews(string $productName, array $reviews): array { return []; }
    public function classifyInquiry(string $subject, string $message): array { return $this->canned; }
    public function generateInquiryReply(string $name, string $subject, string $message): string { return ''; }
    public function generateSalesReport(array $stats): string { return ''; }
    public function generateRestockMessage(string $productName, string $productDescription): string { return ''; }
    public function expandSearchQuery(string $query): array { return []; }
}

class FakeInquiryModel extends InquiryModel
{
    public array $applied = [];
    public function __construct(private ?array $inquiry) {}
    public function find($id = null) { return $this->inquiry; }
    public function applyClassification(int $id, array $classification): bool
    {
        $this->applied = $classification;
        return true;
    }
}

final class InquiryTriageTest extends CIUnitTestCase
{
    private function validJson(): string
    {
        return json_encode(['category' => 'refund', 'priority' => 'high', 'sentiment' => 'negative']);
    }

    // ── parseClassification ─────────────────────────────────────────────────

    public function testParseExtractsEnums(): void
    {
        $r = (new InquiryTraitHost())->parse($this->validJson());
        $this->assertSame(['category' => 'refund', 'priority' => 'high', 'sentiment' => 'negative'], $r);
    }

    public function testParseNormalizesInvalidToDefault(): void
    {
        $r = (new InquiryTraitHost())->parse(json_encode(['category' => 'xxx', 'priority' => 'urgent', 'sentiment' => 'bad']));
        $this->assertSame('etc', $r['category']);
        $this->assertSame('normal', $r['priority']);
        $this->assertSame('neutral', $r['sentiment']);
    }

    public function testParseIsCaseInsensitive(): void
    {
        $r = (new InquiryTraitHost())->parse(json_encode(['category' => 'SHIPPING', 'priority' => 'High', 'sentiment' => 'POSITIVE']));
        $this->assertSame('shipping', $r['category']);
        $this->assertSame('high', $r['priority']);
        $this->assertSame('positive', $r['sentiment']);
    }

    public function testParseExtractsEmbeddedJson(): void
    {
        $r = (new InquiryTraitHost())->parse('분류 결과: ' . $this->validJson() . ' 끝.');
        $this->assertSame('refund', $r['category']);
    }

    public function testParseReturnsDefaultOnMalformed(): void
    {
        $r = (new InquiryTraitHost())->parse('not json');
        $this->assertSame((new InquiryTraitHost())->emptyOne(), $r);
    }

    public function testBuildMessageStripsHtmlAndIncludesFields(): void
    {
        $msg = (new InquiryTraitHost())->build('<b>배송</b> 문의', '<p>언제 오나요?</p>');
        $this->assertStringContainsString('제목: 배송 문의', $msg);
        $this->assertStringContainsString('내용: 언제 오나요?', $msg);
        $this->assertStringNotContainsString('<b>', $msg);
    }

    // ── classifyInquiry (Provider) ──────────────────────────────────────────

    public function testGroqClassifyReturnsEnums(): void
    {
        $raw = json_encode(['choices' => [['message' => ['content' => $this->validJson()]]]]);
        $r   = (new MockGroqInquiryProvider($raw))->classifyInquiry('환불 요청', '환불해주세요');
        $this->assertSame('refund', $r['category']);
        $this->assertSame('high', $r['priority']);
    }

    public function testClaudeClassifyReturnsEnums(): void
    {
        $raw = json_encode(['content' => [['text' => $this->validJson()]]]);
        $r   = (new MockClaudeInquiryProvider($raw))->classifyInquiry('환불', '환불');
        $this->assertSame('negative', $r['sentiment']);
    }

    public function testClassifyReturnsDefaultOnApiFailure(): void
    {
        $r = (new MockGroqInquiryProvider('', false))->classifyInquiry('x', 'y');
        $this->assertSame('etc', $r['category']);
    }

    // ── generateInquiryReply (Provider) ─────────────────────────────────────

    public function testGroqReplyReturnsText(): void
    {
        $raw   = json_encode(['choices' => [['message' => ['content' => '안녕하세요, 홍길동님. 확인 후 안내드리겠습니다. 감사합니다.']]]]);
        $reply = (new MockGroqInquiryProvider($raw))->generateInquiryReply('홍길동', '배송', '언제 오나요?');
        $this->assertStringContainsString('홍길동', $reply);
    }

    public function testReplyReturnsEmptyOnApiFailure(): void
    {
        $this->assertSame('', (new MockGroqInquiryProvider('', false))->generateInquiryReply('a', 'b', 'c'));
    }

    // ── InquiryClassifyHandler ──────────────────────────────────────────────

    public function testHandlerAppliesClassification(): void
    {
        $canned = ['category' => 'payment', 'priority' => 'high', 'sentiment' => 'negative'];
        $model  = new FakeInquiryModel(['id' => 1, 'subject' => '결제 오류', 'message' => '카드가 안돼요']);
        $r      = (new InquiryClassifyHandler(new StubClassifyProvider($canned), $model))->handle(['inquiry_id' => 1]);

        $this->assertTrue($r['ok']);
        $this->assertSame('payment', $r['category']);
        $this->assertSame($canned, $model->applied, '분류 결과가 모델에 저장돼야 한다');
    }

    public function testHandlerSkipsWhenInquiryMissing(): void
    {
        $model = new FakeInquiryModel(null);
        $r     = (new InquiryClassifyHandler(new StubClassifyProvider([]), $model))->handle(['inquiry_id' => 99]);
        $this->assertSame('inquiry_not_found', $r['reason']);
    }

    public function testHandlerSkipsOnInvalidId(): void
    {
        $r = (new InquiryClassifyHandler(new StubClassifyProvider([]), new FakeInquiryModel(null)))->handle(['inquiry_id' => 0]);
        $this->assertSame('invalid_inquiry', $r['reason']);
    }

    public function testDefaultRunnerRegistersInquiryClassify(): void
    {
        $this->assertTrue((new AiJobRunner())->supports('inquiry_classify'));
    }
}
