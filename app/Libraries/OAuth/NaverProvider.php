<?php

declare(strict_types=1);

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

    /** @return array<string, mixed>|null */
    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['response'])) {
            return null;
        }

        $r = $data['response'];

        return [
            'social_id' => (string) $r['id'],
            'email'     => $r['email'] ?? null,
            // 네이버 프로필 API 는 이메일 검증 여부를 알려주지 않는다. 증명이 없으므로
            // 미검증으로 취급해 기존 계정 자동 연동 대상에서 제외한다 (이슈 #137)
            'email_verified' => false,
            'nickname'       => $r['nickname'] ?? $r['name'] ?? '네이버유저',
            'avatar'         => $r['profile_image'] ?? null,
        ];
    }
}
