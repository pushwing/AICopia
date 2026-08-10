<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\PaymentReceipt;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PaymentReceipt — payments.raw_response(PG 원응답 JSON)에서
 * 카드 영수증 정보를 PG 별로 정규화해 꺼내는지 검증한다.
 */
final class PaymentReceiptTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function payment(string $provider, array $raw, string $method = 'card'): array
    {
        return [
            'pg_provider'  => $provider,
            'pg_tid'       => 'TID-' . $provider,
            'method'       => $method,
            'amount'       => 13000,
            'status'       => 'paid',
            'paid_at'      => '2026-08-10 13:05:00',
            'raw_response' => json_encode($raw, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function testReturnsNullWhenPaymentMissing(): void
    {
        $this->assertNull(PaymentReceipt::fromPayment(null));
    }

    public function testParsesTossCardPayment(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('toss', [
            'paymentKey' => 'pk_1',
            'status'     => 'DONE',
            'card'       => [
                'issuerCode'             => '41',
                'number'                 => '433012******1234',
                'installmentPlanMonths'  => 0,
                'approveNo'              => '00012345',
            ],
            'receipt' => ['url' => 'https://dashboard.tosspayments.com/receipt/x'],
        ]));

        $this->assertInstanceOf(PaymentReceipt::class, $receipt);
        $this->assertTrue($receipt->hasCardDetails());
        $this->assertSame('신한카드', $receipt->cardIssuer);
        $this->assertSame('433012******1234', $receipt->cardNumber);
        $this->assertSame(0, $receipt->installmentMonths);
        $this->assertSame('일시불', $receipt->installmentLabel());
        $this->assertSame('00012345', $receipt->approvalNumber);
        $this->assertSame('https://dashboard.tosspayments.com/receipt/x', $receipt->receiptUrl);
    }

    public function testKeepsUnknownIssuerCodeAsIs(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('toss', [
            'card' => ['issuerCode' => 'ZZ', 'number' => '433012******1234'],
        ]));

        $this->assertNotNull($receipt);
        $this->assertSame('ZZ', $receipt->cardIssuer, '모르는 코드는 임의로 바꾸지 말고 그대로 노출해야 한다');
    }

    public function testParsesInicisCardPayment(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('inicis', [
            'resultCode' => '0000',
            'tid'        => 'StdpayCARD_1',
            'cardName'   => '현대카드',
            'cardNum'    => '546212******7890',
            'cardQuota'  => '03',
            'applNum'    => '30012345',
        ]));

        $this->assertNotNull($receipt);
        $this->assertSame('현대카드', $receipt->cardIssuer);
        $this->assertSame('546212******7890', $receipt->cardNumber);
        $this->assertSame(3, $receipt->installmentMonths);
        $this->assertSame('3개월 할부', $receipt->installmentLabel());
        $this->assertSame('30012345', $receipt->approvalNumber);
    }

    public function testParsesNicepayNestedCardPayment(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('nicepay', [
            'resultCode' => '0000',
            'tid'        => 'nicuntct1',
            'approveNo'  => '12345678',
            'card'       => [
                'cardCode' => '04',
                'cardName' => '삼성카드',
                'cardNum'  => '123456******1234',
                'cardQuota' => 0,
            ],
            'receiptUrl' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do?TID=nicuntct1',
        ]));

        $this->assertNotNull($receipt);
        $this->assertSame('삼성카드', $receipt->cardIssuer);
        $this->assertSame('123456******1234', $receipt->cardNumber);
        $this->assertSame('12345678', $receipt->approvalNumber);
        $this->assertSame('https://npg.nicepay.co.kr/issue/IssueLoader.do?TID=nicuntct1', $receipt->receiptUrl);
    }

    public function testParsesKakaoPayCardInfo(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('kakaopay', [
            'aid'       => 'A1',
            'tid'       => 'T1',
            'card_info' => [
                'issuer_corp'   => '국민',
                'bin'           => '540926',
                'install_month' => '00',
                'approved_id'   => '99887766',
            ],
            'amount' => ['total' => 13000],
        ], 'kakaopay'));

        $this->assertNotNull($receipt);
        $this->assertSame('국민', $receipt->cardIssuer);
        $this->assertSame('540926******', $receipt->cardNumber);
        $this->assertSame(0, $receipt->installmentMonths);
        $this->assertSame('99887766', $receipt->approvalNumber);
    }

    public function testParsesNaverPayBodyDetail(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('naverpay', [
            'code' => 'Success',
            'body' => [
                'paymentId'      => 'np_1',
                'totalPayAmount' => 13000,
                'detail'         => [
                    'cardCorpCode'  => '61',
                    'cardNo'        => '123456**',
                    'cardInstCount' => 0,
                ],
            ],
        ], 'naverpay'));

        $this->assertNotNull($receipt);
        $this->assertSame('현대카드', $receipt->cardIssuer);
        $this->assertSame(0, $receipt->installmentMonths);
    }

    public function testParsesPaycoCardInfo(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('payco', [
            'header' => ['isSuccessful' => true],
            'body'   => [
                'orderNo'        => 'payco_1',
                'totalAmount'    => 13000,
                'paymentDetails' => [
                    [
                        'cardInfo' => [
                            'cardName'           => '롯데카드',
                            'cardNo'             => '451234******5678',
                            'installmentMonths'  => 6,
                            'approvalNumber'     => '55443322',
                        ],
                    ],
                ],
            ],
        ], 'payco'));

        $this->assertNotNull($receipt);
        $this->assertSame('롯데카드', $receipt->cardIssuer);
        $this->assertSame('451234******5678', $receipt->cardNumber);
        $this->assertSame(6, $receipt->installmentMonths);
        $this->assertSame('6개월 할부', $receipt->installmentLabel());
        $this->assertSame('55443322', $receipt->approvalNumber);
    }

    public function testBankTransferHasNoCardDetails(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('bank_transfer', [], '무통장입금'));

        $this->assertNotNull($receipt);
        $this->assertFalse($receipt->hasCardDetails());
        $this->assertNull($receipt->cardIssuer);
        $this->assertNull($receipt->installmentLabel());
    }

    public function testSurvivesBrokenRawResponse(): void
    {
        $receipt = PaymentReceipt::fromPayment([
            'pg_provider'  => 'toss',
            'pg_tid'       => 'TID-broken',
            'method'       => 'card',
            'amount'       => '13000',
            'paid_at'      => null,
            'raw_response' => '{not json',
        ]);

        $this->assertNotNull($receipt, '깨진 원응답이라도 영수증 기본 정보는 만들어져야 한다');
        $this->assertSame(13000, $receipt->amount);
        $this->assertSame('TID-broken', $receipt->tid);
        $this->assertFalse($receipt->hasCardDetails());
    }

    public function testMasksOverExposedCardNumber(): void
    {
        $receipt = PaymentReceipt::fromPayment($this->payment('inicis', [
            'cardNum' => '4330-1234-5678-1234',
        ]));

        $this->assertNotNull($receipt);
        $this->assertSame('433012******1234', $receipt->cardNumber, '평문 카드번호는 중간을 가려야 한다');
    }
}
