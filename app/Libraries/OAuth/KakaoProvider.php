<?php

namespace App\Libraries\OAuth;

/**
 * 카카오 로그인
 * 앱 등록: https://developers.kakao.com/console/app
 * 동의 항목: 닉네임, 프로필 사진, 카카오계정(이메일)
 */
class KakaoProvider extends AbstractOAuthProvider
{
    public function __construct()
    {
        parent::__construct('kakao');
    }

    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['id'])) return null;

        $account  = $data['kakao_account'] ?? [];
        $profile  = $account['profile']    ?? [];

        return [
            'social_id' => (string) $data['id'],
            'email'     => $account['email'] ?? null,
            'nickname'  => $profile['nickname'] ?? '카카오유저',
            'avatar'    => $profile['profile_image_url'] ?? null,
        ];
    }
}
