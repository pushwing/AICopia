<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Filters extends BaseConfig
{
    public array $aliases = [
        'csrf'     => \CodeIgniter\Filters\CSRF::class,
        'toolbar'  => \CodeIgniter\Filters\DebugToolbar::class,
        'honeypot' => \CodeIgniter\Filters\Honeypot::class,
        'auth'     => \App\Filters\AuthFilter::class,
        'stats'    => \App\Filters\StatsFilter::class,
        'secheaders' => \App\Filters\SecurityHeadersFilter::class,
        'pwchange'   => \App\Filters\ForcePasswordChangeFilter::class,
    ];

    public array $globals = [
        'before' => [
            // payment/callback/* — PG 서버에서 직접 POST 요청 (CSRF 토큰 없음)
            'csrf' => ['except' => ['api/*', 'board/image-upload', 'admin/media/upload', 'payment/callback/*']],
            // 초기 비밀번호를 쓰는 계정은 변경 전까지 다른 화면으로 나가지 못한다 (이슈 #119)
            'pwchange',
        ],
        'after'  => ['toolbar', 'stats', 'secheaders'],
    ];

    public array $methods = [];
    public array $filters = [];
}
