<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\BoardHtmlSanitizer;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 게시글 본문 sanitizer 검증 (이슈 #117)
 *
 * 기존 구현은 정규식 블랙리스트라 아래 페이로드가 전부 무손실 통과했다.
 * 허용목록 파서로 교체해 "허용한 것만 남기고 나머지는 제거"로 뒤집는다.
 *
 * TinyMCE(lists link image table code)가 만들어내는 서식은 보존해야 한다.
 */
final class BoardContentSanitizerTest extends CIUnitTestCase
{
    // ── 차단: 기존 블랙리스트를 우회하던 페이로드 ────────────────────────────

    #[DataProvider('bypassPayloads')]
    public function testKnownBlacklistBypassesAreNeutralised(string $payload, string $label): void
    {
        $out = BoardHtmlSanitizer::sanitize($payload);

        // 이벤트 핸들러가 "속성으로" 남아 있으면 안 된다.
        // (문자열이 src 값 안으로 밀려 들어가 이스케이프된 경우는 실행되지 않으므로 통과)
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $out, $label);

        // 실행 가능한 태그는 통째로 사라져야 한다.
        foreach (['<script', '<iframe', '<object', '<embed', '<form'] as $tag) {
            $this->assertStringNotContainsStringIgnoringCase($tag, $out, $label);
        }

        // 스킴 기반 실행도 막혀야 한다.
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*"[^"]*javascript/i', $out, $label);
    }

    /** @return array<string, array{string, string}> */
    public static function bypassPayloads(): array
    {
        return [
            // on* 제거 정규식이 앞에 공백을 요구해서 / 구분자로 우회됐다
            'slash-separated onerror' => ['<img/src=x/onerror=alert(1)>', '슬래시 구분자 이벤트 핸들러'],
            'svg onload'              => ['<svg/onload=alert(1)>', 'svg onload'],
            // <script>...</script> 쌍 매칭을 요구해서 닫는 태그 없이 우회됐다
            'unclosed script'         => ['<script src=//evil.tld/x.js>', '닫는 태그 없는 script'],
            'script no close'         => ['<script>alert(1)//', '미종료 script'],
            // 단일 패스 제거라 중첩으로 재조합됐다
            'nested iframe'           => ['<if<iframe>rame src="x"></if<iframe>rame>', '중첩 iframe 재조합'],
            // 리터럴 문자열만 봐서 엔티티 인코딩으로 우회됐다
            'entity javascript uri'   => ['<a href="&#106;avascript:alert(1)">클릭</a>', '엔티티 인코딩 스킴'],
            'plain javascript uri'    => ['<a href="javascript:alert(1)">클릭</a>', 'javascript 스킴'],
            'object tag'              => ['<object data="x"></object>', 'object 태그'],
            'embed tag'               => ['<embed src="x">', 'embed 태그'],
            'form tag'                => ['<form action="/x"><input name="a"></form>', 'form 태그'],
        ];
    }

    public function testStyleBasedPayloadIsRemoved(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<p style="background:url(javascript:alert(1))">본문</p>');

        $this->assertStringNotContainsStringIgnoringCase('javascript', $out);
        $this->assertStringContainsString('본문', $out, '본문 텍스트까지 사라지면 안 된다');
    }

    public function testEventHandlerOnAllowedElementIsStripped(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<p onclick="alert(1)">본문</p>');

        $this->assertStringNotContainsStringIgnoringCase('onclick', $out);
        $this->assertStringContainsString('본문', $out);
    }

    // ── 보존: TinyMCE 서식은 살아 있어야 한다 ────────────────────────────────

    public function testBasicFormattingIsPreserved(): void
    {
        $html = '<p><strong>굵게</strong> <em>기울임</em> <u>밑줄</u></p>';

        $out = BoardHtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
        $this->assertStringContainsString('굵게', $out);
    }

    public function testListsArePreserved(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<ul><li>하나</li><li>둘</li></ul><ol><li>가</li></ol>');

        $this->assertStringContainsString('<ul>', $out);
        $this->assertStringContainsString('<li>', $out);
        $this->assertStringContainsString('<ol>', $out);
    }

    public function testTablesArePreserved(): void
    {
        $html = '<table><thead><tr><th>머리</th></tr></thead><tbody><tr><td>칸</td></tr></tbody></table>';

        $out = BoardHtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('<table>', $out);
        $this->assertStringContainsString('<td>', $out);
        $this->assertStringContainsString('칸', $out);
    }

    public function testHeadingsAndBreaksArePreserved(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<h2>제목</h2><p>줄<br>바꿈</p>');

        $this->assertStringContainsString('<h2>', $out);
        $this->assertStringContainsString('<br', $out);
    }

    public function testSafeLinkIsPreserved(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<a href="https://example.com">링크</a>');

        $this->assertStringContainsString('href="https://example.com"', $out);
        $this->assertStringContainsString('링크', $out);
    }

    public function testUploadedImageWithRelativePathIsPreserved(): void
    {
        // TinyMCE 이미지 업로드가 돌려주는 경로 (board/image-upload)
        $out = BoardHtmlSanitizer::sanitize('<img src="/uploads/board/images/2026/08/abc123.jpg" alt="사진">');

        $this->assertStringContainsString('/uploads/board/images/2026/08/abc123.jpg', $out);
    }

    public function testMailtoLinkIsPreserved(): void
    {
        $out = BoardHtmlSanitizer::sanitize('<a href="mailto:hi@example.com">메일</a>');

        // @ 는 &#64; 로 엔티티 인코딩될 수 있다 — 브라우저가 디코딩하므로 동작에는 문제없다
        $this->assertStringContainsString('mailto:', $out);
        $this->assertStringContainsString('메일', $out);
        $this->assertStringContainsString('example.com', $out);
    }

    // ── 경계값 ────────────────────────────────────────────────────────────────

    public function testNullAndEmptyInputReturnEmptyString(): void
    {
        $this->assertSame('', BoardHtmlSanitizer::sanitize(null));
        $this->assertSame('', BoardHtmlSanitizer::sanitize(''));
        $this->assertSame('', BoardHtmlSanitizer::sanitize('   '));
    }

    public function testPlainTextSurvivesUnchanged(): void
    {
        $this->assertStringContainsString('안녕하세요', BoardHtmlSanitizer::sanitize('안녕하세요'));
    }

    public function testSanitizingTwiceIsStable(): void
    {
        // 쓰기·렌더 양쪽에서 돌려도 결과가 흔들리면 안 된다
        $once  = BoardHtmlSanitizer::sanitize('<p><strong>굵게</strong> <a href="https://example.com">링크</a></p>');
        $twice = BoardHtmlSanitizer::sanitize($once);

        $this->assertSame($once, $twice);
    }
}
