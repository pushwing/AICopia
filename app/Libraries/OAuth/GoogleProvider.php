<?php

namespace App\Libraries\OAuth;

/**
 * 구글 로그인
 * 앱 등록: https://console.cloud.google.com/apis/credentials
 * 승인된 리디렉션 URI에 callback URL 등록 필수
 */
class GoogleProvider extends AbstractOAuthProvider
{
    public function __construct()
    {
        parent::__construct('google');
    }

    /**
     * 구글은 code → token 교환 시 POST body가 아닌 JSON 방식도 지원하지만
     * application/x-www-form-urlencoded 로도 동작함
     */
    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['sub'])) return null;

        return [
            'social_id' => $data['sub'],
            'email'     => $data['email'] ?? null,
            'nickname'  => $data['name'] ?? $data['given_name'] ?? '구글유저',
            'avatar'    => $data['picture'] ?? null,
        ];
    }
}
