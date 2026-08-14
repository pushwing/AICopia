<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table      = 'media';
    protected $primaryKey = 'id';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = [
        'original_name', 'stored_name', 'file_path', 'file_size', 'mime_type', 'alt',
    ];

    /** @return array<int, array<string, mixed>> */
    public function getList(int $limit = 30, int $offset = 0): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit, $offset);
    }

    /**
     * 미디어 선택 모달(picker)용 목록 — 경로에 선행 슬래시를 붙여 그대로 <img src>/hidden input 값으로 쓸 수 있게 정리한다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getListForPicker(int $limit = 24, int $offset = 0): array
    {
        return array_map(static fn (array $m): array => [
            'id'            => (int) $m['id'],
            'path'          => $m['file_path'],
            'url'           => '/' . $m['file_path'],
            'alt'           => $m['alt'] ?? '',
            'original_name' => $m['original_name'],
        ], $this->getList($limit, $offset));
    }

    public function deleteWithFile(int $id): bool
    {
        $media = $this->find($id);
        if (! $media) {
            return false;
        }

        $fullPath = FCPATH . $media['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        return (bool) $this->delete($id);
    }
}
