<?php

namespace Tests\Unit;

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 배치 작업 설정 토글 로직 검증
 * ScheduleController::toggle() 핵심 동작
 */
final class ScheduleToggleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [];
    private SettingModel $model;

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

    private function insertScheduleSetting(string $key, string $value = '1'): int
    {
        $db = db_connect();
        $db->table('settings')->insert([
            'group'      => 'schedule',
            'key'        => $key,
            'value'      => $value,
            'label'      => '테스트 작업 (' . $key . ')',
            'type'       => 'boolean',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;
        return $id;
    }

    /** ScheduleController::toggle() 동작과 동일한 헬퍼 */
    private function doToggle(string $key): bool
    {
        $existing = $this->model->where('group', 'schedule')->where('key', $key)->first();
        if (! $existing) return false;

        $newValue = $existing['value'] === '1' ? '0' : '1';
        $this->model->saveSettings([$key => $newValue]);
        return true;
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function test_toggle_disables_enabled_setting(): void
    {
        $key = 'schedule_test_toggle_a_' . uniqid();
        $this->insertScheduleSetting($key, '1');

        $this->doToggle($key);

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('0', $row['value']);
    }

    public function test_toggle_enables_disabled_setting(): void
    {
        $key = 'schedule_test_toggle_b_' . uniqid();
        $this->insertScheduleSetting($key, '0');

        $this->doToggle($key);

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('1', $row['value']);
    }

    public function test_toggle_twice_returns_to_original_value(): void
    {
        $key = 'schedule_test_toggle_c_' . uniqid();
        $this->insertScheduleSetting($key, '1');

        $this->doToggle($key);
        $this->doToggle($key);

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('1', $row['value']);
    }

    public function test_toggle_clears_site_settings_cache(): void
    {
        $key = 'schedule_test_toggle_d_' . uniqid();
        $this->insertScheduleSetting($key, '1');

        // 캐시 프라이밍
        $before = $this->model->getAllAsMap();
        $this->assertSame('1', $before[$key] ?? '1');

        $this->doToggle($key);

        // saveSettings 내부에서 cache()->delete('site_settings') 호출됨
        $this->assertNull(cache()->get('site_settings'));
    }

    public function test_toggle_reflects_in_getAllAsMap_after_clear(): void
    {
        $key = 'schedule_test_toggle_e_' . uniqid();
        $this->insertScheduleSetting($key, '1');

        $this->doToggle($key);

        $map = $this->model->getAllAsMap();
        $this->assertSame('0', $map[$key]);
    }

    public function test_toggle_unknown_key_returns_false(): void
    {
        $result = $this->doToggle('schedule_nonexistent_key_xyz');
        $this->assertFalse($result);
    }

    public function test_toggle_does_not_affect_other_settings(): void
    {
        $key1 = 'schedule_test_toggle_f1_' . uniqid();
        $key2 = 'schedule_test_toggle_f2_' . uniqid();
        $this->insertScheduleSetting($key1, '1');
        $this->insertScheduleSetting($key2, '1');

        $this->doToggle($key1);

        $row2 = db_connect()->table('settings')->where('key', $key2)->get()->getRowArray();
        $this->assertSame('1', $row2['value'], 'key2 는 변경되지 않아야 함');
    }

    public function test_schedule_group_setting_is_boolean_type(): void
    {
        $key = 'schedule_test_toggle_g_' . uniqid();
        $this->insertScheduleSetting($key, '1');

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('boolean', $row['type']);
        $this->assertSame('schedule', $row['group']);
    }
}
