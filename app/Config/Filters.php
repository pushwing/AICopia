<?php

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
    ];

    public array $globals = [
        'before' => [
            // payment/callback/* — PG 서버에서 직접 POST 요청 (CSRF 토큰 없음)
            'csrf' => ['except' => ['api/*', 'board/image-upload', 'admin/media/upload', 'payment/callback/*']],
        ],
        'after'  => ['toolbar', 'stats'],
    ];

    public array $methods = [];
    public array $filters = [];
}
