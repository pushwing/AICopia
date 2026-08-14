<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * FixWelcomeHomepageSettingGroup 마이그레이션 — FixStraySettingsGroups(#78) 후속
 *
 * store_homepage 키는 welcome_show_* 등과 달리 마이그레이션으로 미리 시딩된 적이
 * 없어, WelcomeController::update()가 saveSettings() group 버그가 살아있던
 * 시점에 처음 저장되면 'general'로 INSERT되어 "사이트 설정 > 기본" 탭에 원시
 * 키로 노출됐다. 값은 건드리지 않고 그룹만 'welcome'으로 바로잡는다.
 */
final class FixWelcomeHomepageSettingGroupMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** 테스트용으로 새로 INSERT한 경우에만 채워짐 — tearDown에서 삭제 */
    private bool $inserted = false;

    /** 기존 행을 건드린 경우에만 채워짐 — tearDown에서 복원 */
    private ?array $originalRow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $files = glob(APPPATH . 'Database/Migrations/*_FixWelcomeHomepageSettingGroup.php');
        require_once $files[0];
    }

    protected function tearDown(): void
    {
        if ($this->inserted) {
            db_connect()->table('settings')->where('key', 'store_homepage')->delete();
        }
        if ($this->originalRow !== null) {
            db_connect()->table('settings')->where('key', 'store_homepage')->update($this->originalRow);
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    private function seedAsGeneral(string $value = 'welcome'): void
    {
        $row = db_connect()->table('settings')->where('key', 'store_homepage')->get()->getRowArray();
        if ($row) {
            $this->originalRow = ['group' => $row['group'], 'value' => $row['value']];
            db_connect()->table('settings')->where('key', 'store_homepage')->update(['group' => 'general', 'value' => $value]);
            return;
        }

        $this->inserted = true;
        db_connect()->table('settings')->insert([
            'group'      => 'general',
            'key'        => 'store_homepage',
            'value'      => $value,
            'label'      => 'store_homepage',
            'type'       => 'text',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_stray_general_row_is_moved_to_welcome_group(): void
    {
        $this->seedAsGeneral();

        (new \App\Database\Migrations\FixWelcomeHomepageSettingGroup())->up();

        $row = db_connect()->table('settings')->where('key', 'store_homepage')->get()->getRowArray();
        $this->assertSame('welcome', $row['group']);
    }

    public function test_value_is_untouched(): void
    {
        $this->seedAsGeneral('welcome');

        (new \App\Database\Migrations\FixWelcomeHomepageSettingGroup())->up();

        $row = db_connect()->table('settings')->where('key', 'store_homepage')->get()->getRowArray();
        $this->assertSame('welcome', $row['value']);
    }

    public function test_row_already_in_welcome_group_is_left_alone(): void
    {
        $row = db_connect()->table('settings')->where('key', 'store_homepage')->get()->getRowArray();
        if ($row) {
            $this->originalRow = ['group' => $row['group'], 'value' => $row['value']];
            db_connect()->table('settings')->where('key', 'store_homepage')->update(['group' => 'welcome', 'value' => 'default']);
        } else {
            $this->inserted = true;
            db_connect()->table('settings')->insert([
                'group'      => 'welcome',
                'key'        => 'store_homepage',
                'value'      => 'default',
                'label'      => '스토어 첫 화면',
                'type'       => 'select',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new \App\Database\Migrations\FixWelcomeHomepageSettingGroup())->up();

        $after = db_connect()->table('settings')->where('key', 'store_homepage')->get()->getRowArray();
        $this->assertSame('welcome', $after['group']);
        $this->assertSame('default', $after['value']);
    }
}
