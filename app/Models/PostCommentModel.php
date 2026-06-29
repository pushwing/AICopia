<?php

namespace App\Models;

use CodeIgniter\Model;

class PostCommentModel extends Model
{
    protected $table      = 'post_comments';
    protected $primaryKey = 'id';
    protected $useTimestamps  = true;
    protected $updatedField   = '';
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'post_id', 'user_id', 'content',
        'author_name', 'author_password', 'ip_address',
    ];

    public function getByPost(int $postId): array
    {
        return $this->select('post_comments.*, users.nickname as user_nickname')
                    ->join('users', 'users.id = post_comments.user_id', 'left')
                    ->where('post_comments.post_id', $postId)
                    ->orderBy('post_comments.id', 'ASC')
                    ->findAll();
    }
}
