<?php

declare(strict_types=1);

namespace App\Libraries\OAuth;

/**
 * 공통 OAuth 2.0 추상 클래스
 * 각 제공자는 getAuthUrl(), getToken(), getProfile() 만 구현
 */
abstract class AbstractOAuthProvider
{
    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(protected string $providerName)
    {
        $cfg = config(\Config\OAuth::class)->{$this->providerName};

        // .env 우선 적용
        $cfg['client_id']     = env("oauth.{$this->providerName}.client_id", $cfg['client_id']);
        $cfg['client_secret'] = env("oauth.{$this->providerName}.client_secret", $cfg['client_secret']);
        $cfg['redirect_uri']  = base_url("auth/social/{$this->providerName}/callback");

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
     *
     * @return array<string, mixed>|null
     */
    abstract public function getProfile(string $token): ?array;

    // ─── HTTP 헬퍼 ────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $headers
     * @return array<string, mixed>
     */
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
        return json_decode($body, true) ?? [];
    }

    /**
     * @param  array<string, mixed> $data
     * @param  array<int, string>   $headers
     * @return array<string, mixed>
     */
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
        return json_decode($body, true) ?? [];
    }
}
