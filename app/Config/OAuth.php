<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 각 소셜 앱에서 발급받은 키를 .env에 설정하세요.
 *
 * .env 예시:
 *   oauth.naver.client_id     = YOUR_NAVER_CLIENT_ID
 *   oauth.naver.client_secret = YOUR_NAVER_CLIENT_SECRET
 *   oauth.kakao.client_id     = YOUR_KAKAO_REST_API_KEY
 *   oauth.kakao.client_secret = YOUR_KAKAO_CLIENT_SECRET   (선택)
 *   oauth.google.client_id    = YOUR_GOOGLE_CLIENT_ID
 *   oauth.google.client_secret = YOUR_GOOGLE_CLIENT_SECRET
 *
 * scope 도 .env 로 덮어쓸 수 있다 (예: oauth.kakao.scope = ...).
 */
class OAuth extends BaseConfig
{
    public array $naver = [
        'client_id'     => '',  // .env: oauth.naver.client_id
        'client_secret' => '',
        'redirect_uri'  => '',  // 자동 생성됨 (base_url + /auth/social/naver/callback)
        'auth_url'      => 'https://nid.naver.com/oauth2.0/authorize',
        'token_url'     => 'https://nid.naver.com/oauth2.0/token',
        'profile_url'   => 'https://openapi.naver.com/v1/nid/me',
        'scope'         => '',
    ];

    public array $kakao = [
        'client_id'     => '',  // .env: oauth.kakao.client_id  (REST API 키)
        'client_secret' => '',  // 카카오 앱 보안 > Client Secret (선택)
        'redirect_uri'  => '',
        'auth_url'      => 'https://kauth.kakao.com/oauth/authorize',
        'token_url'     => 'https://kauth.kakao.com/oauth/token',
        'profile_url'   => 'https://kapi.kakao.com/v2/user/me',
        // 휴대폰번호·성별·생년월일(users.phone/gender/birthday)을 함께 받으려면
        // 카카오 콘솔 > 카카오 로그인 > 동의 항목에서 아래를 켠다.
        //   phone_number(비즈니스 앱 전환 필요) · gender · birthday · birthyear
        // 콘솔에서 켠 항목은 scope 를 넘기지 않아도 동의 화면에 나오므로 기본값은
        // 그대로 둔다 — 켜지 않은 항목을 scope 에 넣으면 인가 요청 자체가 거부된다.
        // 항목을 "이용 중 동의"로 운영해 명시적으로 요청해야 한다면 .env 에서
        // oauth.kakao.scope 로 덮어쓴다.
        'scope' => 'profile_nickname,profile_image,account_email',
    ];

    public array $google = [
        'client_id'     => '',  // .env: oauth.google.client_id
        'client_secret' => '',
        'redirect_uri'  => '',
        'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url'     => 'https://oauth2.googleapis.com/token',
        'profile_url'   => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'scope'         => 'openid email profile',
    ];
}
