<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\FrameBridge;
use App\Libraries\PG\PGFactory;
use App\Models\OrderAttemptModel;
use App\Models\OrderModel;

class PaymentController extends BaseController
{
    private readonly OrderModel        $orderModel;
    private readonly OrderAttemptModel $attemptModel;

    public function __construct()
    {
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
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
    public function callback(string $pgProvider): \CodeIgniter\HTTP\ResponseInterface
    {
        if (! in_array($pgProvider, PGFactory::providers(), true)) {
            return redirect()->to('/')->with('error', '잘못된 접근입니다.');
        }

        $attemptId = (int) ($this->request->getGet('attempt_id') ?: $this->request->getPost('attempt_id'));
        // 배포 시점에 결제창이 떠 있던 사용자의 콜백은 아직 order_id 를 싣고 온다.
        // TODO(#214): 다음 릴리스에서 이 레거시 분기를 제거한다 — order_id 파싱.
        $legacyOrderId = (int) ($this->request->getGet('order_id') ?: $this->request->getPost('order_id'));
        $userId        = (int) session()->get('user_id');

        if (($attemptId <= 0 && $legacyOrderId <= 0) || ! $userId) {
            // 이니시스·나이스페이는 이 returnUrl 을 자기 iframe 안에서 직접 로드한다.
            // SameSite=Lax 세션 쿠키는 그런 크로스사이트 iframe 서브요청엔 실리지
            // 않아 userId 가 비어 보일 수 있다 — 잘못된 접근으로 단정하기 전에
            // 최상위 창을 같은 URL로 이동시켜 재시도할 기회를 준다.
            if (! $userId && FrameBridge::isFramed($this->request)) {
                return $this->response->setBody(FrameBridge::render((string) current_url(true)));
            }

            return redirect()->to('/')->with('error', '잘못된 접근입니다.');
        }

        // 금액 검증·주문번호 표시에 쓸 스냅샷. 신규는 시도, 레거시는 주문에서 읽는다.
        // TODO(#214): 레거시 order_id 분기 — 다음 릴리스에서 else 절을 제거한다.
        $snapshot = $attemptId > 0
            ? $this->attemptModel->findPendingForUser($attemptId, $userId)
            : $this->orderModel->where('id', $legacyOrderId)->where('user_id', $userId)->where('status', 'pending')->first();

        if (! $snapshot) {
            if ($attemptId > 0) {
                // findPendingForUser() 는 소유권 불일치와 "이미 확정된 시도"(실패·전환
                // 완료) 모두에서 null 을 반환해 원인을 구분하지 못한다. 이 시점은
                // $pg->confirm() 이전이라 청구는 없지만, PG 가 성공으로 돌려보낸
                // 콜백을 우리가 버렸을 가능성이 있으므로 흔적을 남긴다.
                log_message('warning', "결제 콜백 — 유효하지 않은 시도: attempt_id={$attemptId}, pg={$pgProvider}");
            }

            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }

        // 네이버페이는 성공·취소 모두 같은 returnUrl로 오고 resultCode 로만 구분한다
        // (카카오페이·PAYCO·이니시스처럼 별도 취소 URL이 없다). 취소(결제창을 그냥
        // 닫음)는 승인 실패가 아니므로 시도를 걷어내고 주문서로 돌려보낸다.
        if ($pgProvider === 'naverpay') {
            $resultCode = $this->request->getGet('resultCode') ?? $this->request->getPost('resultCode');
            if ($resultCode === 'Fail') {
                if ($attemptId > 0) {
                    $this->attemptModel->markFailed($attemptId, '네이버페이 결제 취소');
                }

                return redirect()->to('/order');
            }
        }

        // PG별 토큰 파라미터 이름이 다름
        $pgToken = $this->resolvePgToken($pgProvider);
        if ($pgToken === '' || $pgToken === '0') {
            session()->setFlashdata('pg_error', '결제 정보를 받지 못했습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        $pg     = PGFactory::make($pgProvider);
        $result = $pg->confirm($pgToken, (int) $snapshot['payable_amount']);

        if (! $result['success']) {
            session()->setFlashdata('pg_error', $result['message'] ?? '결제 확인에 실패했습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        // 금액 2차 검증 (어댑터 내부에서도 검증하지만 여기서 한 번 더)
        if ((int) $result['amount'] !== (int) $snapshot['payable_amount']) {
            log_message(
                'critical',
                "결제 금액 불일치: attempt_id={$attemptId}, order_id={$legacyOrderId}, "
                . "expected={$snapshot['payable_amount']}, got={$result['amount']}"
            );
            session()->setFlashdata('pg_error', '결제 금액이 일치하지 않습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        // 재고 차감 + 주문 생성 (트랜잭션)
        // TODO(#214): 레거시 order_id 분기 — 다음 릴리스에서 else 절(confirmPaid 호출)을 제거한다.
        $confirmed = $attemptId > 0
            ? $this->orderModel->convertAttempt($attemptId, 'paid', $pgProvider, $result['tid'], $result['method'], $result['raw']) > 0
            : $this->orderModel->confirmPaid($legacyOrderId, $pgProvider, $result['tid'], $result['method'], $result['raw']);

        if (! $confirmed) {
            // convertAttempt() 이 0을 반환하는 원인은 두 가지이고 컨트롤러는 이를
            // 구분하지 못한다 — 메시지·로그 모두 원인을 단정하지 않는다.
            //   1) 재고 부족: compensateFailedConversion() 이 취소 주문 + paid 결제행을
            //      남긴다. 쿠폰·포인트는 그 안에서 이미 복구됐고, 이 주문은 관리자
            //      화면의 "환불 필요"(findRefundPending)에 자동으로 뜨며 관리자가
            //      PgCancellationService 의 환불 버튼으로 PG 취소를 요청할 수 있다.
            //   2) fail-closed 거부: 이미 failed/converted 로 확정된 시도에 승인이
            //      늦게 도착한 경우다. 그 확정 시점(markFailed 등)에 쿠폰·포인트는
            //      이미 복구됐지만, 이 경로는 아무 행도 남기지 않아 "환불 필요"
            //      목록에 뜨지 않는다 — 아래 critical 로그가 유일한 단서다.
            // PG 승인(청구) 자체는 이 시점에 이미 끝난 상태이고, PG 승인 취소를
            // 자동으로 요청하는 기능(TODO(#113))은 두 경우 모두 아직 구현돼 있지
            // 않다 — 실제로 환불이 나가기 전까지 "자동 환불"이라고 안내해선 안 된다.
            session()->setFlashdata(
                'pg_error',
                '결제 확정에 실패했습니다(재고 부족 또는 중복·지연 콜백). 사용하신 쿠폰·포인트는 '
                . '복구되었습니다. 결제하신 금액은 확인 후 환불해 드립니다. 고객센터로 문의해 주세요.'
            );
            // TODO(#113): PG 자동 취소 요청 ($pg->cancel($result['tid'], $result['amount'], '결제 확정 실패'))
            //             구현 전까지는 아래 critical 로그가 fail-closed 경로의 유일한 단서다.
            log_message(
                'critical',
                "결제 확정 실패 — 확인 필요: attempt_id={$attemptId}, order_id={$legacyOrderId}, "
                . "pg={$pgProvider}, tid={$result['tid']}, amount={$result['amount']}"
            );

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        return redirect()->to('/order/complete/' . $snapshot['order_number']);
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
