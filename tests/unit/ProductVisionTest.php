<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\ClaudeProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 상품 이미지 Vision 추출 검증 (#171)
 *
 * ClaudeProvider::extractProductInfo — Claude 응답 파싱·정규화 (callApi 모킹)
 */
class MockClaudeVisionProvider extends ClaudeProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

final class ProductVisionTest extends CIUnitTestCase
{
    private function claudeResponse(string $text): string
    {
        return json_encode(['content' => [['text' => $text]]]);
    }

    public function testExtractsNameAndDescription(): void
    {
        $raw      = $this->claudeResponse('{"name": "면 티셔츠", "description": "<p>고품질 면 소재</p>"}');
        $provider = new MockClaudeVisionProvider($raw);

        $r = $provider->extractProductInfo('base64data', 'image/jpeg');

        $this->assertSame('면 티셔츠', $r['name']);
        $this->assertStringContainsString('<p>', $r['description']);
        $this->assertStringContainsString('면 소재', $r['description']);
    }

    public function testExtractsJsonEmbeddedInText(): void
    {
        $raw      = $this->claudeResponse('분석 결과: {"name": "운동화", "description": "<p>편한 착화감</p>"} 입니다.');
        $provider = new MockClaudeVisionProvider($raw);

        $r = $provider->extractProductInfo('x', 'image/png');
        $this->assertSame('운동화', $r['name']);
    }

    public function testConvertsMarkdownDescriptionToHtml(): void
    {
        // 모델이 마크다운을 반환해도 HTML로 변환되어야 한다
        $raw      = $this->claudeResponse('{"name": "가방", "description": "튼튼한 가방\n- 수납력\n- 방수"}');
        $provider = new MockClaudeVisionProvider($raw);

        $r = $provider->extractProductInfo('x', 'image/webp');
        $this->assertStringContainsString('<li>수납력</li>', $r['description']);
        $this->assertStringContainsString('<ul>', $r['description']);
    }

    public function testReturnsEmptyOnApiFailure(): void
    {
        $provider = new MockClaudeVisionProvider('', false);
        $r = $provider->extractProductInfo('x', 'image/jpeg');
        $this->assertSame(['name' => '', 'description' => ''], $r);
    }

    public function testReturnsEmptyWhenNameMissing(): void
    {
        $raw      = $this->claudeResponse('{"description": "<p>설명만 있음</p>"}');
        $provider = new MockClaudeVisionProvider($raw);
        $this->assertSame('', $provider->extractProductInfo('x', 'image/jpeg')['name']);
    }

    public function testReturnsEmptyOnMalformedResponse(): void
    {
        $provider = new MockClaudeVisionProvider($this->claudeResponse('JSON 아님'));
        $this->assertSame('', $provider->extractProductInfo('x', 'image/jpeg')['name']);
    }

    public function testTruncatesLongName(): void
    {
        $longName = str_repeat('가', 200);
        $raw      = $this->claudeResponse('{"name": "' . $longName . '", "description": "<p>x</p>"}');
        $provider = new MockClaudeVisionProvider($raw);

        $r = $provider->extractProductInfo('x', 'image/jpeg');
        $this->assertLessThanOrEqual(100, mb_strlen($r['name']));
    }

    public function testPayloadIncludesImageAndMime(): void
    {
        $raw      = $this->claudeResponse('{"name": "테스트", "description": "<p>x</p>"}');
        $provider = new MockClaudeVisionProvider($raw);
        $provider->extractProductInfo('BASE64DATA', 'image/png');

        $payload = json_decode($provider->lastPayload, true);
        $content = $payload['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('image/png', $content[0]['source']['media_type']);
        $this->assertSame('BASE64DATA', $content[0]['source']['data']);
    }
}
