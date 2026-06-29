<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BoardModel;
use App\Models\PostModel;
use App\Models\PostFileModel;

class PostController extends BaseController
{
    private PostModel  $postModel;
    private BoardModel $boardModel;

    public function __construct()
    {
        $this->postModel  = new PostModel();
        $this->boardModel = new BoardModel();
    }

    public function index(): string
    {
        $keyword = $this->request->getGet('q') ?? '';
        $boardId = (int) ($this->request->getGet('board_id') ?? 0);
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 20;

        $result = $this->postModel->getAdminList($page, $perPage, $keyword, $boardId);
        $boards = $this->boardModel->orderBy('sort_order')->findAll();

        return $this->render('admin/posts/list', [
            'posts'       => $result['posts'],
            'total'       => $result['total'],
            'currentPage' => $page,
            'totalPages'  => (int) ceil($result['total'] / $perPage),
            'keyword'     => $keyword,
            'boardId'     => $boardId,
            'boards'      => $boards,
        ]);
    }

    /** GET /admin/posts/json */
    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $posts = $this->postModel
            ->select('posts.id, posts.title, posts.is_notice, posts.is_secret, posts.views,
                      posts.created_at, posts.author_name,
                      boards.name AS board_name, boards.slug AS board_slug,
                      users.nickname AS user_nickname')
            ->join('boards', 'boards.id = posts.board_id', 'left')
            ->join('users', 'users.id = posts.user_id', 'left')
            ->orderBy('posts.id', 'DESC')
            ->findAll();

        $data = array_map(fn($p) => [
            'id'         => (int) $p['id'],
            'title'      => $p['title'],
            'is_notice'  => (int) $p['is_notice'],
            'is_secret'  => (int) $p['is_secret'],
            'board_name' => $p['board_name'] ?? '',
            'board_slug' => $p['board_slug'] ?? '',
            'author'     => $p['user_nickname'] ?: ($p['author_name'] ?? ''),
            'views'      => (int) $p['views'],
            'created_at' => $p['created_at'],
        ], $posts);

        return $this->response->setJSON(['data' => $data]);
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->postModel->find($id);
        if (! $post) {
            return redirect()->back()->with('error', '게시글을 찾을 수 없습니다.');
        }

        (new PostFileModel())->deleteByPost($id);
        $this->postModel->delete($id);

        return redirect()->back()->with('success', '삭제되었습니다.');
    }
}
