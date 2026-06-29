<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 네이버 Open API 키 설정
 * 실제 키는 .env에 저장: NAVER_CLIENT_ID, NAVER_CLIENT_SECRET
 * 네이버 개발자센터(developers.naver.com)에서 발급
 */
class Naver extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';
}
