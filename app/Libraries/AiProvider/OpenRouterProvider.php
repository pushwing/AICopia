<?php

namespace App\Libraries\AiProvider;

class OpenRouterProvider extends GroqProvider
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const DEFAULT_MODEL = 'meta-llama/llama-3.1-8b-instruct:free';

    private string $apiKey;
    protected string $model;

    public function __construct()
    {
        $settings      = model('SettingModel')->getAllAsMap();
        $this->apiKey  = ($settings['openrouter_api_key'] ?? '') ?: env('OPENROUTER_API_KEY', '');
        $this->model   = ($settings['openrouter_model']   ?? '') ?: env('OPENROUTER_MODEL', self::DEFAULT_MODEL);
    }

    protected function callApi(string $payload, int $timeout = 15): string|false
    {
        // 모델 필드를 현재 설정값으로 교체
        $data          = json_decode($payload, true);
        $data['model'] = $this->model;
        $payload       = json_encode($data);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: ' . base_url(),
                'X-Title: AICreo Shop',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 && $response !== false) ? $response : false;
    }
}
