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

    /**
     * curl 자체가 실패하면(DNS·SSL·타임아웃 등) 카카오 응답 본문이 없어 msg 도 없다.
     * 지금까지는 이 경우와 "카카오가 정상 응답했지만 msg 없이 실패"를 구분하지 못해
     * 둘 다 똑같은 뭉뚱그린 문구로 보였다 — 존재하지 않는 호스트로 강제 실패시켜
     * curl 에러가 msg 로 실려 오는지 확인한다(DNS 실패는 네트워크 없이도 즉시 실패한다).
     */
    public function testCurlFailureSurfacesAsMessageInsteadOfSilentlyEmpty(): void
    {
        $adapter = new KakaoPayAdapter();
        $this->setPrivateProperty($adapter, 'apiBase', 'https://invalid.invalid.test.example./v1');

        $invoker = $this->getPrivateMethodInvoker($adapter, 'request');

        /** @var array<string, mixed> $result */
        $result = $invoker('POST', '/ready', []);

        $this->assertArrayHasKey('msg', $result);
        $this->assertStringContainsString('카카오페이 서버 연결 실패', (string) $result['msg']);
    }

    /**
     * 카카오 표준 에러 포맷("msg" 키)이 아니라 게이트웨이 단계 등에서 다른
     * 형식으로 거부되면 뭉뚱그린 "HTTP 400" 문구만으로는 어떤 필드가 문제인지
     * 알 수 없다. msg 가 없을 때는 원본 응답 바디를 그대로 실어, 카카오가
     * 실제로 어떤 키・값으로 응답했는지 바로 보이게 한다.
     */
    public function testInterpretResponseIncludesRawBodyWhenMsgMissing(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'interpretResponse');

        /** @var array<string, mixed> $result */
        $result = $invoker(400, '{"code":-1,"error_description":"invalid client"}');

        $this->assertStringContainsString('HTTP 400', (string) $result['msg']);
        $this->assertStringContainsString('error_description', (string) $result['msg']);
        $this->assertStringContainsString('invalid client', (string) $result['msg']);
    }

    /** 카카오가 표준 msg 를 내려주면 원본 바디를 덧붙이지 않고 그대로 쓴다. */
    public function testInterpretResponseKeepsApiMsgWhenPresent(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'interpretResponse');

        /** @var array<string, mixed> $result */
        $result = $invoker(400, '{"msg":"invalid param(cid has invalid value)"}');

        $this->assertSame('invalid param(cid has invalid value)', $result['msg']);
    }

    /** JSON 이 아닌 응답(HTML 에러 페이지 등)도 별도 메시지로 구분한다. */
    public function testInterpretResponseHandlesUnparsableBody(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'interpretResponse');

        /** @var array<string, mixed> $result */
        $result = $invoker(502, '<html>Bad Gateway</html>');

        $this->assertStringContainsString('파싱 실패', (string) $result['msg']);
        $this->assertStringContainsString('HTTP 502', (string) $result['msg']);
    }
}
