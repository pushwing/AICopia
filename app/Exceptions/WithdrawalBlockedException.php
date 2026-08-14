<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 탈퇴 차단 조건에 걸렸을 때 발생
 *
 * 폼을 그릴 때 canWithdraw() 로 한 번 걸러도, 제출 사이에 주문이 생기거나
 * 상태가 바뀔 수 있다. withdraw() 는 트랜잭션 안에서 재검사하고 이 예외를 던진다.
 */
class WithdrawalBlockedException extends \RuntimeException
{
    /** @param list<string> $reasons 사용자에게 보여줄 차단 사유 목록 */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
