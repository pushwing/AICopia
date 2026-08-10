<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * 결제 영수증 정보 DTO.
 *
 * payments 행(+ raw_response 에 담긴 PG 원응답 JSON)에서 화면에 보여줄
 * 카드 영수증 정보(카드사·카드번호·할부·승인번호·영수증 URL)를 뽑아 정규화한다.
 * PG 마다 응답 구조가 제각각이라 필드별로 후보 경로를 순서대로 훑고,
 * 못 찾으면 조용히 null 로 둔다(원응답이 깨져도 화면은 떠야 한다).
 */
final readonly class PaymentReceipt
{
    /**
     * 카드사 코드 → 이름. 토스페이먼츠 issuerCode 표 기준이며
     * 같은 BC 계열 코드를 쓰는 PG 응답에도 그대로 적용한다.
     * 표에 없는 코드는 임의로 바꾸지 않고 코드 그대로 노출한다.
     *
     * 숫자로만 된 코드('46' 등)는 PHP 가 int 키로 정규화하므로 키 타입이 int|string 이다.
     *
     * @var array<int|string, string>
     */
    private const ISSUER_CODES = [
        '3K' => '기업BC',
        '46' => '광주은행',
        '71' => '롯데카드',
        '30' => '한국산업은행',
        '31' => 'BC카드',
        '51' => '삼성카드',
        '38' => '새마을금고',
        '41' => '신한카드',
        '62' => '신협',
        '36' => '씨티카드',
        '33' => '우리BC카드',
        'W1' => '우리카드',
        '37' => '우체국예금보험',
        '39' => '저축은행중앙회',
        '35' => '전북은행',
        '42' => '제주은행',
        '15' => '카카오뱅크',
        '3A' => '케이뱅크',
        '24' => '토스뱅크',
        '21' => '하나카드',
        '61' => '현대카드',
        '11' => 'KB국민카드',
        '91' => 'NH농협카드',
        '34' => 'Sh수협은행',
        '6D' => '다이너스 클럽',
        '4M' => '마스터카드',
        '3C' => '유니온페이',
        '7A' => '아메리칸 익스프레스',
        '4J' => 'JCB',
        '4V' => 'VISA',
    ];

    /**
     * PG 별 필드 후보 경로(점 표기). 앞에서부터 훑어 처음 찾은 값을 쓴다.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const PROVIDER_PATHS = [
        'toss' => [
            'issuer_code' => ['card.issuerCode'],
            'number'      => ['card.number'],
            'installment' => ['card.installmentPlanMonths'],
            'approval'    => ['card.approveNo'],
            'receipt_url' => ['receipt.url'],
        ],
        'inicis' => [
            'issuer_name' => ['cardName', 'CARD_Name'],
            'number'      => ['cardNum', 'CARD_Num'],
            'installment' => ['cardQuota', 'CARD_Quota'],
            'approval'    => ['applNum', 'APPL_NUM'],
            'receipt_url' => ['receiptUrl'],
        ],
        'nicepay' => [
            'issuer_name' => ['card.cardName', 'cardName'],
            'issuer_code' => ['card.cardCode', 'cardCode'],
            'number'      => ['card.cardNum', 'cardNum'],
            'installment' => ['card.cardQuota', 'cardQuota'],
            'approval'    => ['approveNo', 'authCode'],
            'receipt_url' => ['receiptUrl'],
        ],
        'kakaopay' => [
            'issuer_name' => ['card_info.issuer_corp', 'card_info.kakaopay_issuer_corp'],
            'number'      => ['card_info.bin'],
            'installment' => ['card_info.install_month'],
            'approval'    => ['card_info.approved_id'],
        ],
        'naverpay' => [
            'issuer_code' => ['body.detail.cardCorpCode', 'body.cardCorpCode'],
            'number'      => ['body.detail.cardNo', 'body.cardNo'],
            'installment' => ['body.detail.cardInstCount', 'body.cardInstCount'],
            'approval'    => ['body.detail.approvalNumber', 'body.approvalNumber'],
        ],
        'payco' => [
            'issuer_name' => ['body.paymentDetails.0.cardInfo.cardName', 'body.cardInfo.cardName'],
            'number'      => ['body.paymentDetails.0.cardInfo.cardNo', 'body.cardInfo.cardNo'],
            'installment' => ['body.paymentDetails.0.cardInfo.installmentMonths', 'body.cardInfo.installmentMonths'],
            'approval'    => ['body.paymentDetails.0.cardInfo.approvalNumber', 'body.cardInfo.approvalNumber'],
        ],
    ];

    private function __construct(
        public string $pgProvider,
        public ?string $method,
        public int $amount,
        public ?string $paidAt,
        public ?string $tid,
        public ?string $cardIssuer,
        public ?string $cardNumber,
        public ?int $installmentMonths,
        public ?string $approvalNumber,
        public ?string $receiptUrl,
    ) {
    }

    /**
     * payments 행에서 영수증 정보를 만든다. 결제 자체가 없으면 null.
     *
     * @param array<string, mixed>|null $payment
     */
    public static function fromPayment(?array $payment): ?self
    {
        if ($payment === null || $payment === []) {
            return null;
        }

        $provider = (string) ($payment['pg_provider'] ?? '');
        $raw      = self::decodeRaw($payment['raw_response'] ?? null);
        $paths    = self::PROVIDER_PATHS[$provider] ?? [];

        $issuerName = self::firstString($raw, $paths['issuer_name'] ?? []);
        $issuerCode = self::firstString($raw, $paths['issuer_code'] ?? []);
        $issuer     = $issuerName ?? self::issuerFromCode($issuerCode);

        $installment = self::firstString($raw, $paths['installment'] ?? []);

        return new self(
            pgProvider: $provider,
            method: self::nullableString($payment['method'] ?? null),
            amount: (int) ($payment['amount'] ?? 0),
            paidAt: self::nullableString($payment['paid_at'] ?? null),
            tid: self::nullableString($payment['pg_tid'] ?? null),
            cardIssuer: $issuer,
            cardNumber: self::maskCardNumber(self::firstString($raw, $paths['number'] ?? [])),
            installmentMonths: $installment === null ? null : (int) $installment,
            approvalNumber: self::firstString($raw, $paths['approval'] ?? []),
            receiptUrl: self::httpUrl(self::firstString($raw, $paths['receipt_url'] ?? [])),
        );
    }

    /** 카드 영수증으로 보여줄 내용이 하나라도 있는지 */
    public function hasCardDetails(): bool
    {
        return $this->cardIssuer !== null
            || $this->cardNumber !== null
            || $this->approvalNumber !== null
            || $this->receiptUrl !== null;
    }

    /** '일시불' / 'N개월 할부'. 할부 정보가 없으면 null */
    public function installmentLabel(): ?string
    {
        if ($this->installmentMonths === null) {
            return null;
        }

        return $this->installmentMonths > 0 ? $this->installmentMonths . '개월 할부' : '일시불';
    }

    /** 카드사·카드번호를 한 줄로 요약. 보여줄 게 없으면 null */
    public function cardSummary(): ?string
    {
        $parts = array_filter([$this->cardIssuer, $this->cardNumber, $this->installmentLabel()]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    // ── 내부 헬퍼 ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function decodeRaw(mixed $rawResponse): array
    {
        if (is_array($rawResponse)) {
            return $rawResponse;
        }

        if (! is_string($rawResponse) || trim($rawResponse) === '') {
            return [];
        }

        $decoded = json_decode($rawResponse, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 점 표기 경로 후보를 순서대로 훑어 처음 찾은 스칼라 값을 문자열로 돌려준다.
     *
     * @param array<string, mixed> $raw
     * @param array<int, string>   $paths
     */
    private static function firstString(array $raw, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = self::dig($raw, $path);
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $raw */
    private static function dig(array $raw, string $path): mixed
    {
        $cursor = $raw;
        foreach (explode('.', $path) as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    private static function issuerFromCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        // 표에 없는 코드는 그대로 노출한다 — 잘못된 카드사명을 보여주는 것보다 낫다.
        return self::ISSUER_CODES[strtoupper($code)] ?? $code;
    }

    /**
     * PG 가 이미 마스킹해 주지만, 혹시 평문이 섞여 와도 앞 6자리·뒤 4자리만 남긴다.
     * BIN(앞 6자리)만 오는 PG 는 뒤를 별표로 채워 카드번호처럼 보이게 한다.
     */
    private static function maskCardNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-]/', '', $number) ?? $number;
        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length <= 6) {
            return $digits . '******';
        }

        $head = substr($digits, 0, 6);
        $tail = $length >= 10 ? substr($digits, -4) : '';

        return $head . str_repeat('*', max(4, $length - 6 - strlen($tail))) . $tail;
    }

    /** http(s) 스킴이 아닌 값은 링크로 쓰지 않는다 */
    private static function httpUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
