<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * SettingModel::saveSettings() 그룹 지정 검증 — 이슈 #77
 *
 * 신규 키를 저장할 때 group 인자를 지정하지 않으면 항상 'general'로 INSERT되어
 * 외부 API 탭 등에서 처음 저장하는 키가 기본(general) 탭에 노출되던 문제.
 */
final class SettingModelSaveGroupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanupKeys = [];

    protected function tearDown(): void
    {
        if ($this->cleanupKeys !== []) {
            db_connect()->table('settings')->whereIn('key', $this->cleanupKeys)->delete();
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    public function test_new_key_is_inserted_with_given_group(): void
    {
        $key = 'test_api_key_' . uniqid();
        $this->cleanupKeys[] = $key;

        (new SettingModel())->saveSettings([$key => 'sk-test'], 'api');

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('api', $row['group']);
    }

    public function test_new_key_defaults_to_general_group_when_omitted(): void
    {
        $key = 'test_general_key_' . uniqid();
        $this->cleanupKeys[] = $key;

        (new SettingModel())->saveSettings([$key => 'value']);

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('general', $row['group']);
    }

    public function test_existing_key_group_is_not_changed(): void
    {
        $key = 'test_existing_key_' . uniqid();
        $this->cleanupKeys[] = $key;

        db_connect()->table('settings')->insert([
            'group'      => 'shop',
            'key'        => $key,
            'value'      => 'old',
            'label'      => $key,
            'type'       => 'text',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new SettingModel())->saveSettings([$key => 'new'], 'api');

        $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
        $this->assertSame('shop', $row['group']);
        $this->assertSame('new', $row['value']);
    }
}
