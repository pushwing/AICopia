<?php

declare(strict_types=1);

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

    /** @return array<string, mixed>|null */
    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['id'])) {
            return null;
        }

        $account  = $data['kakao_account'] ?? [];
        $profile  = $account['profile']    ?? [];

        return [
            'social_id' => (string) $data['id'],
            'email'     => $account['email'] ?? null,
            // 카카오 계정 이메일은 사용자가 바꿀 수 있어, 검증·유효 두 플래그가 모두
            // 참일 때만 소유가 증명된 것으로 본다 (이슈 #137)
            'email_verified' => ($account['is_email_verified'] ?? false) === true
                             && ($account['is_email_valid'] ?? false) === true,
            'nickname' => $profile['nickname'] ?? '카카오유저',
            'avatar'   => $profile['profile_image_url'] ?? null,
        ];
    }
}
