<?php

namespace Tests\Unit;

use App\Libraries\AiProvider\AiJobRunner;
use App\Models\AiJobModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * AI 작업 큐 검증 (#166)
 *
 * - AiJobModel: enqueue / claimNext(원자적 선점) / markDone / markFailed(재시도·소진)
 * - AiJobRunner: 핸들러 디스패치 / 미등록 타입 예외
 */
final class AiJobQueueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private AiJobModel $model;

    /** @var int[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new AiJobModel();
    }

    protected function tearDown(): void
    {
        if ($this->cleanup !== []) {
            db_connect()->table('ai_jobs')->whereIn('id', $this->cleanup)->delete();
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function enqueue(string $type = 'test_job', array $payload = []): int
    {
        $id = $this->model->enqueue($type, $payload);
        $this->cleanup[] = $id;
        return $id;
    }

    // ── enqueue ───────────────────────────────────────────────────────────────

    public function testEnqueueReturnsIdAndStoresPending(): void
    {
        $id  = $this->enqueue('test_job', ['foo' => 'bar']);
        $row = $this->model->find($id);

        $this->assertGreaterThan(0, $id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(['foo' => 'bar'], json_decode($row['payload'], true));
        $this->assertSame(0, (int) $row['attempts']);
    }

    // ── claimNext ─────────────────────────────────────────────────────────────

    public function testClaimNextMarksJobProcessing(): void
    {
        $id  = $this->enqueue();
        $job = $this->model->claimNext();

        $this->assertNotNull($job);
        $this->assertSame($id, (int) $job['id']);
        $this->assertSame('processing', $job['status']);
        $this->assertNotEmpty($job['worker_token']);
    }

    public function testClaimNextReturnsNullWhenNoPending(): void
    {
        // 대기 작업이 없도록: 큐에 넣고 곧바로 선점하여 비움
        $this->enqueue();
        $this->model->claimNext();

        // 두 번째 선점은 처리 대상이 없어야 한다(이미 processing)
        $this->assertNull($this->model->claimNext());
    }

    public function testClaimNextDoesNotReturnSameJobTwice(): void
    {
        $id    = $this->enqueue();
        $first = $this->model->claimNext();
        $second = $this->model->claimNext();

        $this->assertSame($id, (int) $first['id']);
        $this->assertNull($second, '같은 작업이 두 번 선점되면 안 된다');
    }

    public function testClaimNextSkipsFutureAvailableAt(): void
    {
        $id = $this->enqueue();
        // 미래 시각으로 미뤄두면 선점 대상에서 제외
        $this->model->update($id, ['available_at' => date('Y-m-d H:i:s', time() + 3600)]);

        $this->assertNull($this->model->claimNext());
    }

    // ── markDone ──────────────────────────────────────────────────────────────

    public function testMarkDoneStoresResult(): void
    {
        $id = $this->enqueue();
        $this->model->claimNext();
        $this->model->markDone($id, ['summary' => '좋은 상품']);

        $row = $this->model->find($id);
        $this->assertSame('done', $row['status']);
        $this->assertSame(['summary' => '좋은 상품'], json_decode($row['result'], true));
        $this->assertNull($row['worker_token']);
        $this->assertNotNull($row['processed_at']);
    }

    // ── markFailed ────────────────────────────────────────────────────────────

    public function testMarkFailedRetriesBeforeMaxAttempts(): void
    {
        $id = $this->enqueue();
        $this->model->claimNext();
        $this->model->markFailed($id, '일시적 오류');

        $row = $this->model->find($id);
        $this->assertSame('pending', $row['status'], '재시도 여지가 있으면 pending으로 복귀');
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNotNull($row['available_at'], 'backoff 시각이 설정돼야 한다');
        $this->assertStringContainsString('일시적 오류', $row['error']);
    }

    public function testMarkFailedTransitionsToFailedAtMaxAttempts(): void
    {
        $id = $this->enqueue();
        // max_attempts=3 → 3번 실패 시 failed
        for ($i = 0; $i < 3; $i++) {
            $this->model->update($id, ['available_at' => date('Y-m-d H:i:s')]); // backoff 무시하고 즉시 재선점
            $this->model->claimNext();
            $this->model->markFailed($id, "오류 {$i}");
        }

        $row = $this->model->find($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(3, (int) $row['attempts']);
    }

    // ── AiJobRunner ───────────────────────────────────────────────────────────

    public function testRunnerDispatchesToHandler(): void
    {
        $runner = new AiJobRunner([
            'echo' => static fn (array $p): array => ['got' => $p['msg'] ?? ''],
        ]);

        $result = $runner->run(['type' => 'echo', 'payload' => json_encode(['msg' => '안녕'])]);

        $this->assertSame(['got' => '안녕'], $result);
    }

    public function testRunnerThrowsOnUnregisteredType(): void
    {
        $runner = new AiJobRunner([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/미등록/');
        $runner->run(['type' => 'nope', 'payload' => '{}']);
    }

    public function testRunnerSupportsReportsRegistration(): void
    {
        $runner = new AiJobRunner(['known' => static fn (array $p): array => []]);

        $this->assertTrue($runner->supports('known'));
        $this->assertFalse($runner->supports('unknown'));
    }
}
