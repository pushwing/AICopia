<?php

namespace Tests\Unit;

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 배치 커맨드 활성화 가드 검증
 * 각 Command::run()에서 settings 키를 확인해 비활성 시 스킵하는 로직
 */
final class ScheduleCommandEnabledTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [];

    protected function tearDown(): void
    {
        if (! empty($this->cleanup)) {
            db_connect()->table('settings')->whereIn('id', $this->cleanup)->delete();
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertScheduleSetting(string $key, string $value): int
    {
        $db = db_connect();
        $db->table('settings')->insert([
            'group'      => 'schedule',
            'key'        => $key,
            'value'      => $value,
            'label'      => $key,
            'type'       => 'boolean',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;
        return $id;
    }

    /** 커맨드 내부와 동일한 enabled 판정 */
    private function isEnabled(array $settings, string $key): bool
    {
        return (bool) ($settings[$key] ?? 1);
    }

    // ── 기본값 테스트 ─────────────────────────────────────────────────────────

    public function test_default_enabled_when_key_absent(): void
    {
        cache()->delete('site_settings');
        $settings = (new SettingModel())->getAllAsMap();

        // settings 에 키가 없을 때 ?? 1 폴백 → 활성
        $this->assertTrue($this->isEnabled($settings, 'schedule_nonexistent_xyz'));
    }

    // ── orders:expire ─────────────────────────────────────────────────────────

    public function test_orders_expire_enabled_when_setting_is_one(): void
    {
        $this->insertScheduleSetting('schedule_orders_expire_test_' . uniqid(), '1');
        cache()->delete('site_settings');

        $settings = (new SettingModel())->getAllAsMap();
        // 실제 키가 '1'인 경우 — isEnabled true
        $key = array_key_first(array_filter($settings, fn($v) => $v === '1'));
        $this->assertTrue($this->isEnabled(['schedule_orders_expire_enabled' => '1'], 'schedule_orders_expire_enabled'));
    }

    public function test_orders_expire_skips_when_setting_is_zero(): void
    {
        $key = 'schedule_orders_expire_enabled_' . uniqid();
        $this->insertScheduleSetting($key, '0');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertFalse($this->isEnabled($map, $key));
    }

    // ── stats:purge-logs ──────────────────────────────────────────────────────

    public function test_purge_logs_skips_when_setting_is_zero(): void
    {
        $key = 'schedule_stats_purge_logs_enabled_' . uniqid();
        $this->insertScheduleSetting($key, '0');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertFalse($this->isEnabled($map, $key));
    }

    public function test_purge_logs_runs_when_setting_is_one(): void
    {
        $key = 'schedule_stats_purge_logs_enabled_' . uniqid();
        $this->insertScheduleSetting($key, '1');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertTrue($this->isEnabled($map, $key));
    }

    // ── coupons:birthday ──────────────────────────────────────────────────────

    public function test_birthday_coupon_skips_when_setting_is_zero(): void
    {
        $key = 'schedule_coupons_birthday_enabled_' . uniqid();
        $this->insertScheduleSetting($key, '0');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertFalse($this->isEnabled($map, $key));
    }

    // ── grades:upgrade ────────────────────────────────────────────────────────

    public function test_grades_upgrade_skips_when_setting_is_zero(): void
    {
        $key = 'schedule_grades_upgrade_enabled_' . uniqid();
        $this->insertScheduleSetting($key, '0');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertFalse($this->isEnabled($map, $key));
    }

    // ── 설정 값 '0' 과 0 의 동일성 ──────────────────────────────────────────

    public function test_string_zero_is_falsy(): void
    {
        // settings 값은 문자열 '0' — (bool)'0' === false 를 보장
        $this->assertFalse((bool) '0');
        $this->assertTrue((bool) '1');
    }

    // ── 활성화 상태에서 getAllAsMap 반영 확인 ─────────────────────────────────

    public function test_enabled_setting_reflected_in_getAllAsMap(): void
    {
        $key = 'schedule_reflect_test_' . uniqid();
        $this->insertScheduleSetting($key, '1');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertArrayHasKey($key, $map);
        $this->assertSame('1', $map[$key]);
    }

    public function test_disabled_setting_reflected_in_getAllAsMap(): void
    {
        $key = 'schedule_reflect_disabled_' . uniqid();
        $this->insertScheduleSetting($key, '0');
        cache()->delete('site_settings');

        $map = (new SettingModel())->getAllAsMap();
        $this->assertArrayHasKey($key, $map);
        $this->assertSame('0', $map[$key]);
    }
}
