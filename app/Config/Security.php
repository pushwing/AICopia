<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Security extends BaseConfig
{
    public string $csrfProtection = 'session';
    public bool   $tokenRandomize = false;
    public string $tokenName      = 'csrf_test_name';
    public string $headerName     = 'X-CSRF-TOKEN';
    public string $cookieName     = 'csrf_cookie_name';
    public int    $expires        = 7200;

    // Ajax POST가 여러 번 발생하는 경우 토큰 재발급 없이 세션 단위로 유지
    public bool $regenerate = false;

    public bool $redirect = (ENVIRONMENT === 'production');
}
