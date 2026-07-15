<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * 이슈 #72 — 한글 상품 슬러그 URL 접근 시 404(BadRequestException) 회귀 방지
 * CodeIgniter\Router\Router::checkDisallowedChars()가 실제로 쓰는 정규식과
 * 동일한 로직으로 App::$permittedURIChars 를 검증한다.
 */
final class ProductSlugKoreanUriTest extends CIUnitTestCase
{
    private function isPermittedSegment(string $segment): bool
    {
        $permittedURIChars = config(App::class)->permittedURIChars;

        return preg_match('/\A[' . $permittedURIChars . ']+\z/iu', $segment) === 1;
    }

    public function test_korean_slug_segment_is_permitted(): void
    {
        $this->assertTrue($this->isPermittedSegment('한글-상품-슬러그'));
    }

    public function test_ascii_slug_segment_is_still_permitted(): void
    {
        $this->assertTrue($this->isPermittedSegment('regular-ascii-slug-123'));
    }

    public function test_mixed_korean_and_ascii_slug_segment_is_permitted(): void
    {
        $this->assertTrue($this->isPermittedSegment('상품-product-99'));
    }

    public function test_disallowed_special_chars_are_still_rejected(): void
    {
        $this->assertFalse($this->isPermittedSegment('<script>alert(1)</script>'));
    }
}
