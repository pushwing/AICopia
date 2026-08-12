<?php

declare(strict_types=1);

namespace App\Libraries\Order;

/**
 * OrderModel::convertAttempt() 의 결과 (이슈 #214).
 *
 * 실패를 0 하나로 돌려주면 호출자가 "재고 부족(환불 추적 가능)"과 "fail-closed
 * 거부(추적 불가)"를 구분할 수 없어, 로그도 사용자 안내도 뭉뚱그려질 수밖에 없다.
 */
final readonly class ConversionResult
{
    /**
     * @param int                    $orderId     성공 시 생성된 주문 id, 실패면 0
     * @param ConversionFailure|null $failure     실패 원인. 성공이면 null
     * @param bool                   $compensated 취소 주문 + paid 결제행이 실제로 남아
     *                                            환불 추적(findRefundPending)이 가능한지
     */
    private function __construct(
        public int $orderId,
        public ?ConversionFailure $failure,
        public bool $compensated,
    ) {
    }

    public static function success(int $orderId): self
    {
        return new self($orderId, null, false);
    }

    public static function failed(ConversionFailure $failure, bool $compensated = false): self
    {
        return new self(0, $failure, $compensated);
    }

    public function succeeded(): bool
    {
        return $this->failure === null && $this->orderId > 0;
    }
}
