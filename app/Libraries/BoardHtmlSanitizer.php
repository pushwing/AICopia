<?php

declare(strict_types=1);

namespace App\Libraries;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * 게시글 본문 HTML 정화 — 허용목록 방식.
 *
 * 이전에는 정규식 블랙리스트로 위험 패턴을 지웠는데, 블랙리스트는 구조적으로
 * 우회가 가능했다(속성 구분자로 쓰이는 `/`, 닫는 태그 없는 `<script>`,
 * 엔티티 인코딩한 `javascript:`, 중첩 태그 재조합 등). 허용한 것만 남기고
 * 나머지를 전부 버리는 파서 기반 정화로 뒤집는다. (이슈 #117)
 *
 * 허용 범위는 게시판 에디터(TinyMCE — lists·link·image·table)가 실제로
 * 만들어내는 서식에 맞췄다.
 */
final class BoardHtmlSanitizer
{
    /** 본문 최대 길이 — 파서에 넘기기 전 방어선 */
    private const int MAX_INPUT_LENGTH = 500_000;

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * 저장·출력 양쪽에서 호출한다.
     *
     * 쓰기 시점에만 정화하면 이미 저장된 행이 그대로 남고, 렌더 시점에만
     * 정화하면 DB 에 위험한 원본이 쌓인다. 두 번 돌려도 결과가 같도록
     * (멱등) 구성했으므로 양쪽에서 호출해도 안전하다.
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(self::config());
    }

    private static function config(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig()
            // W3C 기준 안전 요소 집합에서 출발한다.
            ->allowSafeElements()
            // 링크·이미지는 안전한 스킴만. data: 는 허용하지 않는다.
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            // 업로드된 첨부 이미지는 /uploads/... 상대경로로 들어온다.
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        // 에디터가 실제로 쓰는 서식 요소를 명시적으로 허용한다.
        foreach (['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'pre', 'code', 'hr'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['ul', 'ol', 'li'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['table', 'thead', 'tbody', 'tfoot', 'tr'] as $tag) {
            $config = $config->allowElement($tag);
        }
        foreach (['th', 'td'] as $tag) {
            $config = $config->allowElement($tag, ['colspan', 'rowspan']);
        }

        return $config
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            // 외부 링크는 새 창 + 참조 차단으로 고정한다(탭내빙 방지).
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            // style 은 통째로 버린다 — url(javascript:...) 등 우회 표면이 넓다.
            ->dropAttribute('style', '*')
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('textarea')
            ->dropElement('select');
    }
}
