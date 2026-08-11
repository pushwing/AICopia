<?php

declare(strict_types=1);

use App\Libraries\PG\KakaoPayAdapter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\PG;

/**
 * 카카오페이 ready() 호출이 실패하면 buildPaymentParams() 는 'pg' 키 없이
 * ['error' => ...] 만 돌려주고 있었다. checkout.php 의 launchPG() 는 p.pg 값으로
 * 어떤 PG인지 분기하므로, 'pg' 키가 빠지면 카카오페이 전용 에러 처리
 * (`if (pg === 'kakaopay') { if (p.error) ... }`)로 가지 못하고 맨 끝의
 * "지원하지 않는 PG입니다" 문구로 떨어져 실제 원인을 가린다.
 *
 * 'pg' 키를 고쳐 실제 원인이 보이게 되자, 시크릿 키 미설정 같은 흔한 설정
 * 실수도 전부 "카카오페이 결제 준비 실패"라는 뭉뚱그린 문구로만 나타났다.
 * 토스 어댑터(TossPaymentsAdapter::validateKeys())와 동일하게 네트워크를 타기
 * 전에 키 존재를 먼저 검증하고, 카카오 API 가 실패 사유(msg)를 내려주면
 * 그대로 노출해 원인을 바로 알 수 있게 한다.
 *
 * 네트워크 호출 없이 검증하기 위해, ready 실패 시 반환값을 만드는 부분·키 검증
 * 부분만 떼어낸 메서드를 직접 확인한다(PgPayableAmountTest 와 동일한 방식).
 *
 * @internal
 */
final class KakaoPayErrorResponseTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    public function testReadyFailureResultIncludesPgKey(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyFailureResult');

        /** @var array<string, mixed> $result */
        $result = $invoker();

        $this->assertSame('kakaopay', $result['pg'] ?? null);
        $this->assertArrayHasKey('error', $result);
    }

    /** 카카오 API 가 실패 사유(msg)를 내려주면 뭉뚱그린 기본 문구 대신 그대로 노출한다. */
    public function testReadyFailureResultPrefersApiMessageWhenGiven(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyFailureResult');

        /** @var array<string, mixed> $result */
        $result = $invoker('등록되지 않은 cid 입니다.');

        $this->assertSame('등록되지 않은 cid 입니다.', $result['error']);
    }

    /** 시크릿 키가 비어 있으면 네트워크를 타기 전에 설정 문제를 바로 알려준다. */
    public function testMissingSecretKeyReturnsActionableError(): void
    {
        $config                 = new PG();
        $config->kakaoSecretKey = '';
        Factories::injectMock('config', 'PG', $config);

        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'validateKeys');

        $this->assertSame(
            '카카오페이 시크릿 키가 설정되지 않았습니다. (.env 의 KAKAOPAY_SECRET_KEY)',
            $invoker()
        );
    }

    public function testConfiguredSecretKeyPassesValidation(): void
    {
        $config                 = new PG();
        $config->kakaoSecretKey = 'dummy_secret_key';
        Factories::injectMock('config', 'PG', $config);

        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'validateKeys');

        $this->assertNull($invoker());
    }
}
