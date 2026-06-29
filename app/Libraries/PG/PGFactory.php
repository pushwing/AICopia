<?php

namespace App\Libraries\PG;

class PGFactory
{
    public static function make(string $provider): PGInterface
    {
        return match ($provider) {
            'bank_transfer' => new BankTransferAdapter(),
            'toss'          => new TossPaymentsAdapter(),
            'inicis'        => new InicisAdapter(),
            'nicepay'       => new NicePayAdapter(),
            'kakaopay'      => new KakaoPayAdapter(),
            'naverpay'      => new NaverPayAdapter(),
            'payco'         => new PaycoAdapter(),
            default         => throw new \InvalidArgumentException("지원하지 않는 PG: {$provider}"),
        };
    }

    public static function providers(): array
    {
        return ['bank_transfer', 'toss', 'inicis', 'nicepay', 'kakaopay', 'naverpay', 'payco'];
    }

    public static function labels(): array
    {
        return [
            'bank_transfer' => '무통장입금',
            'toss'          => '토스페이먼츠',
            'inicis'        => 'KG이니시스',
            'nicepay'       => '나이스페이',
            'kakaopay'      => '카카오페이',
            'naverpay'      => '네이버페이',
            'payco'         => 'PAYCO',
        ];
    }

    /**
     * 운영자 설정에서 활성화된 PG만 반환
     */
    public static function enabledLabels(): array
    {
        $settings = (new \App\Models\SettingModel())->getAllAsMap();

        $all = self::labels();
        $result = [];
        foreach ($all as $key => $label) {
            if (($settings["pg_enabled_{$key}"] ?? '0') === '1') {
                $result[$key] = $label;
            }
        }
        // 활성화된 게 없으면 전체 반환 (초기 설정 전 상황 대비)
        return $result ?: $all;
    }
}
