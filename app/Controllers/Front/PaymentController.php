<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\PG\PGFactory;
use App\Models\OrderModel;

class PaymentController extends BaseController
{
    private readonly OrderModel $orderModel;

    public function __construct()
    {
        $this->orderModel = new OrderModel();
    }

    /**
     * GET|POST /payment/callback/:pg
     *
     * 결제 확정 플로우:
     *   1. 주문 조회 + 금액 검증
     *   2. PG 서버사이드 승인 요청
     *   3. 재고 차감 + 주문 상태 → paid (트랜잭션)
     *   4. 주문 완료 페이지 리디렉트
     */
    public function callback(string $pgProvider): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! in_array($pgProvider, PGFactory::providers(), true)) {
            return redirect()->to('/')->with('error', '잘못된 접근입니다.');
        }

        $orderId = (int) ($this->request->getGet('order_id') ?: $this->request->getPost('order_id'));
        $userId  = (int) session()->get('user_id');

        if (! $orderId || ! $userId) {
            return redirect()->to('/')->with('error', '잘못된 접근입니다.');
        }

        $order = $this->orderModel->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }

        // PG별 토큰 파라미터 이름이 다름
        $pgToken = $this->resolvePgToken($pgProvider);
        if ($pgToken === '' || $pgToken === '0') {
            session()->setFlashdata('pg_error', '결제 정보를 받지 못했습니다.');
            return redirect()->to('/order/fail/' . $order['order_number']);
        }

        $pg     = PGFactory::make($pgProvider);
        $result = $pg->confirm($pgToken, (int) $order['payable_amount']);

        if (! $result['success']) {
            session()->setFlashdata('pg_error', $result['message'] ?? '결제 확인에 실패했습니다.');
            return redirect()->to('/order/fail/' . $order['order_number']);
        }

        // 금액 2차 검증 (어댑터 내부에서도 검증하지만 여기서 한 번 더)
        if ((int) $result['amount'] !== (int) $order['payable_amount']) {
            log_message('critical', "결제 금액 불일치: order_id={$orderId}, expected={$order['payable_amount']}, got={$result['amount']}");
            session()->setFlashdata('pg_error', '결제 금액이 일치하지 않습니다.');
            return redirect()->to('/order/fail/' . $order['order_number']);
        }

        // 재고 차감 + 주문 확정 (트랜잭션)
        $confirmed = $this->orderModel->confirmPaid(
            $orderId,
            $pgProvider,
            $result['tid'],
            $result['method'],
            $result['raw']
        );

        if (! $confirmed) {
            // 쿠폰·포인트는 confirmPaid() 안에서 이미 복구되고 주문도 취소됐다.
            // 다만 PG 결제는 이 시점에 이미 승인(청구)된 상태이고, 자동 취소는
            // 아직 구현돼 있지 않다 — 실제로 환불이 나가기 전까지 "자동 환불"이라고
            // 안내해선 안 된다.
            session()->setFlashdata(
                'pg_error',
                '재고 부족으로 주문이 취소되었습니다. 사용하신 쿠폰·포인트는 복구되었습니다. '
                . '결제하신 금액은 확인 후 환불해 드립니다. 고객센터로 문의해 주세요.'
            );
            // TODO(#113): PG 자동 취소 요청 ($pg->cancel($result['tid'], $result['amount'], '재고 부족'))
            //             구현 전까지는 아래 critical 로그가 수동 환불의 유일한 단서다.
            log_message(
                'critical',
                "결제 확정 실패 (재고 부족) — 수동 환불 필요: order_id={$orderId}, "
                . "pg={$pgProvider}, tid={$result['tid']}, amount={$result['amount']}"
            );
            return redirect()->to('/order/fail/' . $order['order_number']);
        }

        return redirect()->to('/order/complete/' . $order['order_number']);
    }

    /** 결제 수단별 PG 토큰 파라미터 이름 해소 */
    private function resolvePgToken(string $pgProvider): string
    {
        $get  = $this->request->getGet();
        $post = $this->request->getPost();
        $all  = array_merge($post ?? [], $get ?? []);

        return match ($pgProvider) {
            'toss'     => $all['paymentKey'] ?? '',
            'inicis'   => $all['authToken']  ?? '',
            'nicepay'  => $all['tid']        ?? '',
            'kakaopay' => $all['pg_token']   ?? '',
            'naverpay' => $all['paymentId']  ?? '',
            'payco'    => $all['reserveOrderNo'] ?? '',
            default    => '',
        };
    }
}
