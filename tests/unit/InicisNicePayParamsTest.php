<?php

declare(strict_types=1);

use App\Libraries\PG\InicisAdapter;
use App\Libraries\PG\NicePayAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 이니시스·나이스페이 결제창 파라미터 검증.
 *
 * 두 PG 모두 결제창을 띄우려면 어댑터가 만들어 준 파라미터가 각 SDK 규격을
 * 만족해야 한다. 특히 returnUrl 이 없으면 인증 후 돌아올 곳이 없어 결제가 끊긴다.
 *
 * @internal
 */
final class InicisNicePayParamsTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id'             => 4242,
            'order_number'   => 'ORD20260807XYZ',
            'total_amount'   => 22000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'items'          => [
                ['product_name' => '기본 티셔츠', 'qty' => 1],
            ],
        ];
    }

    // ─── 이니시스 ─────────────────────────────────────────────────────────────

    /** INIStdPay 는 version·currency·gopaymethod·acceptmethod 가 없으면 결제창을 띄우지 않는다. */
    public function testInicisParamsIncludeStdPayRequiredFields(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->order());

        foreach (['version', 'currency', 'gopaymethod', 'acceptmethod'] as $key) {
            $this->assertArrayHasKey($key, $params, "이니시스 필수 파라미터 {$key} 가 없습니다.");
            $this->assertNotSame('', $params[$key], "이니시스 파라미터 {$key} 가 비어 있습니다.");
        }

        $this->assertSame('1.0', $params['version']);
        $this->assertSame('WON', $params['currency']);
    }

    /** 인증 후 돌아올 returnUrl·closeUrl 이 콜백 라우트를 order_id 와 함께 가리켜야 한다. */
    public function testInicisParamsIncludeReturnAndCloseUrl(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('returnUrl', $params);
        $this->assertArrayHasKey('closeUrl', $params);
        $this->assertStringContainsString('payment/callback/inicis', (string) $params['returnUrl']);
        $this->assertStringContainsString('order_id=4242', (string) $params['returnUrl']);
        $this->assertStringStartsWith('http', (string) $params['returnUrl'], 'returnUrl 은 절대 URL 이어야 합니다.');
    }

    /** signature 는 oid·price·timestamp 를, mKey 는 signKey 를 SHA256 해시한 값이다. */
    public function testInicisSignatureMatchesManualFormula(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->order());

        $expected = hash('sha256', sprintf(
            'oid=%s&price=%s&timestamp=%s',
            $params['oid'],
            $params['price'],
            $params['timestamp']
        ));

        $this->assertSame($expected, $params['signature']);
        $this->assertSame(hash('sha256', (string) config('PG')->inicisSignKey), $params['mKey']);
    }

    /** 구매자 연락처는 결제창 입력 편의를 위해 함께 넘긴다. */
    public function testInicisParamsIncludeBuyerContact(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('buyertel', $params);
        $this->assertSame('01012345678', $params['buyertel']);
    }

    // ─── 나이스페이 ───────────────────────────────────────────────────────────

    /** AUTHNICE.requestPay 는 method·returnUrl 이 없으면 결제창을 띄우지 않는다. */
    public function testNicepayParamsIncludeRequestPayRequiredFields(): void
    {
        $params = (new NicePayAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('method', $params, '나이스페이 method 파라미터가 없습니다.');
        $this->assertSame('card', $params['method']);

        $this->assertArrayHasKey('returnUrl', $params);
        $this->assertStringContainsString('payment/callback/nicepay', (string) $params['returnUrl']);
        $this->assertStringContainsString('order_id=4242', (string) $params['returnUrl']);
        $this->assertStringStartsWith('http', (string) $params['returnUrl'], 'returnUrl 은 절대 URL 이어야 합니다.');
    }

    // ─── 공통 ────────────────────────────────────────────────────────────────

    /** 뷰의 launchPG 가 분기에 쓰는 pg 키는 두 어댑터 모두 유지해야 한다. */
    public function testBothAdaptersKeepPgDiscriminator(): void
    {
        $this->assertSame('inicis', (new InicisAdapter())->buildPaymentParams($this->order())['pg']);
        $this->assertSame('nicepay', (new NicePayAdapter())->buildPaymentParams($this->order())['pg']);
    }
}
