<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * FixStraySettingsGroups 마이그레이션 — #78(FixApiSettingsGroup) 후속
 *
 * openrouter_api_key/openrouter_model, pg_enabled_*, oauth_enabled_* 키가
 * saveSettings() group 버그로 'general'에 남아 있으면 각자의 그룹으로 옮긴다.
 * 값은 건드리지 않는다.
 *
 * 마이그레이션 파일명은 타임스탬프 접두어라 PSR-4 오토로드 대상이 아니므로
 * 클래스를 직접 require 해서 인스턴스화한다(CI4 마이그레이션 러너와 동일하게
 * 파일 경로 기준으로 로드).
 */
final class FixStraySettingsGroupsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** pg_enabled_*·oauth_enabled_*는 마이그레이션으로 미리 시딩되지 않아 tests DB에 없을 수 있다 */
    private array $insertedKeys = [];

    private array $originalGroups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $files = glob(APPPATH . 'Database/Migrations/*_FixStraySettingsGroups.php');
        require_once $files[0];
    }

    protected function tearDown(): void
    {
        if ($this->insertedKeys !== []) {
            db_connect()->table('settings')->whereIn('key', $this->insertedKeys)->delete();
        }
        foreach ($this->originalGroups as $key => $group) {
            db_connect()->table('settings')->where('key', $key)->update(['group' => $group]);
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    /** 키가 이미 있으면 group만 general로 바꾸고 원래 group을 기억, 없으면 테스트용으로 INSERT 후 나중에 삭제 */
    private function seedAsGeneral(string $key, string $value = 'test-value'): void
    {
        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        if ($row) {
            $this->originalGroups[$key] = $row['group'];
            db_connect()->table('settings')->where('key', $key)->update(['group' => 'general']);
            return;
        }

        $this->insertedKeys[] = $key;
        db_connect()->table('settings')->insert([
            'group'      => 'general',
            'key'        => $key,
            'value'      => $value,
            'label'      => $key,
            'type'       => 'text',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_stray_general_rows_are_moved_to_correct_group(): void
    {
        $this->seedAsGeneral('openrouter_model');
        $this->seedAsGeneral('pg_enabled_toss');
        $this->seedAsGeneral('oauth_enabled_kakao');

        (new \App\Database\Migrations\FixStraySettingsGroups())->up();

        $this->assertSame('api', $this->groupOf('openrouter_model'));
        $this->assertSame('pg', $this->groupOf('pg_enabled_toss'));
        $this->assertSame('oauth', $this->groupOf('oauth_enabled_kakao'));
    }

    public function test_value_is_untouched(): void
    {
        $this->seedAsGeneral('openrouter_model', 'kept-value');

        (new \App\Database\Migrations\FixStraySettingsGroups())->up();

        $row = db_connect()->table('settings')->where('key', 'openrouter_model')->get()->getRowArray();
        $this->assertSame('kept-value', $row['value']);
    }

    private function groupOf(string $key): ?string
    {
        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        return $row['group'] ?? null;
    }
}
