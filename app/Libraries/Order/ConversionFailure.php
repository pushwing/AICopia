<?php

declare(strict_types=1);

namespace App\Libraries\Order;

/**
 * 주문 시도 → 주문 전환이 실패한 원인 (이슈 #214).
 *
 * 실패를 하나로 뭉뚱그리면 안 되는 이유는 **청구를 추적할 수 있는지**가 원인마다
 * 다르기 때문이다:
 *
 * - compensates() === true  — 취소 주문 + paid 결제행이 남아 관리자 "환불 필요"
 *   목록(OrderModel::findRefundPending())에 자동으로 뜬다.
 * - compensates() === false — 아무 행도 남지 않는다. critical 로그가 유일한 단서다.
 *
 * PG 승인 취소를 자동으로 요청하는 기능은 아직 없다(TODO #113) — 어떤 안내
 * 문구도 "자동 환불"을 약속해선 안 된다.
 */
enum ConversionFailure: string
{
    /** 이미 failed/converted 로 확정된 시도에 승인이 늦게 도착 (fail-closed 거부) */
    case AlreadyFinalized = 'already_finalized';

    /** items_snapshot 이 비어 라인 0건 주문이 될 상황 */
    case CorruptSnapshot = 'corrupt_snapshot';

    /** orders INSERT 실패 — 주문번호 UNIQUE 충돌 */
    case OrderNumberConflict = 'order_number_conflict';

    /** 재고 부족으로 차감 실패 */
    case OutOfStock = 'out_of_stock';

    /** payments INSERT 실패 — pg_tid UNIQUE 충돌 */
    case PaymentConflict = 'payment_conflict';

    /** 전환 트랜잭션 커밋 실패 */
    case CommitFailed = 'commit_failed';

    /** PG 승인 금액이 시도의 payable_amount 와 다름 (컨트롤러의 2차 검증) */
    case AmountMismatch = 'amount_mismatch';

    /**
     * 원인을 특정할 수 없는 확정 실패.
     *
     * bool 만 돌려주는 레거시 confirmPaid() 경로처럼 원인을 알 수 없을 때 쓴다.
     * 추측해서 단정하지 않고 관리자 수동 확인을 유도하는 쪽으로 기운다.
     */
    case Unknown = 'unknown';

    /**
     * 이 실패가 보상 경로(취소 주문 + paid 결제행)를 타는가.
     *
     * false 인 경우는 전환 트랜잭션이 통째로 롤백돼 시도가 pending 으로 돌아가는
     * 상황이라, 보상 주문을 만들면 오히려 잘못된 흔적이 남는다.
     */
    public function compensates(): bool
    {
        return match ($this) {
            self::OutOfStock,
            self::CorruptSnapshot,
            self::OrderNumberConflict,
            self::AmountMismatch => true,

            self::AlreadyFinalized,
            self::PaymentConflict,
            self::CommitFailed,
            self::Unknown => false,
        };
    }

    /** 보상 주문의 상태 로그·order_attempts.fail_reason 에 남길 사유 */
    public function note(): string
    {
        return match ($this) {
            self::AlreadyFinalized    => '이미 종료된 주문 시도 — 중복·지연 콜백 거부',
            self::CorruptSnapshot     => '주문 시도 스냅샷 손상 — 주문 취소',
            self::OrderNumberConflict => '주문 생성 실패 — 주문번호 충돌',
            self::OutOfStock          => '재고 부족으로 결제 확정 실패 — 주문 취소',
            self::PaymentConflict     => '결제 기록 생성 실패 — pg_tid 충돌',
            self::CommitFailed        => '전환 트랜잭션 커밋 실패',
            self::AmountMismatch      => '결제 금액 불일치 — 주문 취소',
            self::Unknown             => '원인 불명 — 결제 확정 실패',
        };
    }

    /**
     * 사용자에게 보여줄 안내 — 원인 + 쿠폰·포인트 처리 + (청구가 있었다면) 환불 안내.
     *
     * @param bool $charged PG 청구가 이미 일어났는지. 무료 주문·무통장입금은 false —
     *                      환불할 금액 자체가 없으므로 환불 안내를 붙이면 안 된다.
     */
    public function userMessage(bool $charged = true): string
    {
        $sentences = [$this->causeSentence(), $this->reservationSentence()];

        if ($charged) {
            $sentences[] = $this->refundSentence();
        }

        return implode(' ', array_filter($sentences, static fn (string $s): bool => $s !== ''));
    }

    /** 무엇이 잘못됐는지 */
    private function causeSentence(): string
    {
        return match ($this) {
            self::OutOfStock          => '재고가 부족해 주문을 확정하지 못했습니다.',
            self::AmountMismatch      => '결제 금액이 주문 금액과 일치하지 않아 주문을 확정하지 못했습니다.',
            self::AlreadyFinalized    => '이미 종료된 결제 요청입니다. 주문내역에서 상태를 확인해 주세요.',
            self::CorruptSnapshot,
            self::OrderNumberConflict => '주문 정보를 확정하지 못했습니다.',
            self::PaymentConflict,
            self::CommitFailed,
            self::Unknown             => '일시적인 오류로 주문을 확정하지 못했습니다.',
        };
    }

    /**
     * 선점했던 쿠폰·포인트가 어떻게 됐는지.
     *
     * 보상 경로를 타지 않는 실패는 시도가 pending 으로 되돌아가 만료 스윕이
     * 나중에 회수하므로 "복구되었습니다"라고 단정할 수 없다.
     */
    private function reservationSentence(): string
    {
        return match ($this) {
            self::OutOfStock,
            self::AmountMismatch,
            self::CorruptSnapshot,
            self::OrderNumberConflict => '사용하신 쿠폰·포인트는 복구되었습니다.',

            self::PaymentConflict,
            self::CommitFailed => '사용하신 쿠폰·포인트는 잠시 후 복구됩니다.',

            // 이미 다른 주체가 시도를 확정했거나(AlreadyFinalized) 원인 자체를
            // 알 수 없어(Unknown) 이 요청이 복구 여부를 단정할 수 없다.
            self::AlreadyFinalized, self::Unknown => '',
        };
    }

    /**
     * 청구된 금액을 어떻게 처리하는지.
     *
     * PG 승인 취소를 자동으로 요청하는 기능은 아직 없다(TODO #113) — 관리자가
     * 확인 후 수동으로 환불한다. "자동 환불"이라고 안내해선 안 된다.
     */
    private function refundSentence(): string
    {
        if ($this === self::AlreadyFinalized) {
            return '중복으로 결제된 금액이 있다면 확인 후 환불해 드립니다. 고객센터로 문의해 주세요.';
        }

        return '결제하신 금액은 확인 후 환불해 드립니다. 고객센터로 문의해 주세요.';
    }
}
