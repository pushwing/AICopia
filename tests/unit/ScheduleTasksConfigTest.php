<?php

namespace Tests\Unit;

use CodeIgniter\Tasks\Scheduler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Tasks;

/**
 * Config\Tasks::init() — DB settings 기반 스케줄 등록 로직 검증
 *
 * settings 테이블의 _enabled / _cron 값에 따라 Scheduler에 잡이
 * 올바르게 등록/미등록되는지 확인합니다.
 */
final class ScheduleTasksConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** 테스트 전 _enabled/_cron 원본 값 보존용 */
    private array $savedValues = [];

    private const JOBS = [
        'schedule_orders_expire',
        'schedule_stats_purge_logs',
        'schedule_coupons_birthday',
        'schedule_grades_upgrade',
        'schedule_ai_work',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete('site_settings');

        // 원본 값 보존 후 전체 비활성화 — 테스트 간 간섭 방지
        $db = db_connect();
        foreach (self::JOBS as $base) {
            foreach (['_enabled', '_cron'] as $suffix) {
                $row = $db->table('settings')
                          ->where('key', $base . $suffix)
                          ->get()->getRowArray();
                if ($row) {
                    $this->savedValues[$base . $suffix] = $row['value'];
                }
            }
            $db->table('settings')->where('key', $base . '_enabled')->update(['value' => '0']);
        }
        cache()->delete('site_settings');
    }

    protected function tearDown(): void
    {
        // 원본 값 복원
        $db = db_connect();
        foreach ($this->savedValues as $key => $value) {
            $db->table('settings')->where('key', $key)->update(['value' => $value]);
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function setSettings(array $map): void
    {
        $db = db_connect();
        foreach ($map as $key => $value) {
            $db->table('settings')->where('key', $key)->update(['value' => $value]);
        }
        cache()->delete('site_settings');
    }

    private function runInit(): Scheduler
    {
        $scheduler = new Scheduler();
        (new Tasks())->init($scheduler);
        return $scheduler;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function test_disabled_job_is_not_registered(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled' => '0',
            'schedule_orders_expire_cron'    => '*/5 * * * *',
        ]);

        $scheduler = $this->runInit();

        $this->assertCount(0, $scheduler->getTasks());
    }

    public function test_enabled_job_with_valid_cron_is_registered(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled' => '1',
            'schedule_orders_expire_cron'    => '*/5 * * * *',
        ]);

        $scheduler = $this->runInit();
        $tasks     = $scheduler->getTasks();

        $this->assertCount(1, $tasks);
    }

    public function test_cron_expression_is_applied_correctly(): void
    {
        $cron = '0 2 * * 1';
        $this->setSettings([
            'schedule_stats_purge_logs_enabled' => '1',
            'schedule_stats_purge_logs_cron'    => $cron,
        ]);

        $scheduler = $this->runInit();
        $tasks     = $scheduler->getTasks();

        $this->assertCount(1, $tasks);
        $this->assertSame($cron, $tasks[0]->getExpression());
    }

    public function test_enabled_job_with_empty_cron_is_not_registered(): void
    {
        $this->setSettings([
            'schedule_coupons_birthday_enabled' => '1',
            'schedule_coupons_birthday_cron'    => '',
        ]);

        $scheduler = $this->runInit();

        $this->assertCount(0, $scheduler->getTasks());
    }

    public function test_only_enabled_jobs_are_registered(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled'    => '1',
            'schedule_orders_expire_cron'       => '*/5 * * * *',
            'schedule_stats_purge_logs_enabled' => '0',
            'schedule_stats_purge_logs_cron'    => '0 2 * * 1',
            'schedule_coupons_birthday_enabled' => '1',
            'schedule_coupons_birthday_cron'    => '0 1 * * *',
            'schedule_grades_upgrade_enabled'   => '0',
            'schedule_grades_upgrade_cron'      => '0 3 * * *',
        ]);

        $scheduler = $this->runInit();

        $this->assertCount(2, $scheduler->getTasks());
    }

    public function test_all_four_jobs_enabled(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled'    => '1',
            'schedule_orders_expire_cron'       => '*/5 * * * *',
            'schedule_stats_purge_logs_enabled' => '1',
            'schedule_stats_purge_logs_cron'    => '0 2 * * 1',
            'schedule_coupons_birthday_enabled' => '1',
            'schedule_coupons_birthday_cron'    => '0 1 * * *',
            'schedule_grades_upgrade_enabled'   => '1',
            'schedule_grades_upgrade_cron'      => '0 3 * * *',
        ]);

        $scheduler = $this->runInit();

        $this->assertCount(4, $scheduler->getTasks());
    }

    public function test_all_jobs_disabled_registers_nothing(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled'    => '0',
            'schedule_stats_purge_logs_enabled' => '0',
            'schedule_coupons_birthday_enabled' => '0',
            'schedule_grades_upgrade_enabled'   => '0',
        ]);

        $scheduler = $this->runInit();

        $this->assertCount(0, $scheduler->getTasks());
    }

    public function test_multiple_different_cron_expressions(): void
    {
        $this->setSettings([
            'schedule_orders_expire_enabled'    => '1',
            'schedule_orders_expire_cron'       => '*/30 * * * *',
            'schedule_stats_purge_logs_enabled' => '1',
            'schedule_stats_purge_logs_cron'    => '0 2 * * 1',
        ]);

        $scheduler = $this->runInit();
        $tasks     = $scheduler->getTasks();

        $this->assertCount(2, $tasks);

        $expressions = array_map(fn ($t) => $t->getExpression(), $tasks);
        $this->assertContains('*/30 * * * *', $expressions);
        $this->assertContains('0 2 * * 1', $expressions);
    }
}
