<?php

namespace App\Libraries;

/**
 * 페이지별 메타태그/OG태그 생성
 */
class SeoHelper
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function render(?array $page = null): string
    {
        $siteName = $this->settings['site_name'] ?? '';
        $title    = $page['meta_title'] ?? ($page['title'] ?? $siteName);
        $desc     = $page['meta_desc'] ?? ($this->settings['site_desc'] ?? '');
        $ogImage  = $page['og_image']  ?? ($this->settings['site_logo'] ?? '');
        $url      = current_url();

        $html  = "<title>" . esc($title) . "</title>\n";
        $html .= "<meta name=\"description\" content=\"" . esc($desc) . "\">\n";
        $html .= "<meta property=\"og:title\" content=\"" . esc($title) . "\">\n";
        $html .= "<meta property=\"og:description\" content=\"" . esc($desc) . "\">\n";
        $html .= "<meta property=\"og:url\" content=\"" . esc($url) . "\">\n";
        $html .= "<meta property=\"og:site_name\" content=\"" . esc($siteName) . "\">\n";
        $html .= "<meta property=\"og:type\" content=\"website\">\n";

        if ($ogImage) {
            $html .= "<meta property=\"og:image\" content=\"" . esc(base_url($ogImage)) . "\">\n";
        }

        // 네이버 웹마스터 인증
        if (! empty($this->settings['naver_verify'])) {
            $html .= "<meta name=\"naver-site-verification\" content=\"" . esc($this->settings['naver_verify']) . "\">\n";
        }

        return $html;
    }

    /**
     * Google Analytics 스크립트
     */
    public function gaScript(): string
    {
        $gaId = $this->settings['ga_id'] ?? '';
        if (! $gaId) return '';

        return <<<HTML
<script async src="https://www.googletagmanager.com/gtag/js?id={$gaId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$gaId}');
</script>
HTML;
    }
}
