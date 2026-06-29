<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Mailer;
use App\Models\SettingModel;

class SettingController extends BaseController
{
    private SettingModel $settingModel;

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index(string $group = 'general'): string
    {
        if ($group === 'oauth') {
            return $this->render('admin/settings/oauth', [
                'group'    => 'oauth',
                'settings' => $this->settingModel->getAllAsMap(),
            ]);
        }

        if ($group === 'api') {
            return $this->render('admin/settings/api', [
                'group'    => 'api',
                'settings' => $this->settingModel->getAllAsMap(),
            ]);
        }

        if ($group === 'pg') {
            $pgList = [
                'toss'          => ['label' => '토스페이먼츠', 'desc' => '신용카드·간편결제', 'env' => ['TOSS_CLIENT_KEY', 'TOSS_SECRET_KEY']],
                'inicis'        => ['label' => 'KG이니시스',   'desc' => '신용카드·계좌이체', 'env' => ['INICIS_MERCHANT_ID', 'INICIS_SIGN_KEY']],
                'nicepay'       => ['label' => '나이스페이',    'desc' => '신용카드·계좌이체', 'env' => ['NICEPAY_CLIENT_ID', 'NICEPAY_SECRET_KEY']],
                'kakaopay'      => ['label' => '카카오페이',    'desc' => '간편결제',          'env' => ['KAKAOPAY_SECRET_KEY', 'KAKAOPAY_CID']],
                'naverpay'      => ['label' => '네이버페이',    'desc' => '간편결제',          'env' => ['NAVERPAY_CLIENT_ID', 'NAVERPAY_CLIENT_SECRET']],
                'payco'         => ['label' => 'PAYCO',         'desc' => '간편결제',          'env' => ['PAYCO_SELLER_KEY', 'PAYCO_SECRET_KEY']],
                'bank_transfer' => ['label' => '무통장입금',    'desc' => '계좌이체 확인 후 처리', 'env' => []],
            ];
            return $this->render('admin/settings/pg', [
                'group'        => 'pg',
                'pgList'       => $pgList,
                'settings'     => $this->settingModel->getAllAsMap(),
                'bankSettings' => array_filter(
                    $this->settingModel->getGroup('shop'),
                    fn($s) => in_array($s['key'], ['bank_name', 'bank_account', 'bank_holder'])
                ),
            ]);
        }

        if ($group === 'theme') {
            return $this->render('admin/settings/theme', [
                'group'         => 'theme',
                'activeTheme'   => $this->settingModel->getAllAsMap()['active_theme'] ?? 'default',
                'availableThemes' => $this->scanThemes(),
            ]);
        }

        $allowed = ['general', 'contact', 'sns', 'seo', 'footer', 'shop', 'grade'];
        if (! in_array($group, $allowed)) $group = 'general';

        return $this->render('admin/settings/index', [
            'group'    => $group,
            'settings' => $this->settingModel->getGroup($group),
        ]);
    }

    public function uploadTheme(): \CodeIgniter\HTTP\RedirectResponse
    {
        $file = $this->request->getFile('theme_zip');

        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', '파일 업로드에 실패했습니다.');
        }

        if (strtolower($file->getClientExtension()) !== 'zip') {
            return redirect()->back()->with('error', 'ZIP 파일만 업로드 가능합니다.');
        }

        // 파일명에서 테마 이름 추출 (영소문자·숫자·하이픈·언더스코어만 허용)
        $themeName = strtolower(pathinfo($file->getClientName(), PATHINFO_FILENAME));
        $themeName = trim(preg_replace('/[^a-z0-9\-_]+/', '-', $themeName), '-_');

        if (empty($themeName) || $themeName === 'default') {
            return redirect()->back()->with('error', "'{$themeName}'은 사용할 수 없는 테마 이름입니다.");
        }

        // ZIP 열기
        $zip = new \ZipArchive();
        if ($zip->open($file->getTempName()) !== true) {
            return redirect()->back()->with('error', 'ZIP 파일을 열 수 없습니다.');
        }

