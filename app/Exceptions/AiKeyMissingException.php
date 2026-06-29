<?php

namespace App\Exceptions;

class AiKeyMissingException extends \RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct("AI API 키가 설정되지 않았습니다. ({$provider})");
    }
}
