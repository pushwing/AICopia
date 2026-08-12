<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\FrameBridge;
use App\Libraries\Order\ConversionFailure;
use App\Libraries\Order\ConversionResult;
use App\Libraries\PG\PGFactory;
use App\Libraries\PG\PGInterface;
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
        //
        // "성공이 아니면 전부 취소"로 판정한다(과거처럼 resultCode==='Fail' 정확
        // 일치만 보지 않는다). paymentId 가 없으면 애초에 /apply 승인 요청 자체를
        // 보낼 수 없으므로, 대소문자 차이·Cancel류 값·resultCode 자체가 없는 경우를
        // 취소로 묶어도 잃는 것이 없다 — 반대로 이 값들을 성공으로 오인해 그대로
        // 흘려보내면 승인 불가능한 요청이 아래에서 "결제 정보를 받지 못했습니다"
        // 실패 화면만 띄우게 된다(사용자가 결제창을 닫았을 뿐인데 실패로 보임).
        if ($pgProvider === 'naverpay') {
            $resultCode = (string) ($this->request->getGet('resultCode') ?? $this->request->getPost('resultCode') ?? '');
            $paymentId  = $this->resolvePgToken('naverpay');
            $isSuccess  = strcasecmp($resultCode, 'Success') === 0 && $paymentId !== '';

            if (! $isSuccess) {
                if ($attemptId > 0) {
                    $reason = $resultCode === '' ? '없음' : $resultCode;
                    $this->attemptModel->markFailed($attemptId, "네이버페이 결제 취소 (resultCode: {$reason})");
                }

                // resultMessage 는 네이버페이가 함께 보내는 사유 텍스트다. 사용자가
                // 결제창을 그냥 닫은 경우엔 비어 있으므로, 그런 경우까지 경고
                // 알림을 띄우면 안 된다(취소 화면 자체를 없애려던 목적과 어긋난다) —
                // 값이 있을 때(=진짜 승인 실패, 예: 카드 한도 초과)만 플래시로 안내한다.
                $resultMessage = trim(
                    (string) ($this->request->getGet('resultMessage') ?? $this->request->getPost('resultMessage') ?? '')
                );

                if ($resultMessage !== '') {
                    return redirect()->to('/order')->with('error', "결제가 완료되지 않았습니다. ({$resultMessage})");
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

        $pg     = $this->makePg($pgProvider);
        $result = $pg->confirm($pgToken, (int) $snapshot['payable_amount']);

        if (! $result['success']) {
            session()->setFlashdata('pg_error', $result['message'] ?? '결제 확인에 실패했습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        // 승인은 성공했으므로 이 시점부터 청구는 이미 살아 있다. 아래 실패 경로는
        // 모두 그 청구를 추적 가능한 형태로 남겨야 한다.
        $charge = [
            'pg_provider' => $pgProvider,
            'pg_tid'      => (string) $result['tid'],
            'method'      => (string) $result['method'],
            'amount'      => (int) $result['amount'],
            'raw'         => $result['raw'],
        ];

        // 금액 2차 검증 (어댑터 내부에서도 검증하지만 여기서 한 번 더)
        if ((int) $result['amount'] !== (int) $snapshot['payable_amount']) {
            log_message(
                'critical',
                "결제 금액 불일치: attempt_id={$attemptId}, order_id={$legacyOrderId}, "
                . "expected={$snapshot['payable_amount']}, got={$result['amount']}"
            );

            // 시도 기반 주문은 보상 주문(취소 + paid 결제행)을 남겨 관리자
            // "환불 필요" 목록에서 이 청구를 추적할 수 있게 한다. 이 처리가
            // 없으면 orders·payments 어디에도 흔적이 남지 않아 환불 대상이
            // 화면에서 사라진다(레거시 order_id 경로는 주문이 이미 있어 해당 없음).
            // TODO(#214): 레거시 order_id 분기 제거 시 이 조건도 함께 정리한다.
            $mismatch = $attemptId > 0
                ? $this->orderModel->compensateChargedAttempt($snapshot, ConversionFailure::AmountMismatch, $charge)
                : ConversionResult::failed(ConversionFailure::AmountMismatch);

            return $this->failWithCharge($mismatch, $snapshot, $pgProvider, $attemptId, $legacyOrderId, $charge);
        }

        // 재고 차감 + 주문 생성 (트랜잭션)
        // TODO(#214): 레거시 order_id 분기 — 다음 릴리스에서 else 절(confirmPaid 호출)을 제거한다.
        $conversion = $attemptId > 0
            ? $this->orderModel->convertAttempt($attemptId, 'paid', $pgProvider, $result['tid'], $result['method'], $result['raw'])
            : $this->confirmLegacyOrder($legacyOrderId, $pgProvider, $result);

        if (! $conversion->succeeded()) {
            return $this->failWithCharge($conversion, $snapshot, $pgProvider, $attemptId, $legacyOrderId, $charge);
        }

        return redirect()->to('/order/complete/' . $snapshot['order_number']);
    }

    /**
     * 승인은 끝났는데 주문 확정에 실패한 경우의 공통 마무리.
     *
     * 실패 원인마다 환불 추적 가능 여부가 달라 로그·안내를 뭉뚱그리면 안 된다:
     * 보상 주문이 남은 실패는 관리자 화면의 "환불 필요"(findRefundPending)에
     * 자동으로 뜨지만, 남지 않은 실패는 이 critical 로그가 유일한 단서다.
     *
     * PG 승인 취소를 자동으로 요청하는 기능(TODO #113)은 아직 없다 — 실제로
     * 환불이 나가기 전까지 "자동 환불"이라고 안내해선 안 된다.
     *
     * @param array<string, mixed>                                                                              $snapshot
     * @param array{pg_provider: string, pg_tid: string, method: string, amount: int, raw: array<string, mixed>} $charge
     */
    private function failWithCharge(
        ConversionResult $result,
        array $snapshot,
        string $pgProvider,
        int $attemptId,
        int $legacyOrderId,
        array $charge
    ): \CodeIgniter\HTTP\ResponseInterface {
        $failure = $result->failure ?? ConversionFailure::Unknown;

        session()->setFlashdata('pg_error', $failure->userMessage());

        // TODO(#113): PG 자동 취소 요청 ($pg->cancel(...)) 구현 전까지 이 로그가
        //             보상되지 않은 청구를 찾아내는 유일한 단서다.
        // 원응답·PG 토큰은 남기지 않는다(민감 정보). tid 는 환불 실행에 필요하다.
        log_message(
            'critical',
            sprintf(
                '결제 확정 실패 — 확인 필요: reason=%s, refund_tracked=%s, attempt_id=%d, order_id=%d, pg=%s, tid=%s, amount=%d',
                $failure->value,
                $result->compensated ? 'yes(환불 필요 목록)' : 'no(수동 확인 필요)',
                $attemptId,
                $legacyOrderId,
                $pgProvider,
                $charge['pg_tid'],
                $charge['amount'],
            )
        );

        return redirect()->to('/order/fail/' . $snapshot['order_number']);
    }

    /**
     * 레거시 order_id 경로 — 이미 존재하는 pending 주문을 확정한다.
     *
     * 실패해도 주문·결제행이 이미 있어 환불 추적은 confirmPaid() 내부의
     * compensateFailedConfirm() 이 담당한다.
     *
     * TODO(#214): 다음 릴리스에서 이 메서드를 통째로 제거한다.
     *
     * @param array<string, mixed> $result
     */
    private function confirmLegacyOrder(int $legacyOrderId, string $pgProvider, array $result): ConversionResult
    {
        $confirmed = $this->orderModel->confirmPaid(
            $legacyOrderId,
            $pgProvider,
            $result['tid'],
            $result['method'],
            $result['raw']
        );

        // confirmPaid() 는 bool 만 돌려줘 재고 부족·fail-closed 거부를 구분할 수
        // 없다. 추측해서 단정하느니 Unknown 으로 남겨 관리자 확인을 유도한다.
        return $confirmed
            ? ConversionResult::success($legacyOrderId)
            : ConversionResult::failed(ConversionFailure::Unknown);
    }

    /**
     * PG 어댑터 해소. 승인 결과를 가짜로 주입해 실패 분기를 검증할 수 있도록
     * 정적 팩토리 호출을 이 메서드 하나로 모아 둔다(테스트 전용 시임).
     */
    protected function makePg(string $pgProvider): PGInterface
    {
        return PGFactory::make($pgProvider);
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
