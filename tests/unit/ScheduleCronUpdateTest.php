<?php

namespace Tests\Unit;

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 배치 작업 실행 주기(크론) 업데이트 로직 검증
 * ScheduleController::updateCron() 핵심 동작
 */
final class ScheduleCronUpdateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [];
    private SettingModel $model;

    /** 컨트롤러에 등록된 유효 베이스 키 목록 */
    private const VALID_KEYS = [
        'schedule_orders_expire',
        'schedule_stats_purge_logs',
        'schedule_coupons_birthday',
        'schedule_grades_upgrade',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new SettingModel();
    }

    protected function tearDown(): void
    {
        if (! empty($this->cleanup)) {
            db_connect()->table('settings')->whereIn('id', $this->cleanup)->delete();
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertCronSetting(string $key, string $value = '*/5 * * * *'): int
    {
        $db = db_connect();
        $db->table('settings')->insert([
            'group'      => 'schedule',
            'key'        => $key,
            'value'      => $value,
            'label'      => $key . ' — 크론 주기',
            'type'       => 'text',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;
        return $id;
    }

    /** ScheduleController::updateCron() 동작과 동일한 헬퍼 */
    private function doUpdateCron(string $baseKey, string $cron): bool
    {
        if (! in_array($baseKey, self::VALID_KEYS, true)) {
            return false;
        }

        $cron = trim($cron);
        if (! preg_match('/^(\S+\s+){4}\S+$/', $cron)) {
            return false;
        }

        $this->model->saveSettings([$baseKey . '_cron' => $cron]);
        return true;
    }

    // ── 유효성 검사 ───────────────────────────────────────────────────────────

    public function test_valid_cron_is_accepted(): void
    {
        $result = $this->doUpdateCron('schedule_orders_expire', '*/5 * * * *');
        $this->assertTrue($result);
    }

    public function test_unknown_base_key_returns_false(): void
    {
        $result = $this->doUpdateCron('schedule_nonexistent_job', '*/5 * * * *');
        $this->assertFalse($result);
    }

    public function test_empty_cron_returns_false(): void
    {
        $result = $this->doUpdateCron('schedule_orders_expire', '');
        $this->assertFalse($result);
    }

    public function test_four_field_cron_returns_false(): void
    {
        $result = $this->doUpdateCron('schedule_orders_expire', '*/5 * * *');
        $this->assertFalse($result);
    }

    public function test_six_field_cron_returns_false(): void
    {
        $result = $this->doUpdateCron('schedule_orders_expire', '*/5 * * * * *');
        $this->assertFalse($result);
    }

    // ── 저장 동작 ─────────────────────────────────────────────────────────────

    public function test_cron_is_saved_to_db(): void
    {
        $key = 'schedule_orders_expire_cron_' . uniqid();
        $this->insertCronSetting($key, '*/5 * * * *');

        // saveSettings 는 key 가 존재하면 UPDATE
        $this->model->saveSettings([$key => '0 3 * * *']);

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('0 3 * * *', $row['value']);
    }

    public function test_cron_update_clears_site_settings_cache(): void
    {
        $key = 'schedule_cron_cache_test_' . uniqid();
        $this->insertCronSetting($key, '*/5 * * * *');

        // 캐시 프라이밍
        $this->model->getAllAsMap();
        $this->assertNotNull(cache()->get('site_settings'));

        $this->model->saveSettings([$key => '0 1 * * *']);

        $this->assertNull(cache()->get('site_settings'));
    }

    public function test_updated_cron_reflects_in_getAllAsMap(): void
    {
        $key = 'schedule_cron_reflect_' . uniqid();
        $this->insertCronSetting($key, '*/5 * * * *');
        cache()->delete('site_settings');

        $this->model->saveSettings([$key => '0 2 * * 1']);

        $map = $this->model->getAllAsMap();
        $this->assertSame('0 2 * * 1', $map[$key]);
    }

    public function test_cron_update_does_not_affect_other_settings(): void
    {
        $key1 = 'schedule_cron_side1_' . uniqid();
        $key2 = 'schedule_cron_side2_' . uniqid();
        $this->insertCronSetting($key1, '*/5 * * * *');
        $this->insertCronSetting($key2, '0 1 * * *');

        $this->model->saveSettings([$key1 => '0 3 * * *']);

        $row2 = db_connect()->table('settings')->where('key', $key2)->get()->getRowArray();
        $this->assertSame('0 1 * * *', $row2['value'], 'key2 는 변경되지 않아야 함');
    }

    // ── 크론 표현식 프리셋 유효성 ─────────────────────────────────────────────

    /**
     * @dataProvider validCronProvider
     */
    public function test_preset_cron_expressions_pass_validation(string $cron): void
    {
        $result = $this->doUpdateCron('schedule_orders_expire', $cron);
        $this->assertTrue($result, "프리셋 '{$cron}' 은 유효해야 함");
    }

    public static function validCronProvider(): array
    {
        return [
            'every_minute'    => ['* * * * *'],
            'every_5min'      => ['*/5 * * * *'],
            'every_10min'     => ['*/10 * * * *'],
            'every_15min'     => ['*/15 * * * *'],
            'every_30min'     => ['*/30 * * * *'],
            'hourly'          => ['0 * * * *'],
            'daily_midnight'  => ['0 0 * * *'],
            'daily_1am'       => ['0 1 * * *'],
            'daily_2am'       => ['0 2 * * *'],
            'daily_3am'       => ['0 3 * * *'],
            'weekly_monday'   => ['0 2 * * 1'],
            'weekly_sunday'   => ['0 2 * * 0'],
        ];
    }

    // ── 모든 유효 베이스 키 허용 확인 ────────────────────────────────────────

    /**
     * @dataProvider validBaseKeyProvider
     */
    public function test_all_registered_base_keys_are_accepted(string $baseKey): void
    {
        $result = $this->doUpdateCron($baseKey, '0 1 * * *');
        $this->assertTrue($result, "베이스 키 '{$baseKey}' 는 허용되어야 함");
    }

    public static function validBaseKeyProvider(): array
    {
        return [
            ['schedule_orders_expire'],
            ['schedule_stats_purge_logs'],
            ['schedule_coupons_birthday'],
            ['schedule_grades_upgrade'],
        ];
    }
}
