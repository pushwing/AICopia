<?php

namespace App\Libraries;

use App\Models\PostFileModel;
use CodeIgniter\HTTP\Files\UploadedFile;

class FileUploader
{
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const ALLOWED_EXTS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'txt', 'hwp',
    ];
    // 확장자 → 허용 MIME 매핑 (서버사이드 검증용)
    private const EXT_MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'txt'  => ['text/plain'],
        'hwp'  => ['application/x-hwp', 'application/haansofthwp', 'application/octet-stream'],
    ];
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    private PostFileModel $fileModel;

    public function __construct()
    {
        $this->fileModel = new PostFileModel();
    }

    /**
     * 파일 유효성 사전 검증 (DB/디스크 저장 없음)
     * @return string[] 에러 메시지 배열 (비어 있으면 통과)
     */
    public function validateFiles(array $uploadedFiles): array
    {
        $errors = [];

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (! $file->isValid()) {
                $errors[] = $file->getName() . ': ' . $this->uploadErrorMessage($file->getError());
                continue;
            }

            $ext = strtolower($file->getClientExtension());
            if (! in_array($ext, self::ALLOWED_EXTS)) {
                $errors[] = $file->getName() . ': 허용되지 않는 파일 형식';
                continue;
            }
            if ($file->getSize() > self::MAX_SIZE) {
                $errors[] = $file->getName() . ': 파일 크기 초과 (최대 10MB)';
            }
        }

        return $errors;
    }

    /**
     * 게시글의 첨부파일 일괄 저장
     * @return array ['saved' => int, 'errors' => array]
     */
    public function savePostFiles(int $postId, array $uploadedFiles): array
    {
        $saved  = 0;
        $errors = [];

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            // 파일 미선택은 건너뜀
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if (! $file->isValid()) {
                $errors[] = $file->getName() . ': ' . $this->uploadErrorMessage($file->getError());
                continue;
            }

            $result = $this->saveFile($postId, $file);
            if ($result['success']) {
                $saved++;
            } else {
                $errors[] = $result['error'];
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE  => '파일 크기가 너무 큽니다.',
            UPLOAD_ERR_PARTIAL    => '파일이 불완전하게 업로드되었습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '임시 디렉터리가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '파일 저장에 실패했습니다.',
            default               => '업로드 오류가 발생했습니다.',
        };
    }

    private function saveFile(int $postId, UploadedFile $file): array
    {
        $ext  = strtolower($file->getClientExtension());
        $mime = $file->getMimeType(); // 서버사이드 MIME (클라이언트 헤더 무시)

        if (! in_array($ext, self::ALLOWED_EXTS)) {
            return ['success' => false, 'error' => "{$file->getName()}: 허용되지 않는 파일 형식"];
        }

        $allowedMimes = self::EXT_MIME_MAP[$ext] ?? [];
        if (! empty($allowedMimes) && ! in_array($mime, $allowedMimes, true)) {
            return ['success' => false, 'error' => "{$file->getName()}: 파일 형식이 올바르지 않습니다."];
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return ['success' => false, 'error' => "{$file->getName()}: 파일 크기 초과 (최대 10MB)"];
        }

        $isImage    = in_array($ext, self::IMAGE_EXTS);
        $subDir     = $isImage ? 'images' : 'files';
        $uploadPath = FCPATH . "uploads/board/{$subDir}/" . date('Y/m');

        if (! is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $file->move($uploadPath, $storedName);

        $relativePath = "uploads/board/{$subDir}/" . date('Y/m') . "/{$storedName}";

        $this->fileModel->insert([
            'post_id'       => $postId,
            'original_name' => $file->getName(),
            'stored_name'   => $storedName,
            'file_path'     => $relativePath,
            'file_size'     => $file->getSize(),
            'mime_type'     => $mime,
            'is_image'      => $isImage ? 1 : 0,
        ]);

        return ['success' => true];
    }

    public function deleteFile(array $fileRecord): void
    {
        $fullPath = FCPATH . $fileRecord['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->fileModel->delete($fileRecord['id']);
    }
}
