<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 소셜 제공자가 검증하지 않은 이메일이 기존 계정과 겹칠 때 발생 (이슈 #137)
 *
 * 이 경우 계정을 연동하면 남의 계정을 가져가는 셈이 되고, users.email 이 UNIQUE 라
 * 새 계정을 만들 수도 없다. 두 경로 모두 막히므로 사용자에게 상황을 알려야 한다.
 */
class SocialEmailNotVerifiedException extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $email,
    ) {
        parent::__construct("소셜 계정({$provider})의 이메일이 검증되지 않아 기존 계정과 연동할 수 없습니다. ({$email})");
    }
}