        // 필수 파일 및 보안 검사
        $required       = ['views/layouts/main.php', 'public/css/style.css'];
        $allowedViewExt = ['php'];
        $allowedPubExt  = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
                           'woff', 'woff2', 'ttf', 'eot', 'ico', 'map', 'json'];
        $found = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            // Zip-slip 방지
            if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_starts_with($entry, '\\')) {
                $zip->close();
                return redirect()->back()->with('error', "보안 위험: 잘못된 경로 포함 ({$entry})");
            }

            if (str_ends_with($entry, '/')) continue; // 디렉토리 엔트리 skip

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (str_starts_with($entry, 'views/')) {
                if (! in_array($ext, $allowedViewExt)) {
                    $zip->close();
                    return redirect()->back()->with('error', "views/ 내 허용되지 않는 파일 형식: {$entry}");
                }
            } elseif (str_starts_with($entry, 'public/')) {
                if (! in_array($ext, $allowedPubExt)) {
                    $zip->close();
                    return redirect()->back()->with('error', "public/ 내 허용되지 않는 파일 형식: {$entry}");
                }
            }
            // views/, public/ 외 파일은 무시

            if (in_array($entry, $required)) {
                $found[] = $entry;
            }
        }

        $missing = array_diff($required, $found);
        if (! empty($missing)) {
            $zip->close();
            return redirect()->back()->with('error', '필수 파일 누락: ' . implode(', ', $missing));
        }

        // 임시 디렉토리에 압축 해제
        $tempDir = rtrim(WRITEPATH, '/') . '/themes_tmp/' . uniqid('theme_', true);
        if (! mkdir($tempDir, 0755, true)) {
            $zip->close();
            return redirect()->back()->with('error', '임시 디렉토리 생성 실패');
        }

        $zip->extractTo($tempDir);
        $zip->close();

        $isUpdate = is_dir(APPPATH . "Views/themes/{$themeName}");

        try {
            if (is_dir("{$tempDir}/views")) {
                $this->copyDirectory("{$tempDir}/views", APPPATH . "Views/themes/{$themeName}");
            }
            if (is_dir("{$tempDir}/public")) {
                $this->copyDirectory("{$tempDir}/public", FCPATH . "themes/{$themeName}");
            }
        } catch (\Throwable $e) {
            $this->deleteDirectory($tempDir);
            return redirect()->back()->with('error', '파일 복사 실패: ' . $e->getMessage());
        }

        $this->deleteDirectory($tempDir);

        $action = $isUpdate ? '업데이트' : '설치';
        return redirect()->to('/admin/settings/theme')
                         ->with('success', "테마 '{$themeName}'이(가) {$action}되었습니다.");
    }

    private function copyDirectory(string $src, string $dest): void
    {
        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $target = $dest . '/' . substr($item->getPathname(), strlen($src) + 1);
            $item->isDir() ? (is_dir($target) ?: mkdir($target, 0755, true))
                           : copy($item->getPathname(), $target);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function update(string $group = 'general'): \CodeIgniter\HTTP\RedirectResponse
    {
        $postData = $this->request->getPost();
        unset($postData[csrf_token()]);

        if ($group === 'pg') {
            // 체크박스: POST에 없으면 0
            $pgKeys = ['toss', 'inicis', 'nicepay', 'kakaopay', 'naverpay', 'payco', 'bank_transfer'];
            $save = [];
            foreach ($pgKeys as $key) {
                $save["pg_enabled_{$key}"] = isset($postData["pg_enabled_{$key}"]) ? '1' : '0';
            }
            // 무통장 계좌 정보도 같이 저장
            foreach (['bank_name', 'bank_account', 'bank_holder'] as $bk) {
                if (array_key_exists($bk, $postData)) {
                    $save[$bk] = $postData[$bk];
                }
            }
            $this->settingModel->saveSettings($save);
            return redirect()->to('/admin/settings/pg')->with('success', '결제수단 설정이 저장되었습니다.');
        }

        if ($group === 'oauth') {
            $oauthKeys = ['naver', 'kakao', 'google'];
            $save = [];
            foreach ($oauthKeys as $key) {
                $save["oauth_enabled_{$key}"] = isset($postData["oauth_enabled_{$key}"]) ? '1' : '0';
            }
            $this->settingModel->saveSettings($save);
            return redirect()->to('/admin/settings/oauth')->with('success', '소셜 로그인 설정이 저장되었습니다.');
        }

        if ($group === 'api') {
            $save = [
                'ai_provider'                  => in_array($postData['ai_provider'] ?? '', ['groq', 'claude'], true)
                    ? $postData['ai_provider'] : 'groq',
                'groq_api_key'                 => trim($postData['groq_api_key'] ?? ''),
                'anthropic_api_key'            => trim($postData['anthropic_api_key'] ?? ''),
                'naver_shopping_client_id'     => trim($postData['naver_shopping_client_id'] ?? ''),
                'naver_shopping_client_secret' => trim($postData['naver_shopping_client_secret'] ?? ''),
            ];
            // AI 프롬프트: 입력값이 있으면 저장, 비우면 코드 기본값으로 폴백
            foreach (\App\Libraries\AiProvider\AiPrompts::KEYS as $key) {
                $save[\App\Libraries\AiProvider\AiPrompts::PREFIX . $key] = trim($postData['ai_prompt_' . $key] ?? '');
            }
            $this->settingModel->saveSettings($save);
            return redirect()->to('/admin/settings/api')->with('success', '외부 API 설정이 저장되었습니다.');
        }

        if ($group === 'theme') {
            $theme = $postData['active_theme'] ?? 'default';
            $this->settingModel->saveSettings(['active_theme' => $theme]);
            return redirect()->to('/admin/settings/theme')->with('success', "테마가 '{$theme}'으로 변경되었습니다.");
        }

        // carriers 타입: 배열 입력 → JSON 배열로 변환
        if (array_key_exists('shipping_carriers', $postData)) {
            $raw      = $postData['shipping_carriers'];
            $carriers = is_array($raw) ? $raw : [];
            $carriers = array_values(array_filter(array_map('trim', $carriers), fn ($c) => $c !== ''));
            $postData['shipping_carriers'] = json_encode($carriers, JSON_UNESCAPED_UNICODE);
        }

        $this->settingModel->saveSettings($postData);

        return redirect()->to("/admin/settings/{$group}")->with('success', '설정이 저장되었습니다.');
    }

    /** POST /admin/settings/smtp-test — SMTP 테스트 메일 발송 */
    public function smtpTest(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings = $this->settingModel->getAllAsMap();
        $to       = trim($this->request->getPost('to') ?? '');
        if ($to === '') {
            $to = ($settings['smtp_from'] ?? '') ?: ($settings['email'] ?? '');
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => '유효한 수신 이메일 주소를 입력하세요.',
            ]);
        }

        try {
            (new Mailer($settings))->sendSmtpTest($to);
            return $this->response->setJSON([
                'success' => true,
                'message' => "{$to} 으로 테스트 메일을 발송했습니다.",
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'SMTP 오류: ' . $e->getMessage(),
            ]);
        }
    }

    /** app/Views/themes/ 하위 폴더를 스캔해 테마 목록 반환 */
    private function scanThemes(): array
    {
        $base   = APPPATH . 'Views/themes/';
        $themes = [];

        if (! is_dir($base)) {
            return $themes;
        }

        foreach (new \DirectoryIterator($base) as $item) {
            if ($item->isDir() && ! $item->isDot()) {
                $name = $item->getFilename();
                $themes[$name] = [
                    'name'      => $name,
                    'label'     => ucfirst($name),
                    'has_css'   => is_file(FCPATH . "themes/{$name}/css/style.css"),
                    'has_layout'=> is_file(APPPATH . "Views/themes/{$name}/layouts/main.php"),
                ];
            }
        }

        ksort($themes);
        return $themes;
    }
}
