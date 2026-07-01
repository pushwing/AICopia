<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PostCommentModel extends Model
{
    #[\Override]
    protected $table      = 'post_comments';
    #[\Override]
    protected $primaryKey = 'id';
    #[\Override]
    protected $useTimestamps  = true;
    #[\Override]
    protected $updatedField   = '';
    #[\Override]
    protected $useSoftDeletes = true;
    #[\Override]
    protected $allowedFields  = [
        'post_id', 'user_id', 'content',
        'author_name', 'author_password', 'ip_address',
    ];

    /** @return array<int, array<string, mixed>> */
    public function getByPost(int $postId): array
    {
        return $this->select('post_comments.*, users.nickname as user_nickname')
                    ->join('users', 'users.id = post_comments.user_id', 'left')
                    ->where('post_comments.post_id', $postId)
                    ->orderBy('post_comments.id', 'ASC')
                    ->findAll();
    }
}
