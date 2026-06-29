<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 최근 본 상품 쿠키 배열 조작 로직 단위 테스트
 * 이슈 #55
 *
 * ShopController::detail() 의 recently_viewed 쿠키 처리 로직을
 * 순수 배열 연산으로 검증한다.
 */
final class RecentlyViewedTest extends CIUnitTestCase
{
    /**
     * 쿠키 값과 현재 슬러그를 받아 업데이트된 배열을 반환 (컨트롤러 로직과 동일)
     */
    private function applyRecentlyViewed(string $cookieJson, string $slug): array
    {
        $viewed = json_decode($cookieJson, true);
        if (! is_array($viewed)) {
            $viewed = [];
        }
        $viewed = array_values(array_filter($viewed, fn ($s) => $s !== $slug));
        array_unshift($viewed, $slug);
        return array_slice($viewed, 0, 11);
    }

    // ── 기본 동작 ─────────────────────────────────────────────────────────────

    public function testFirstVisitAddsSlugToEmptyCookie(): void
    {
        $result = $this->applyRecentlyViewed('[]', 'product-a');

        $this->assertSame(['product-a'], $result);
    }

    public function testNewSlugIsPrependedToExistingList(): void
    {
        $result = $this->applyRecentlyViewed('["product-a","product-b"]', 'product-c');

        $this->assertSame('product-c', $result[0], '새 상품이 맨 앞에 위치해야 한다');
        $this->assertSame(['product-c', 'product-a', 'product-b'], $result);
    }

    public function testDuplicateSlugIsMovedToFront(): void
    {
        $cookie = '["product-a","product-b","product-c"]';
        $result = $this->applyRecentlyViewed($cookie, 'product-b');

        $this->assertSame('product-b', $result[0]);
        $this->assertCount(3, $result, '중복 제거 후 개수가 유지되어야 한다');
        $this->assertNotContains('product-b', array_slice($result, 1), '앞 이외 위치에 중복이 없어야 한다');
    }

    // ── 최대 11개 제한 ────────────────────────────────────────────────────────

    public function testListIsCappedAtEleven(): void
    {
        $existing = array_map(fn ($i) => "product-$i", range(1, 11));
        $cookie   = json_encode($existing);

        $result = $this->applyRecentlyViewed($cookie, 'product-new');

        $this->assertCount(11, $result, '최대 11개를 넘으면 안 된다');
        $this->assertSame('product-new', $result[0]);
        $this->assertNotContains('product-11', $result, '가장 오래된 항목이 밀려나야 한다');
    }

    public function testExactlyElevenSlotsAllowed(): void
    {
        $existing = array_map(fn ($i) => "product-$i", range(1, 10));
        $cookie   = json_encode($existing);

        $result = $this->applyRecentlyViewed($cookie, 'product-new');

        $this->assertCount(11, $result);
    }

    // ── 표시 목록 생성 (현재 상품 제외, 최대 10개) ────────────────────────────

    public function testDisplayListExcludesCurrentSlug(): void
    {
        $stored  = ['current-product', 'product-a', 'product-b'];
        $current = 'current-product';

        $display = array_values(array_filter($stored, fn ($s) => $s !== $current));

        $this->assertNotContains($current, $display, '현재 상품이 목록에 포함되면 안 된다');
        $this->assertSame(['product-a', 'product-b'], $display);
    }

    public function testDisplayListIsLimitedToTen(): void
    {
        $stored  = array_map(fn ($i) => "product-$i", range(0, 10)); // 11개
        $current = 'product-0';

        $display = array_slice(
            array_values(array_filter($stored, fn ($s) => $s !== $current)),
            0,
            10
        );

        $this->assertCount(10, $display, '현재 상품 제외 후 최대 10개만 표시되어야 한다');
        $this->assertNotContains($current, $display);
    }

    // ── 잘못된 쿠키 값 처리 ───────────────────────────────────────────────────

    public function testInvalidCookieJsonFallsBackToEmpty(): void
    {
        $result = $this->applyRecentlyViewed('NOT_VALID_JSON', 'product-a');

        $this->assertSame(['product-a'], $result, '잘못된 JSON은 빈 배열로 초기화되어야 한다');
    }

    public function testNonArrayCookieFallsBackToEmpty(): void
    {
        $result = $this->applyRecentlyViewed('"just-a-string"', 'product-a');

        $this->assertSame(['product-a'], $result, '배열이 아닌 JSON 값은 빈 배열로 초기화되어야 한다');
    }

    public function testEmptyStringCookieTreatedAsEmpty(): void
    {
        $viewed = json_decode('', true);
        if (! is_array($viewed)) {
            $viewed = [];
        }
        array_unshift($viewed, 'product-x');
        $viewed = array_slice($viewed, 0, 11);

        $this->assertSame(['product-x'], $viewed);
    }
}
