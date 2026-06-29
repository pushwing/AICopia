<?php

namespace App\Libraries\OAuth;

/**
 * 공통 OAuth 2.0 추상 클래스
 * 각 제공자는 getAuthUrl(), getToken(), getProfile() 만 구현
 */
abstract class AbstractOAuthProvider
{
    protected array $config;
    protected string $providerName;

    public function __construct(string $providerName)
    {
        $this->providerName = $providerName;
        $cfg = config(\Config\OAuth::class)->{$providerName};

        // .env 우선 적용
        $cfg['client_id']     = env("oauth.{$providerName}.client_id",     $cfg['client_id']);
        $cfg['client_secret'] = env("oauth.{$providerName}.client_secret", $cfg['client_secret']);
        $cfg['redirect_uri']  = base_url("auth/social/{$providerName}/callback");

        $this->config = $cfg;
    }

    /**
     * 인가 URL 반환 (로그인 버튼 클릭 시 이동)
     */
    public function getAuthUrl(string $state): string
    {
        $params = [
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'response_type' => 'code',
            'state'         => $state,
        ];

        if (! empty($this->config['scope'])) {
            $params['scope'] = $this->config['scope'];
        }

        return $this->config['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * code → access_token 교환
     */
    public function getToken(string $code): ?string
    {
        $params = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'code'          => $code,
        ];

        $response = $this->post($this->config['token_url'], $params);
        return $response['access_token'] ?? null;
    }

    /**
     * access_token → 사용자 정보
     * 반환: ['social_id', 'email', 'nickname', 'avatar']
     */
    abstract public function getProfile(string $token): ?array;

    // ─── HTTP 헬퍼 ────────────────────────────────────────────────────────

    protected function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode($body, true) ?? [];
    }

    protected function post(string $url, array $data, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode($body, true) ?? [];
    }
}
