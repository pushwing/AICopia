<?php

namespace App\Models;

use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table      = 'posts';
    protected $primaryKey = 'id';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'board_id', 'user_id', 'title', 'content',
        'author_name', 'author_password',
        'is_notice', 'is_secret', 'ip_address',
    ];

    public function getList(int $boardId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $notices = $this->where('board_id', $boardId)
                        ->where('is_notice', 1)
                        ->orderBy('id', 'DESC')
                        ->findAll(5);

        $posts = $this->select('posts.*, users.nickname as user_nickname')
                      ->join('users', 'users.id = posts.user_id', 'left')
                      ->where('posts.board_id', $boardId)
                      ->where('posts.is_notice', 0)
                      ->orderBy('posts.id', 'DESC')
                      ->findAll($perPage, $offset);

        return ['notices' => $notices, 'posts' => $posts];
    }

    public function getTotalCount(int $boardId): int
    {
        return $this->where('board_id', $boardId)
                    ->where('is_notice', 0)
                    ->countAllResults();
    }

    public function getDetail(int $id): ?array
    {
        return $this->select('posts.*, users.nickname as user_nickname, users.email as user_email')
                    ->join('users', 'users.id = posts.user_id', 'left')
                    ->find($id);
    }

    public function incrementView(int $id): void
    {
        $this->db->query('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
    }

    public function getAdminList(int $page, int $perPage, string $keyword = '', int $boardId = 0): array
    {
        $builder = $this->select('posts.*, boards.name as board_name, boards.slug as board_slug, users.nickname as user_nickname')
                        ->join('boards', 'boards.id = posts.board_id', 'left')
                        ->join('users', 'users.id = posts.user_id', 'left');

        if ($boardId > 0) {
            $builder->where('posts.board_id', $boardId);
        }
        if ($keyword !== '') {
            $builder->groupStart()
                    ->like('posts.title', $keyword)
                    ->orLike('posts.author_name', $keyword)
                    ->orLike('users.nickname', $keyword)
                    ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $posts = $builder->orderBy('posts.id', 'DESC')
                         ->findAll($perPage, ($page - 1) * $perPage);

        return ['posts' => $posts, 'total' => $total];
    }

    public function search(int $boardId, string $keyword, string $type, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $builder = $this->select('posts.*, users.nickname as user_nickname')
                        ->join('users', 'users.id = posts.user_id', 'left')
                        ->where('posts.board_id', $boardId);

        if ($type === 'title') {
            $builder->like('posts.title', $keyword);
        } elseif ($type === 'content') {
            $builder->like('posts.content', $keyword);
        } else {
            $builder->groupStart()
                    ->like('posts.title', $keyword)
                    ->orLike('posts.content', $keyword)
                    ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $posts = $builder->orderBy('posts.id', 'DESC')->findAll($perPage, $offset);

        return ['posts' => $posts, 'total' => $total];
    }
}
