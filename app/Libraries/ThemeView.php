<?php

namespace App\Libraries;

use App\Models\SettingModel;
use CodeIgniter\View\View;

/**
 * 테마 경로 우선순위로 뷰를 해석하는 렌더러.
 *
 * 해석 순서: themes/{active}/{view} → themes/default/{view} → {view}
 * 이미 themes/ 경로로 들어온 요청은 재귀 방지를 위해 그대로 통과.
 */
class ThemeView extends View
{
    private ?string $resolvedTheme = null;

    private function activeTheme(): string
    {
        if ($this->resolvedTheme === null) {
            $this->resolvedTheme = (new SettingModel())->getAllAsMap()['active_theme'] ?? 'default';
        }
        return $this->resolvedTheme;
    }

    public function render(string $view, ?array $options = null, ?bool $saveData = null): string
    {
        // 이미 테마 경로로 들어온 경우 그대로 처리 (무한 루프 방지)
        if (str_starts_with($view, 'themes/')) {
            return parent::render($view, $options, $saveData);
        }

        $base  = rtrim($this->viewPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $theme = $this->activeTheme();

        // 1순위: 활성 테마 (default가 아닐 때만)
        if ($theme !== 'default') {
            $candidate = "themes/{$theme}/{$view}";
            if (is_file($base . str_replace('/', DIRECTORY_SEPARATOR, $candidate) . '.php')) {
                return parent::render($candidate, $options, $saveData);
            }
        }

        // 2순위: default 테마
        $candidate = "themes/default/{$view}";
        if (is_file($base . str_replace('/', DIRECTORY_SEPARATOR, $candidate) . '.php')) {
            return parent::render($candidate, $options, $saveData);
        }

        // 3순위: 원래 경로 (admin 뷰, 콘텐츠 뷰 등)
        return parent::render($view, $options, $saveData);
    }
}
