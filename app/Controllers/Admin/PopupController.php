<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ImageUploader;
use App\Models\MenuModel;
use App\Models\PopupModel;

class PopupController extends BaseController
{
    private PopupModel $popupModel;

    public function __construct()
    {
        $this->popupModel = new PopupModel();
    }

    public function index(): string
    {
        return $this->render('admin/popups/list', [
            'popups' => $this->popupModel->orderBy('priority')->findAll(),
            'scopes' => PopupModel::SCOPES,
        ]);
    }

    public function create(): string
    {
        return $this->render('admin/popups/form', [
            'popup'    => null,
            'scopes'   => PopupModel::SCOPES,
            'allMenus' => (new MenuModel())->where('is_active', 1)->orderBy('sort_order')->findAll(),
            'pageIds'  => [],
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = ['title' => 'required|max_length[200]'];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $imagePath = null;
        $file      = $this->request->getFile('image');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $result = (new ImageUploader('popups'))->upload($file);
            if (! $result['success']) {
                return redirect()->back()->withInput()->with('error', $result['error']);
            }
            $imagePath = $result['path'];
        }

        $id = $this->popupModel->insert($this->collectData($imagePath));
        if (! $id) {
            return redirect()->back()->withInput()->with('error', '팝업 저장에 실패했습니다.');
        }
        $this->popupModel->syncPages((int) $id, $this->request->getPost('page_ids') ?? []);

        return redirect()->to('/admin/popups')->with('success', '팝업이 등록되었습니다.');
    }

    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $popup = $this->popupModel->find($id);
        if (! $popup) return redirect()->to('/admin/popups')->with('error', '팝업을 찾을 수 없습니다.');

        return $this->render('admin/popups/form', [
            'popup'    => $popup,
            'scopes'   => PopupModel::SCOPES,
            'allMenus' => (new MenuModel())->where('is_active', 1)->orderBy('sort_order')->findAll(),
            'pageIds'  => $this->popupModel->getPageIds($id),
        ]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $popup = $this->popupModel->find($id);
        if (! $popup) return redirect()->to('/admin/popups')->with('error', '팝업을 찾을 수 없습니다.');

        $rules = ['title' => 'required|max_length[200]'];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $imagePath = $popup['image_path'];
        $file      = $this->request->getFile('image');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $result = (new ImageUploader('popups'))->upload($file);
            if (! $result['success']) {
                return redirect()->back()->withInput()->with('error', $result['error']);
            }
            if ($imagePath) {
                $oldPath = FCPATH . $imagePath;
                if (file_exists($oldPath)) unlink($oldPath);
            }
            $imagePath = $result['path'];
        }

        $this->popupModel->update($id, $this->collectData($imagePath));
        $this->popupModel->syncPages($id, $this->request->getPost('page_ids') ?? []);

        return redirect()->to('/admin/popups')->with('success', '저장되었습니다.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->popupModel->deleteWithFile($id);
        return redirect()->to('/admin/popups')->with('success', '삭제되었습니다.');
    }

    private function collectData(?string $imagePath): array
    {
        $toDatetime = fn($val) => $val
            ? (strlen($val) <= 16
                ? str_replace('T', ' ', $val) . ':00'
                : str_replace('T', ' ', substr($val, 0, 19)))
            : null;

        return [
            'title'      => $this->request->getPost('title'),
            'image_path' => $imagePath,
            'content'    => $this->request->getPost('content') ?: null,
            'show_scope' => $this->request->getPost('show_scope') ?: 'all',
            'pos_x'      => (int) ($this->request->getPost('pos_x') ?? 20),
            'pos_y'      => (int) ($this->request->getPost('pos_y') ?? 20),
            'priority'   => (int) ($this->request->getPost('priority') ?? 0),
            'is_active'  => $this->request->getPost('is_active') ? 1 : 0,
            'started_at' => $toDatetime($this->request->getPost('started_at')),
            'ended_at'   => $toDatetime($this->request->getPost('ended_at')),
        ];
    }
}
