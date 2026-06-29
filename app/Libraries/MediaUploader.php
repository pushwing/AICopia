<?php

namespace App\Libraries;

use App\Models\MediaModel;
use CodeIgniter\HTTP\Files\UploadedFile;

class MediaUploader
{
    private const ALLOWED       = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    private const MAX_SIZE      = 5 * 1024 * 1024; // 5MB
    private const MAX_DIMENSION = 1200;             // 리사이즈 기준 (px)

    private MediaModel $model;

    public function __construct()
    {
        $this->model = new MediaModel();
    }

    public function upload(UploadedFile $file, string $alt = ''): array
    {
        $ext = strtolower($file->getClientExtension());

        if (! in_array($ext, self::ALLOWED)) {
            return ['success' => false, 'error' => '이미지 파일만 업로드 가능합니다.'];
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return ['success' => false, 'error' => '파일 크기는 5MB 이하여야 합니다.'];
        }

        $subDir     = date('Y/m');
        $uploadPath = FCPATH . "uploads/media/{$subDir}";

        if (! is_dir($uploadPath) && ! mkdir($uploadPath, 0755, true)) {
            return ['success' => false, 'error' => '업로드 디렉토리를 생성할 수 없습니다.'];
        }

        $storedName   = bin2hex(random_bytes(16)) . '.' . $ext;
        $relativePath = "uploads/media/{$subDir}/{$storedName}";

        if (! $file->move($uploadPath, $storedName)) {
            return ['success' => false, 'error' => '파일 이동에 실패했습니다.'];
        }

        $fullPath = $uploadPath . '/' . $storedName;
        $this->resizeIfNeeded($fullPath, $ext);

        $id = $this->model->insert([
            'original_name' => $file->getName(),
            'stored_name'   => $storedName,
            'file_path'     => $relativePath,
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getClientMimeType(),
            'alt'           => $alt,
        ]);

        return [
            'success' => true,
            'id'      => $id,
            'path'    => '/' . $relativePath,
            'url'     => base_url($relativePath),
        ];
    }

    // GIF(애니메이션)·SVG(벡터)를 제외하고 MAX_DIMENSION 초과 시 비율 유지하며 축소
    private function resizeIfNeeded(string $fullPath, string $ext): void
    {
        if (in_array($ext, ['gif', 'svg'])) return;

        [$width, $height] = getimagesize($fullPath) ?: [0, 0];

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) return;

        $masterDim = $width >= $height ? 'width' : 'height';

        \Config\Services::image()
            ->withFile($fullPath)
            ->resize(self::MAX_DIMENSION, self::MAX_DIMENSION, true, $masterDim)
            ->save($fullPath);
    }
}
