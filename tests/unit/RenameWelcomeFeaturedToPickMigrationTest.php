<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * RenameWelcomeFeaturedToPick 마이그레이션
 *
 * 홈페이지 "기획전" 섹션명이 프로모션 캠페인(별도 기능, /admin/promotions)과
 * 이름이 겹쳐 헷갈린다는 피드백으로 "PICK 상품"으로 바꿨다. welcome_featured_title
 * 값이 아직 옛 기본값 '기획전' 그대로인 설치만 새 기본값으로 옮기고, 관리자가
 * 이미 직접 다른 제목으로 바꿔둔 경우는 건드리지 않는다.
 */
final class RenameWelcomeFeaturedToPickMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ?array $originalRow = null;
    private bool $inserted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $files = glob(APPPATH . 'Database/Migrations/*_RenameWelcomeFeaturedToPick.php');
        require_once $files[0];
    }

    protected function tearDown(): void
    {
        if ($this->inserted) {
            db_connect()->table('settings')->where('key', 'welcome_featured_title')->delete();
        }
        if ($this->originalRow !== null) {
            db_connect()->table('settings')->where('key', 'welcome_featured_title')->update($this->originalRow);
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    private function seedTitle(string $value): void
    {
        $row = db_connect()->table('settings')->where('key', 'welcome_featured_title')->get()->getRowArray();
        if ($row) {
            $this->originalRow = ['value' => $row['value']];
            db_connect()->table('settings')->where('key', 'welcome_featured_title')->update(['value' => $value]);
            return;
        }

        $this->inserted = true;
        db_connect()->table('settings')->insert([
            'group'      => 'welcome',
            'key'        => 'welcome_featured_title',
            'value'      => $value,
            'label'      => '기획전 섹션 제목',
            'type'       => 'text',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_default_title_is_renamed_to_pick(): void
    {
        $this->seedTitle('기획전');

        (new \App\Database\Migrations\RenameWelcomeFeaturedToPick())->up();

        $row = db_connect()->table('settings')->where('key', 'welcome_featured_title')->get()->getRowArray();
        $this->assertSame('PICK 상품', $row['value']);
    }

    public function test_customized_title_is_left_alone(): void
    {
        $this->seedTitle('여름 신상 모음');

        (new \App\Database\Migrations\RenameWelcomeFeaturedToPick())->up();

        $row = db_connect()->table('settings')->where('key', 'welcome_featured_title')->get()->getRowArray();
        $this->assertSame('여름 신상 모음', $row['value']);
    }
}
