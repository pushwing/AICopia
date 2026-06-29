<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ImageUploader;
use App\Models\BannerModel;

class BannerController extends BaseController
{
    private BannerModel $bannerModel;

    public function __construct()
    {
        $this->bannerModel = new BannerModel();
    }

    public function index(): string
    {
        return $this->render('admin/banners/list', [
            'banners'   => $this->bannerModel->orderBy('position')->orderBy('priority')->findAll(),
            'positions' => BannerModel::POSITIONS,
        ]);
    }

    public function create(): string
    {
        return $this->render('admin/banners/form', [
            'banner'    => null,
            'positions' => BannerModel::POSITIONS,
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'position' => 'required|in_list[' . implode(',', array_keys(BannerModel::POSITIONS)) . ']',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $file = $this->request->getFile('image');

        if (! $file || ! $file->isValid()) {
            return redirect()->back()->withInput()->with('error', '배너 이미지를 선택해주세요.');
        }

        $result = (new ImageUploader('banners'))->upload($file);
        if (! $result['success']) {
            return redirect()->back()->withInput()->with('error', $result['error']);
        }

        $this->bannerModel->insert($this->collectData($result['path']));
        return redirect()->to('/admin/banners')->with('success', '배너가 등록되었습니다.');
    }

    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $banner = $this->bannerModel->find($id);
        if (! $banner) return redirect()->to('/admin/banners')->with('error', '배너를 찾을 수 없습니다.');

        return $this->render('admin/banners/form', [
            'banner'    => $banner,
            'positions' => BannerModel::POSITIONS,
        ]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $banner = $this->bannerModel->find($id);
        if (! $banner) return redirect()->to('/admin/banners')->with('error', '배너를 찾을 수 없습니다.');

        $imagePath = $banner['image_path'];

        $file = $this->request->getFile('image');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $result = (new ImageUploader('banners'))->upload($file);
            if (! $result['success']) {
                return redirect()->back()->withInput()->with('error', $result['error']);
            }
            $oldPath = FCPATH . $banner['image_path'];
            if (file_exists($oldPath)) unlink($oldPath);
            $imagePath = $result['path'];
        }

        $this->bannerModel->update($id, $this->collectData($imagePath));
        return redirect()->to('/admin/banners')->with('success', '저장되었습니다.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->bannerModel->deleteWithFile($id);
        return redirect()->to('/admin/banners')->with('success', '삭제되었습니다.');
    }

    private function collectData(string $imagePath): array
    {
        $toDatetime = fn($val) => $val
            ? (strlen($val) <= 16
                ? str_replace('T', ' ', $val) . ':00'
                : str_replace('T', ' ', substr($val, 0, 19)))
            : null;

        return [
            'image_path'  => $imagePath,
            'link_url'    => $this->request->getPost('link_url') ?: null,
            'link_target' => $this->request->getPost('link_target') ?: '_self',
            'position'    => $this->request->getPost('position'),
            'priority'    => (int) $this->request->getPost('priority'),
            'is_active'   => $this->request->getPost('is_active') ? 1 : 0,
            'started_at'  => $toDatetime($this->request->getPost('started_at')),
            'ended_at'    => $toDatetime($this->request->getPost('ended_at')),
        ];
    }
}
