<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\PG\PGInterface;
use App\Models\OrderModel;

/**
 * 확정 실패로 취소된 주문의 PG 청구를 되돌린다.
 *
 * 이 저장소의 환불은 원래 전부 수동이다 — 관리자가 PG 콘솔에서 취소하고
 * 시스템에는 표시만 했다. 여기서도 자동으로 돌리지 않는다. 관리자가 주문
 * 상세에서 버튼을 눌렀을 때만 실행되며, 실패하면 결제행을 paid 로 남겨
 * 다시 시도하거나 콘솔에서 처리할 수 있게 한다.
 */
final class PgCancellationService
{
    private readonly OrderModel $orders;

    public function __construct()
    {
        $this->orders = new OrderModel();
    }

    /**
     * 취소 대상 결제를 찾아 PG 에 취소를 요청한다.
     *
     * @return array{success: bool, message: string}
     */
    public function cancelCharge(PGInterface $pg, int $orderId, string $reason = '재고 부족으로 주문 취소'): array
    {
        $target = $this->findChargeToCancel($orderId);

        if ($target === null) {
            // 이미 취소됐거나 애초에 환불 대상이 아니다 — PG 를 호출하지 않는다.
            return ['success' => false, 'message' => '환불할 PG 결제가 없습니다. (이미 취소되었거나 대상이 아님)'];
        }

        $result = $pg->cancel((string) $target['pg_tid'], (int) $target['amount'], $reason);

        if (! $result['success']) {
            log_message(
                'critical',
                "PG 취소 실패 — 수동 환불 필요: order_id={$orderId}, pg={$target['pg_provider']}, "
                . "tid={$target['pg_tid']}, amount={$target['amount']}, message={$result['message']}"
            );

            return ['success' => false, 'message' => $result['message']];
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('payments')
            ->where('id', $target['payment_id'])
            ->where('status', 'paid')
            ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);

        log_message('info', "PG 취소 완료: order_id={$orderId}, tid={$target['pg_tid']}, amount={$target['amount']}");

        return ['success' => true, 'message' => $result['message']];
    }

    /**
     * 이 주문에 환불이 남아 있는지 확인한다.
     *
     * findRefundPending() 과 같은 기준을 쓴다 — 관리자 화면의 "환불 필요" 표시와
     * 실제 취소 가능 여부가 어긋나지 않게 하기 위해서다.
     *
     * @return array<string, mixed>|null
     */
    private function findChargeToCancel(int $orderId): ?array
    {
        return $this->orders->findRefundPendingPayment($orderId);
    }
}
