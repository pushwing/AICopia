<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

class ImageUploader
{
    private const ALLOWED       = ['jpg', 'jpeg', 'png', 'gif'];
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif'];
    private const MAX_SIZE      = 2 * 1024 * 1024; // 2MB
    private const MAX_DIMENSION = 1200;             // 리사이즈 기준 (px)

    public function __construct(private string $folder) {}

    public function upload(UploadedFile $file): array
    {
        $ext  = strtolower($file->getClientExtension());
        $mime = $file->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIMES) || ! in_array($ext, self::ALLOWED)) {
            return ['success' => false, 'error' => 'jpg, jpeg, png, gif 파일만 업로드 가능합니다.'];
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return ['success' => false, 'error' => '파일 크기는 2MB 이하여야 합니다.'];
        }

        $subDir     = date('Y/m');
        $uploadPath = FCPATH . "uploads/{$this->folder}/{$subDir}";

        if (! is_dir($uploadPath)) mkdir($uploadPath, 0755, true);

        $storedName   = bin2hex(random_bytes(16)) . '.' . $ext;
        $relativePath = "uploads/{$this->folder}/{$subDir}/{$storedName}";
        $fullPath     = $uploadPath . '/' . $storedName;

        $file->move($uploadPath, $storedName);

        $this->resizeIfNeeded($fullPath, $ext);

        return ['success' => true, 'path' => $relativePath];
    }

    // 가로 또는 세로가 MAX_DIMENSION 초과 시 비율 유지하며 축소
    private function resizeIfNeeded(string $fullPath, string $ext): void
    {
        // GIF는 애니메이션 손상 방지를 위해 리사이즈 건너뜀
        if ($ext === 'gif') return;

        [$width, $height] = getimagesize($fullPath) ?: [0, 0];

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) return;

        $masterDim = $width >= $height ? 'width' : 'height';

        \Config\Services::image()
            ->withFile($fullPath)
            ->resize(self::MAX_DIMENSION, self::MAX_DIMENSION, true, $masterDim)
            ->save($fullPath);
    }
}
