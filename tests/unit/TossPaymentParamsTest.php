<?php

declare(strict_types=1);

use App\Libraries\PG\TossPaymentsAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 토스페이먼츠 결제창 파라미터 검증.
 *
 * 토스 SDK 의 requestPayment() 는 결제창을 띄우기 전에 apigw(-sandbox).tosspayments.com
 * 으로 파라미터를 먼저 보내 검증받는다. 규격을 벗어나면 결제창이 열리지 않고
 * 브라우저 콘솔에 400 만 남는다 — 그래서 어댑터가 만드는 값 자체를 여기서 검증한다.
 *
 * @internal
 */
final class TossPaymentParamsTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id'             => 12,
            'order_number'   => 'ORD-20260807-42317',
            'total_amount'   => 22000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'items'          => [
                ['product_name' => '기본 티셔츠', 'qty' => 1],
            ],
        ];
    }

    /**
     * 토스 규격: orderId 는 영문 대소문자·숫자·`-`·`_` 만 허용하며 6자 이상 64자 이하.
     * 위반하면 apigw 가 400(INVALID_ORDER_ID)을 반환해 결제창이 뜨지 않는다.
     * DB PK(예: "12")를 그대로 넘기면 6자 미만이라 항상 실패한다.
     */
    public function testOrderIdMatchesTossFormatRule(): void
    {
        $params = (new TossPaymentsAdapter())->buildPaymentParams($this->order());

        $orderId = (string) $params['orderId'];

        $this->assertGreaterThanOrEqual(6, strlen($orderId), 'orderId 는 6자 이상이어야 합니다.');
        $this->assertLessThanOrEqual(64, strlen($orderId), 'orderId 는 64자 이하여야 합니다.');
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+$/',
            $orderId,
            'orderId 는 영문·숫자·-·_ 만 사용할 수 있습니다.'
        );
    }

    /** 짧은 DB PK 대신 주문번호를 써야 규격을 만족한다. */
    public function testOrderIdUsesOrderNumber(): void
    {
        $params = (new TossPaymentsAdapter())->buildPaymentParams($this->order());

        $this->assertSame('ORD-20260807-42317', $params['orderId']);
    }

    /**
     * 결제 성공 후 돌아올 successUrl 은 다른 PG 어댑터와 동일하게 어댑터가 만든다.
     * 뷰에서 orderId 를 콜백의 order_id 로 재사용하면, orderId 가 주문번호로 바뀌는
     * 순간 콜백이 주문을 찾지 못한다(콜백은 DB PK 로 조회한다).
     */
    public function testSuccessUrlPointsToCallbackWithDatabaseOrderId(): void
    {
        $params = (new TossPaymentsAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('successUrl', $params);
        $this->assertStringContainsString('payment/callback/toss', (string) $params['successUrl']);
        $this->assertStringContainsString('order_id=12', (string) $params['successUrl']);
        $this->assertStringStartsWith('http', (string) $params['successUrl'], 'successUrl 은 절대 URL 이어야 합니다.');
    }

    /** 실패·취소 시 돌아올 failUrl 은 주문번호 기반 실패 페이지를 가리킨다. */
    public function testFailUrlPointsToOrderFailPage(): void
    {
        $params = (new TossPaymentsAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('failUrl', $params);
        $this->assertStringContainsString('order/fail/ORD-20260807-42317', (string) $params['failUrl']);
        $this->assertStringStartsWith('http', (string) $params['failUrl'], 'failUrl 은 절대 URL 이어야 합니다.');
    }

    /** 뷰의 launchPG 가 분기에 쓰는 pg 키는 유지해야 한다. */
    public function testKeepsPgDiscriminator(): void
    {
        $this->assertSame('toss', (new TossPaymentsAdapter())->buildPaymentParams($this->order())['pg']);
    }
}
