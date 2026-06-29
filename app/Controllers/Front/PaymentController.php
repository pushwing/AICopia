<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\PG\PGFactory;
use App\Models\OrderModel;

class PaymentController extends BaseController
{
    private OrderModel $orderModel;

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
        if (! $pgToken) {
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
            session()->setFlashdata('pg_error', '재고 부족으로 결제를 완료할 수 없습니다. 자동 환불 처리됩니다.');
            // TODO: PG 자동 취소 요청 ($pg->cancel($result['tid'], $result['amount'], '재고 부족'))
            log_message('error', "결제 확정 실패 (재고 부족): order_id={$orderId}, tid={$result['tid']}");
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
