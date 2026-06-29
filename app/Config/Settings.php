<?php

namespace Config;

use CodeIgniter\Settings\Config\Settings as BaseSettings;

class Settings extends BaseSettings
{
    // codeigniter4/settings 라이브러리 전용 테이블 — 앱의 settings 테이블과 분리
    public $database = [
        'group' => 'default',
        'table' => 'ci_settings',
    ];
}
