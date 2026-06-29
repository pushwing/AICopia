<?php

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

    public function getList(int $limit = 30, int $offset = 0): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit, $offset);
    }

    public function deleteWithFile(int $id): bool
    {
        $media = $this->find($id);
        if (! $media) return false;

        $fullPath = FCPATH . $media['file_path'];
        if (file_exists($fullPath)) unlink($fullPath);

        return (bool) $this->delete($id);
    }
}
