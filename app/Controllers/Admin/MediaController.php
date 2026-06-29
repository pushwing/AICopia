<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\MediaUploader;
use App\Models\MediaModel;

class MediaController extends BaseController
{
    private MediaModel    $mediaModel;
    private MediaUploader $uploader;

    public function __construct()
    {
        $this->mediaModel = new MediaModel();
        $this->uploader   = new MediaUploader();
    }

    public function index(): string
    {
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $limit  = 24;
        $offset = ($page - 1) * $limit;
        $total  = $this->mediaModel->countAll();

        return $this->render('admin/media/index', [
            'mediaList'  => $this->mediaModel->getList($limit, $offset),
            'totalPages' => (int) ceil($total / $limit),
            'currentPage'=> $page,
        ]);
    }

    public function upload(): \CodeIgniter\HTTP\ResponseInterface
    {
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->response->setJSON(['success' => false, 'error' => '파일 없음']);
        }

        $alt    = $this->request->getPost('alt') ?? '';
        $result = $this->uploader->upload($file, $alt);

        // TinyMCE images_upload_url 응답 형식: { location: "url" }
        if ($result['success']) {
            $result['location'] = $result['url'];
        }

        return $this->response->setJSON($result);
    }

    public function updateAlt(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->mediaModel->update($id, ['alt' => $this->request->getPost('alt')]);
        return $this->response->setJSON(['success' => true]);
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->mediaModel->deleteWithFile($id);
        return redirect()->to('/admin/media')->with('success', '삭제되었습니다.');
    }
}
