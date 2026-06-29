<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BoardModel;
use App\Models\PostModel;

class BoardManagerController extends BaseController
{
    private BoardModel $boardModel;
    private PostModel  $postModel;

    public function __construct()
    {
        $this->boardModel = new BoardModel();
        $this->postModel  = new PostModel();
    }

    // 게시판 목록
    public function index(): string
    {
        $boards = $this->boardModel->orderBy('sort_order')->findAll();
        return $this->render('admin/board/list', ['boards' => $boards]);
    }

    // 게시판 생성 폼
    public function create(): string
    {
        return $this->render('admin/board/form', ['board' => null]);
    }

    // 게시판 저장
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'slug' => 'required|alpha_dash|is_unique[boards.slug]',
            'name' => 'required',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->boardModel->insert([
            'slug'             => $this->request->getPost('slug'),
            'name'             => $this->request->getPost('name'),
            'description'      => $this->request->getPost('description'),
            'read_permission'  => $this->request->getPost('read_permission'),
            'write_permission' => $this->request->getPost('write_permission'),
            'allow_file'       => (int) $this->request->getPost('allow_file'),
            'allow_image'      => (int) $this->request->getPost('allow_image'),
            'posts_per_page'   => (int) $this->request->getPost('posts_per_page') ?: 15,
            'sort_order'       => (int) $this->request->getPost('sort_order'),
        ]);

        return redirect()->to('/admin/boards')->with('success', '게시판이 생성되었습니다.');
    }

    // 게시판 수정
    public function edit(int $id): string
    {
        $board = $this->boardModel->find($id);
        return $this->render('admin/board/form', ['board' => $board]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->boardModel->update($id, [
            'name'             => $this->request->getPost('name'),
            'description'      => $this->request->getPost('description'),
            'read_permission'  => $this->request->getPost('read_permission'),
            'write_permission' => $this->request->getPost('write_permission'),
            'allow_file'       => (int) $this->request->getPost('allow_file'),
            'allow_image'      => (int) $this->request->getPost('allow_image'),
            'posts_per_page'   => (int) $this->request->getPost('posts_per_page') ?: 15,
            'sort_order'       => (int) $this->request->getPost('sort_order'),
            'is_active'        => (int) $this->request->getPost('is_active'),
        ]);

        return redirect()->to('/admin/boards')->with('success', '수정되었습니다.');
    }

    // 게시판의 게시글 관리
    public function posts(int $boardId): string
    {
        $board = $this->boardModel->find($boardId);
        $page  = (int) ($this->request->getGet('page') ?? 1);
        $list  = $this->postModel->getList($boardId, $page, 20);
        $total = $this->postModel->getTotalCount($boardId);

        return $this->render('admin/board/posts', [
            'board'       => $board,
            'posts'       => $list['posts'],
            'notices'     => $list['notices'],
            'currentPage' => $page,
            'totalPages'  => (int) ceil($total / 20),
        ]);
    }

    // 게시글 강제 삭제
    public function deletePost(int $postId): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->postModel->find($postId);
        if (! $post) {
            return redirect()->back()->with('error', '게시글을 찾을 수 없습니다.');
        }

        (new \App\Models\PostFileModel())->deleteByPost($postId);
        $this->postModel->delete($postId);

        return redirect()->back()->with('success', '삭제되었습니다.');
    }
}
