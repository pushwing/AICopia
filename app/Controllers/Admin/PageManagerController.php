<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PageModel;

class PageManagerController extends BaseController
{
    private PageModel $pageModel;

    public function __construct()
    {
        $this->pageModel = new PageModel();
    }

    public function index(): string
    {
        return $this->render('admin/pages/list', [
            'pages' => $this->pageModel->orderBy('sort_order')->findAll(),
        ]);
    }

    public function create(): string
    {
        return $this->render('admin/pages/form', ['page' => null]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'slug'  => 'required|alpha_dash|is_unique[pages.slug]',
            'title' => 'required',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->pageModel->insert($this->collectData());
        return redirect()->to('/admin/pages')->with('success', '페이지가 생성되었습니다.');
    }

    public function edit(int $id): string
    {
        return $this->render('admin/pages/form', ['page' => $this->pageModel->find($id)]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->pageModel->update($id, $this->collectData(isUpdate: true));
        return redirect()->to('/admin/pages')->with('success', '저장되었습니다.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->pageModel->delete($id);
        return redirect()->to('/admin/pages')->with('success', '삭제되었습니다.');
    }

    private function collectData(bool $isUpdate = false): array
    {
        $data = [
            'title'      => $this->request->getPost('title'),
            'content'    => $this->request->getPost('content'),
            'layout'     => $this->request->getPost('layout') ?: 'default',
            'meta_title' => $this->request->getPost('meta_title'),
            'meta_desc'  => $this->request->getPost('meta_desc'),
            'sort_order' => (int) $this->request->getPost('sort_order'),
            'status'     => $this->request->getPost('status') ?: 'published',
        ];
        if (! $isUpdate) {
            $data['slug'] = $this->request->getPost('slug');
        }
        return $data;
    }
}
