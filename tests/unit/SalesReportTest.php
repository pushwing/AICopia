<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\ClaudeProvider;
use App\Libraries\AiProvider\GroqProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 매출 AI 분석 리포트 검증 (#8 / 3단계)
 *
 * generateSalesReport — callApi 모킹으로 페이로드·응답 처리 검증
 */
class MockGroqSalesProvider extends GroqProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

class MockClaudeSalesProvider extends ClaudeProvider
{
    public string $lastPayload = '';

    public function __construct(private string $mockRaw, private bool $success = true) {}

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        $this->lastPayload = $payload;
        return $this->success ? $this->mockRaw : false;
    }
}

final class SalesReportTest extends CIUnitTestCase
{
    private function stats(): array
    {
        return [
            'period'  => 'daily',
            'from'    => '2026-06-01',
            'to'      => '2026-06-27',
            'summary' => ['total_orders' => 12, 'total_revenue' => 1250000, 'avg_order' => 104166, 'total_profit' => 320000],
            'periods' => [['period' => '2026-06-27', 'orders' => 3, 'revenue' => 300000, 'profit' => 80000]],
            'methods' => [['label' => '토스페이먼츠', 'orders' => 8, 'revenue' => 900000]],
        ];
    }

    public function testGroqReturnsReportText(): void
    {
        $raw    = json_encode(['choices' => [['message' => ['content' => '• 총매출 1,250,000원으로 양호합니다.']]]]);
        $report = (new MockGroqSalesProvider($raw))->generateSalesReport($this->stats());
        $this->assertStringContainsString('총매출', $report);
    }

    public function testClaudeReturnsReportText(): void
    {
        $raw    = json_encode(['content' => [['text' => '• 영업이익 320,000원']]]);
        $report = (new MockClaudeSalesProvider($raw))->generateSalesReport($this->stats());
        $this->assertStringContainsString('영업이익', $report);
    }

    public function testReturnsEmptyOnApiFailure(): void
    {
        $this->assertSame('', (new MockGroqSalesProvider('', false))->generateSalesReport($this->stats()));
        $this->assertSame('', (new MockClaudeSalesProvider('', false))->generateSalesReport($this->stats()));
    }

    public function testReturnsEmptyOnMissingContent(): void
    {
        $this->assertSame('', (new MockGroqSalesProvider('{}'))->generateSalesReport($this->stats()));
    }

    public function testPayloadIncludesStatsJson(): void
    {
        $raw      = json_encode(['choices' => [['message' => ['content' => 'ok']]]]);
        $provider = new MockGroqSalesProvider($raw);
        $provider->generateSalesReport($this->stats());

        $payload = json_decode($provider->lastPayload, true);
        $userMsg = $payload['messages'][1]['content'];
        $this->assertStringContainsString('1250000', $userMsg, '요약 수치가 페이로드에 포함돼야 한다');
        $this->assertStringContainsString('토스페이먼츠', $userMsg);
    }
}
