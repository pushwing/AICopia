<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * PG 결제 키 설정
 * 실제 키는 .env에 저장하고, 이 파일을 통해 어댑터가 참조합니다.
 * 키 목록: docs/env.example 참고
 */
class PG extends BaseConfig
{
    // ── 토스페이먼츠 ─────────────────────────────────────────────────────────
    public string $tossClientKey = '';
    public string $tossSecretKey = '';

    // ── KG이니시스 ───────────────────────────────────────────────────────────
    public string $inicisMerchantId = '';
    public string $inicisSignKey    = '';

    /**
     * 이니시스 테스트 환경(stg 도메인) 사용 여부.
     * null 이면 어댑터가 MID 로 자동 판별한다 — 상점별 테스트 MID 를 쓰는 경우
     * .env 의 INICIS_TEST_MODE 로 명시 지정한다.
     */
    public ?bool $inicisTestMode = null;

    // ── 나이스페이먼츠 ───────────────────────────────────────────────────────
    public string $nicepayClientId  = '';
    public string $nicepaySecretKey = '';

    // ── 카카오페이 ───────────────────────────────────────────────────────────
    public string $kakaoSecretKey = '';
    public string $kakaoCid       = 'TC0ONETIME';   // 테스트 CID 기본값

    // ── 네이버페이 ───────────────────────────────────────────────────────────
    public string $naverpayClientId     = '';
    public string $naverpayClientSecret = '';
    public string $naverpayChainId      = '';
    public string $naverpayPartnerId    = '';
    // SDK·API 서버가 함께 바뀌는 값이라 하나로 통일한다.
    // development(dev.apis.naver.com, 테스트/심사 전 clientId) or production(apis.naver.com, 승인된 운영 clientId)
    public string $naverpayMode = 'development';

    // ── PAYCO ────────────────────────────────────────────────────────────────
    public string $paycoSellerKey = '';
    public string $paycoSecretKey = '';

    public function __construct()
    {
        parent::__construct();

        $this->tossClientKey = env('TOSS_CLIENT_KEY', '');
        $this->tossSecretKey = env('TOSS_SECRET_KEY', '');

        $this->inicisMerchantId = env('INICIS_MERCHANT_ID', '');
        $this->inicisSignKey    = env('INICIS_SIGN_KEY', '');

        // env() 는 'true'/'false' 문자열을 bool 로 바꿔 준다. 미설정이면 null 로 두어
        // 어댑터가 MID 로 판별하게 한다.
        $testMode              = env('INICIS_TEST_MODE', null);
        $this->inicisTestMode  = is_bool($testMode) ? $testMode : null;

        $this->nicepayClientId  = env('NICEPAY_CLIENT_ID', '');
        $this->nicepaySecretKey = env('NICEPAY_SECRET_KEY', '');

        $this->kakaoSecretKey = env('KAKAOPAY_SECRET_KEY', '');
        $this->kakaoCid       = env('KAKAOPAY_CID', 'TC0ONETIME');

        $this->naverpayClientId     = env('NAVERPAY_CLIENT_ID', '');
        $this->naverpayClientSecret = env('NAVERPAY_CLIENT_SECRET', '');
        $this->naverpayChainId      = env('NAVERPAY_CHAIN_ID', '');
        $this->naverpayPartnerId    = env('NAVERPAY_PARTNER_ID', '');
        $this->naverpayMode         = env('NAVERPAY_MODE', 'development');

        $this->paycoSellerKey = env('PAYCO_SELLER_KEY', '');
        $this->paycoSecretKey = env('PAYCO_SECRET_KEY', '');
    }
}
