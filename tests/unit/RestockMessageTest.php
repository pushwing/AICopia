<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 재입고 알림 AI 개인화 문구 검증 (#7 / 3단계)
 */
class MockGroqRestockProvider extends GroqProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

class MockClaudeRestockProvider extends ClaudeProvider
{
    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        return $this->success ? $this->mockRaw : false;
    }
}

final class RestockMessageTest extends CIUnitTestCase
{
    public function testGroqReturnsMessage(): void
    {
        $raw = json_encode(['choices' => [['message' => ['content' => '  기다리시던 상품이 돌아왔어요! 빠르게 품절될 수 있으니 서둘러주세요.  ']]]]);
        $msg = (new MockGroqRestockProvider($raw))->generateRestockMessage('면 티셔츠', '편한 데일리 티셔츠');

        $this->assertStringContainsString('돌아왔어요', $msg);
        $this->assertSame($msg, trim($msg), '앞뒤 공백은 제거돼야 한다');
    }

    public function testClaudeReturnsMessage(): void
    {
        $raw = json_encode(['content' => [['text' => '다시 만나보세요.']]]);
        $msg = (new MockClaudeRestockProvider($raw))->generateRestockMessage('운동화', '');
        $this->assertSame('다시 만나보세요.', $msg);
    }

    public function testReturnsEmptyOnApiFailure(): void
    {
        $this->assertSame('', (new MockGroqRestockProvider('', false))->generateRestockMessage('상품', '설명'));
        $this->assertSame('', (new MockClaudeRestockProvider('', false))->generateRestockMessage('상품', '설명'));
    }

    public function testReturnsEmptyOnMissingContent(): void
    {
        $this->assertSame('', (new MockGroqRestockProvider('{}'))->generateRestockMessage('상품', '설명'));
    }

    public function testStripsHtmlFromDescriptionInPayload(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockGroqRestockProvider($raw);
        $provider->generateRestockMessage('가방', '<p>튼튼한 <b>가죽</b> 가방</p>');

        $payload = json_decode($provider->lastPayload, true);
        $userMsg = $payload['messages'][1]['content'];
        $this->assertStringContainsString('튼튼한 가죽 가방', $userMsg);
        $this->assertStringNotContainsString('<p>', $userMsg);
    }
}
