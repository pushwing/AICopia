<?php

namespace App\Models;

use CodeIgniter\Model;

class PostFileModel extends Model
{
    protected $table      = 'post_files';
    protected $primaryKey = 'id';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $allowedFields  = [
        'post_id', 'original_name', 'stored_name',
        'file_path', 'file_size', 'mime_type', 'is_image',
    ];

    public function getByPost(int $postId): array
    {
        return $this->where('post_id', $postId)->findAll();
    }

    public function getImages(int $postId): array
    {
        return $this->where('post_id', $postId)->where('is_image', 1)->findAll();
    }

    public function incrementDownload(int $id): void
    {
        $this->db->query('UPDATE post_files SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }

    public function deleteByPost(int $postId): void
    {
        $files = $this->getByPost($postId);
        foreach ($files as $file) {
            $fullPath = FCPATH . $file['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
        $this->where('post_id', $postId)->delete();
    }
}
