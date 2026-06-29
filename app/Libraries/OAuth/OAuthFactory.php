<?php

namespace App\Libraries\OAuth;

class OAuthFactory
{
    public static function make(string $provider): AbstractOAuthProvider
    {
        return match ($provider) {
            'naver'  => new NaverProvider(),
            'kakao'  => new KakaoProvider(),
            'google' => new GoogleProvider(),
            default  => throw new \InvalidArgumentException("지원하지 않는 소셜 로그인: {$provider}"),
        };
    }

    public static function supported(): array
    {
        return ['naver', 'kakao', 'google'];
    }
}
