<?php

declare(strict_types=1);

use App\Libraries\PG\KakaoPayAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 카카오페이 ready() 호출이 실패하면 buildPaymentParams() 는 'pg' 키 없이
 * ['error' => ...] 만 돌려주고 있었다. checkout.php 의 launchPG() 는 p.pg 값으로
 * 어떤 PG인지 분기하므로, 'pg' 키가 빠지면 카카오페이 전용 에러 처리
 * (`if (pg === 'kakaopay') { if (p.error) ... }`)로 가지 못하고 맨 끝의
 * "지원하지 않는 PG입니다" 문구로 떨어져 실제 원인을 가린다.
 *
 * 네트워크 호출 없이 검증하기 위해, ready 실패 시 반환값을 만드는 부분만
 * 떼어낸 메서드를 직접 확인한다(PgPayableAmountTest·PgCancelReturnsToOrderTest 와 동일한 방식).
 *
 * @internal
 */
final class KakaoPayErrorResponseTest extends CIUnitTestCase
{
    public function testReadyFailureResultIncludesPgKey(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyFailureResult');

        /** @var array<string, mixed> $result */
        $result = $invoker();

        $this->assertSame('kakaopay', $result['pg'] ?? null);
        $this->assertArrayHasKey('error', $result);
    }
}
