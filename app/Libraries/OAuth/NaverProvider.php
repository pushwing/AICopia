<?php

namespace App\Libraries\OAuth;

/**
 * 네이버 로그인
 * 앱 등록: https://developers.naver.com/apps/#/register
 * 필요 권한: 이메일, 별명, 프로필 사진
 */
class NaverProvider extends AbstractOAuthProvider
{
    public function __construct()
    {
        parent::__construct('naver');
    }

    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['response'])) return null;

        $r = $data['response'];

        return [
            'social_id' => (string) $r['id'],
            'email'     => $r['email'] ?? null,
            'nickname'  => $r['nickname'] ?? $r['name'] ?? '네이버유저',
            'avatar'    => $r['profile_image'] ?? null,
        ];
    }
}
