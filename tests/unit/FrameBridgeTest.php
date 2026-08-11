<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\FrameBridge;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 이니시스·나이스페이 결제 레이어(iframe) 안에서 세션이 비어 보일 때 쓰는
 * 탈출(bridge) 페이지 헬퍼 검증.
 *
 * @internal
 */
final class FrameBridgeTest extends CIUnitTestCase
{
    public function testIsFramedTrueWhenSecFetchDestIsIframe(): void
    {
        $request = service('request');
        $request->setHeader('Sec-Fetch-Dest', 'iframe');

        $this->assertTrue(FrameBridge::isFramed($request));
    }

    public function testIsFramedFalseWhenSecFetchDestIsDocument(): void
    {
        $request = service('request');
        $request->setHeader('Sec-Fetch-Dest', 'document');

        $this->assertFalse(FrameBridge::isFramed($request));
    }

    public function testIsFramedFalseWhenHeaderMissing(): void
    {
        $request = service('request');

        $this->assertFalse(FrameBridge::isFramed($request));
    }

    /** 최상위 창을 실제 목적지로 이동시키는 스크립트가 렌더돼야 한다. */
    public function testRenderContainsTopLevelRedirectToTargetUrl(): void
    {
        $html = FrameBridge::render('https://copia.aivance.kr/order/fail/ORD-1');

        $this->assertStringContainsString('top', $html);
        $this->assertStringContainsString('location.href', $html);
        $this->assertStringContainsString('https:\/\/copia.aivance.kr\/order\/fail\/ORD-1', $html);
    }

    /** 대상 URL에 스크립트를 깨뜨릴 수 있는 문자가 있어도 안전하게 이스케이프돼야 한다. */
    public function testRenderEscapesTargetUrlSafely(): void
    {
        $html = FrameBridge::render('https://copia.aivance.kr/order/fail/</script><script>alert(1)</script>');

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $html);
    }
}
