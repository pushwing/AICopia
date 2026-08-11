<?php

declare(strict_types=1);

use App\Libraries\PG\KakaoPayAdapter;
use App\Libraries\PG\PaycoAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 사용자가 PG 결제창에서 그냥 취소한 것은 결제 실패가 아니다.
 *
 * 장바구니는 PaymentController::confirmPaid() 로 결제가 실제 확정되기 전까지
 * 비워지지 않으므로(OrderController::create() 참고), 취소 시 order/fail(결제
 * 실패 화면) 대신 주문서(order)로 돌려보내도 상품 목록이 그대로 남아 있어
 * 문제없이 다시 보여줄 수 있다.
 *
 * @internal
 */
final class PgCancelReturnsToOrderTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function order(): array
    {
        return [
            'id'             => 4242,
            'user_id'        => 77,
            'order_number'   => 'ORD20260807XYZ',
            'total_amount'   => 22000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'items'          => [
                ['product_name' => '기본 티셔츠', 'qty' => 1],
            ],
        ];
    }

    public function testPaycoCancelUrlReturnsToOrderPageNotFailPage(): void
    {
        $params = (new PaycoAdapter())->buildPaymentParams($this->order());

        $this->assertArrayHasKey('cancelUrl', $params);
        $this->assertStringNotContainsString('order/fail', (string) $params['cancelUrl']);
        $this->assertStringEndsWith('/order', rtrim((string) $params['cancelUrl'], '/'));
    }

    /**
     * 카카오페이는 ready() 가 외부 API 를 호출하므로, 네트워크 없이 검증할 수 있도록
     * 페이로드 조립만 떼어낸 buildReadyPayload() 를 직접 확인한다(PgPayableAmountTest 와 동일한 방식).
     */
    public function testKakaopayCancelUrlReturnsToOrderPageNotFailPage(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyPayload');

        /** @var array<string, mixed> $payload */
        $payload = $invoker($this->order());

        $this->assertArrayHasKey('cancel_url', $payload);
        $this->assertStringNotContainsString('order/fail', (string) $payload['cancel_url']);
        $this->assertStringEndsWith('/order', rtrim((string) $payload['cancel_url'], '/'));
    }

    /** 카드 승인 거절 등 진짜 결제 실패(fail_url)는 여전히 결제 실패 화면으로 안내해야 한다. */
    public function testKakaopayFailUrlStillPointsToFailPage(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyPayload');

        /** @var array<string, mixed> $payload */
        $payload = $invoker($this->order());

        $this->assertStringContainsString('order/fail/ORD20260807XYZ', (string) $payload['fail_url']);
    }
}
