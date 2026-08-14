<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MediaModel::getListForPicker() 검증 — 이슈 #279
 *
 * 사이트 설정 기본탭의 로고/파비콘 필드에서 "미디어 라이브러리에서 선택" 모달이
 * 사용할 JSON 목록의 필드 구성·경로 포맷을 확인한다.
 */
final class MediaModelPickerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private MediaModel $model;
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new MediaModel();
    }

    protected function tearDown(): void
    {
        if ($this->cleanup !== []) {
            db_connect()->table('media')->whereIn('id', $this->cleanup)->delete();
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    private function insertMedia(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'original_name' => "picker-{$uid}.png",
            'stored_name'   => "{$uid}.png",
            'file_path'     => "uploads/media/2026/08/{$uid}.png",
            'file_size'     => 1234,
            'mime_type'     => 'image/png',
            'alt'           => '',
            'created_at'    => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('media')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;
        return $id;
    }

    public function testReturnsExpectedFieldShape(): void
    {
        $id    = $this->insertMedia();
        $items = array_filter($this->model->getListForPicker(30, 0), fn (array $m): bool => $m['id'] === $id);
        $item  = array_values($items)[0];

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('path', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('alt', $item);
        $this->assertArrayHasKey('original_name', $item);
        $this->assertIsInt($item['id']);
    }

    public function testUrlHasLeadingSlashWhilePathDoesNot(): void
    {
        $id    = $this->insertMedia(['file_path' => 'uploads/media/2026/08/leadingslash.png']);
        $items = array_filter($this->model->getListForPicker(30, 0), fn (array $m): bool => $m['id'] === $id);
        $item  = array_values($items)[0];

        $this->assertSame('uploads/media/2026/08/leadingslash.png', $item['path']);
        $this->assertSame('/uploads/media/2026/08/leadingslash.png', $item['url']);
    }

    public function testOrderedByIdDescNewestFirst(): void
    {
        $id1 = $this->insertMedia();
        $id2 = $this->insertMedia();

        $items = $this->model->getListForPicker(2, 0);
        $ids   = array_column($items, 'id');

        $this->assertSame($id2, $ids[0]);
        $this->assertContains($id1, $ids);
    }
}
