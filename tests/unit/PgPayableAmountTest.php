<?php

declare(strict_types=1);

use App\Libraries\PG\BankTransferAdapter;
use App\Libraries\PG\InicisAdapter;
use App\Libraries\PG\KakaoPayAdapter;
use App\Libraries\PG\NaverPayAdapter;
use App\Libraries\PG\NicePayAdapter;
use App\Libraries\PG\PaycoAdapter;
use App\Libraries\PG\TossPaymentsAdapter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\PG;

/**
 * 결제창에 넘기는 금액은 반드시 payable_amount(할인 후 실결제액)여야 한다.
 *
 * PaymentController::callback() 은 승인 요청·검증을 payable_amount 로 하므로,
 * 어댑터가 total_amount(할인 전)를 결제창에 넘기면 쿠폰·포인트를 쓴 주문은
 * 고객이 낸 금액과 서버가 승인 요청한 금액이 달라 PG 가 결제를 거부한다.
 *
 * @internal
 */
final class PgPayableAmountTest extends CIUnitTestCase
{
    /** 할인 전 금액 — 어떤 어댑터도 이 값을 결제창에 넘겨선 안 된다. */
    private const TOTAL_AMOUNT = 50000;

    /** 쿠폰 5,000원 + 포인트 3,000원 차감 후 실결제액. */
    private const PAYABLE_AMOUNT = 42000;

    protected function tearDown(): void
    {
        Factories::reset('config');
        parent::tearDown();
    }

    /**
     * 토스 어댑터는 키가 비었거나 결제위젯용(gck/gsk)이면 결제창 파라미터 대신
     * error 만 돌려준다. 로컬 .env 유무에 결과가 좌우되지 않도록 결제창용 키를
     * 주입해 만든다(TossPaymentParamsTest 와 동일한 방식).
     */
    private function tossAdapter(): TossPaymentsAdapter
    {
        $config                = new PG();
        $config->tossClientKey = 'test_ck_docsSampleClientKey0000000000';
        $config->tossSecretKey = 'test_sk_docsSampleSecretKey0000000000';
        Factories::injectMock('config', 'PG', $config);

        return new TossPaymentsAdapter();
    }

    /**
     * 쿠폰·포인트가 함께 적용된 주문 픽스처.
     *
     * @return array<string, mixed>
     */
    private function discountedOrder(): array
    {
        return [
            'id'                     => 4242,
            'user_id'                => 77,
            'order_number'           => 'ORD20260807XYZ',
            'total_amount'           => self::TOTAL_AMOUNT,
            'coupon_discount_amount' => 5000,
            'point_used_amount'      => 3000,
            'payable_amount'         => self::PAYABLE_AMOUNT,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '01012345678',
            'items'                  => [
                ['product_name' => '기본 티셔츠', 'qty' => 2],
            ],
        ];
    }

    // ─── PG별 결제창 금액 ──────────────────────────────────────────────────────

    public function testTossPassesPayableAmount(): void
    {
        $params = $this->tossAdapter()->buildPaymentParams($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $params['amount']);
    }

    public function testInicisPassesPayableAmount(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $params['price']);
    }

    /** 이니시스 signature 는 결제창에 넘긴 price 그대로 해시해야 검증을 통과한다. */
    public function testInicisSignatureIsBuiltFromPayableAmount(): void
    {
        $params = (new InicisAdapter())->buildPaymentParams($this->discountedOrder());

        $expected = hash('sha256', sprintf(
            'oid=%s&price=%s&timestamp=%s',
            $params['oid'],
            self::PAYABLE_AMOUNT,
            $params['timestamp']
        ));

        $this->assertSame($expected, $params['signature']);
    }

    public function testNicepayPassesPayableAmount(): void
    {
        $params = (new NicePayAdapter())->buildPaymentParams($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $params['amount']);
    }

    /** 네이버페이는 총 결제액과 과세 대상액을 함께 넘긴다 — 둘 다 실결제액 기준. */
    public function testNaverpayPassesPayableAmount(): void
    {
        $params = (new NaverPayAdapter())->buildPaymentParams($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $params['totalPayAmount']);
        $this->assertSame(self::PAYABLE_AMOUNT, $params['taxScopeAmount']);
    }

    public function testPaycoPassesPayableAmount(): void
    {
        $params = (new PaycoAdapter())->buildPaymentParams($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $params['totalAmount']);
    }

    /**
     * 카카오페이는 ready() 가 외부 API 를 호출하므로, 네트워크 없이 검증할 수 있도록
     * 페이로드 조립만 떼어낸 buildReadyPayload() 를 직접 확인한다.
     */
    public function testKakaopayReadyPayloadUsesPayableAmount(): void
    {
        $adapter = new KakaoPayAdapter();
        $invoker = $this->getPrivateMethodInvoker($adapter, 'buildReadyPayload');

        /** @var array<string, mixed> $payload */
        $payload = $invoker($this->discountedOrder());

        $this->assertSame(self::PAYABLE_AMOUNT, $payload['total_amount']);
    }

    // ─── 회귀 방지 ────────────────────────────────────────────────────────────

    /** payable_amount 가 없는 레거시 주문은 total_amount 로 안전하게 폴백한다. */
    public function testFallsBackToTotalAmountWhenPayableAmountMissing(): void
    {
        $legacy = $this->discountedOrder();
        unset($legacy['payable_amount']);

        $this->assertSame(self::TOTAL_AMOUNT, $this->tossAdapter()->buildPaymentParams($legacy)['amount']);
        $this->assertSame(self::TOTAL_AMOUNT, (new NicePayAdapter())->buildPaymentParams($legacy)['amount']);
        $this->assertSame(self::TOTAL_AMOUNT, (new InicisAdapter())->buildPaymentParams($legacy)['price']);
        $this->assertSame(self::TOTAL_AMOUNT, (new PaycoAdapter())->buildPaymentParams($legacy)['totalAmount']);
        $this->assertSame(self::TOTAL_AMOUNT, (new NaverPayAdapter())->buildPaymentParams($legacy)['totalPayAmount']);
    }

    /** 무통장입금은 결제창 자체가 없어 금액 파라미터를 넘기지 않는다. */
    public function testBankTransferCarriesNoAmountParam(): void
    {
        $params = (new BankTransferAdapter())->buildPaymentParams($this->discountedOrder());

        $this->assertSame(['pg', 'redirectUrl'], array_keys($params));
        $this->assertStringContainsString('ORD20260807XYZ', (string) $params['redirectUrl']);
    }
}
