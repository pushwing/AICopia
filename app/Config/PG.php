<?php

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

        $this->nicepayClientId  = env('NICEPAY_CLIENT_ID', '');
        $this->nicepaySecretKey = env('NICEPAY_SECRET_KEY', '');

        $this->kakaoSecretKey = env('KAKAOPAY_SECRET_KEY', '');
        $this->kakaoCid       = env('KAKAOPAY_CID', 'TC0ONETIME');

        $this->naverpayClientId     = env('NAVERPAY_CLIENT_ID', '');
        $this->naverpayClientSecret = env('NAVERPAY_CLIENT_SECRET', '');
        $this->naverpayChainId      = env('NAVERPAY_CHAIN_ID', '');

        $this->paycoSellerKey = env('PAYCO_SELLER_KEY', '');
        $this->paycoSecretKey = env('PAYCO_SECRET_KEY', '');
    }
}
