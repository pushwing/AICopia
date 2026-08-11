<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\GradeService;
use CodeIgniter\Model;

class OrderModel extends Model
{
    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id', 'order_number', 'status',
        'total_product_price', 'shipping_fee', 'total_amount',
        'coupon_id', 'coupon_discount_amount', 'point_used_amount', 'point_earned_amount', 'payable_amount',
        'receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2', 'delivery_memo',
        'tracking_company', 'tracking_number',
        'paid_at', 'cancelled_at', 'expired_at', 'delivered_at',
        'return_reason', 'return_reason_code', 'return_reason_note',
        'exchange_reason', 'exchange_reason_code', 'exchange_request_note',
        'exchange_return_tracking_company', 'exchange_return_tracking_number',
        'exchange_tracking_company', 'exchange_tracking_number',
        'exchange_seller_shipping_fee',
    ];

    public const STATUS_LABELS = [
        'pending'           => '결제 대기',
        'awaiting_payment'  => '입금 대기',
        'paid'              => '결제 완료',
        'preparing'         => '상품 준비 중',
        'shipped'           => '배송 중',
        'delivered'         => '배송 완료',
        'cancelled'         => '취소',
        'expired'           => '만료',
        'refund_requested'  => '환불 요청',
        'refunded'          => '환불 완료',
        'return_requested'   => '반품 요청',
        'return_approved'    => '반품 승인',
        'exchange_requested' => '교환 요청',
        'exchange_approved'  => '교환 승인',
        'exchange_completed' => '교환 완료',
    ];

    /**
     * 실결제 금액이 주문 가능한 값인지 검증한다.
     *
     * payable_amount 가 0 인 주문(100% 할인 쿠폰·포인트 전액 사용)은 PG 를 거치지
     * 않고 즉시 확정하는 무료 주문 경로로 가므로 통과시킨다(→ confirmFree()).
     * 최소 결제 금액은 실제로 결제가 일어나는 1원 이상일 때만 따진다.
     *
     * @return string|null 거부 사유. 통과하면 null.
     */
    public function validatePayableAmount(int $payableAmount, int $minPayable): ?string
    {
        // 음수는 금액 계산이 깨졌다는 뜻이라 무료 주문으로 넘기지 않는다.
        if ($payableAmount < 0) {
            return '주문 금액을 계산할 수 없습니다. 장바구니를 다시 확인해주세요.';
        }

        if ($payableAmount > 0 && $payableAmount < $minPayable) {
            return '최소 결제 금액은 ' . number_format($minPayable) . '원입니다. 포인트 사용량을 조정해주세요.';
        }

        return null;
    }

    /**
     * 무통장입금 확정 — 재고 차감 + 주문·결제 상태 paid 전환
     */
    public function confirmBankTransfer(int $orderId): bool
    {
        $this->db->transStart();

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('status', 'awaiting_payment')
            ->get()->getRowArray();

        if (! $order) {
            $this->db->transRollback();
            return false;
        }

        $payment = $this->db->table('payments')
            ->where('order_id', $orderId)
            ->where('pg_provider', 'bank_transfer')
            ->where('status', 'pending')
            ->get()->getRowArray();

        if (! $payment) {
            $this->db->transRollback();
            return false;
        }

        $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();

        foreach ($items as $item) {
            if (! $this->deductItemStock($item)) {
                $this->db->transRollback();
                // 입금은 이미 받은 상태다 — 입금액 환불은 관리자가 수동으로 처리한다.
                $this->compensateFailedConfirm($orderId, '재고 부족으로 입금 확인 실패 — 주문 취소, 입금액 환불 필요');
                return false;
            }
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'  => 'paid',
            'paid_at' => $now,
        ]);

        $this->db->table('payments')->where('id', $payment['id'])->update([
            'status'     => 'paid',
            'paid_at'    => $now,
            'updated_at' => $now,
        ]);

        $this->writeStatusLog($orderId, 'awaiting_payment', 'paid', '무통장 입금 확인');

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * 실결제액이 0원인 주문을 PG 없이 즉시 확정한다.
     *
     * 100% 할인 쿠폰이나 포인트 전액 사용으로 payable_amount 가 0 이 되면
     * 결제창에 요청할 금액이 없다. 재고 차감·장바구니 비우기·상태 로그는
     * PG 결제와 동일해야 하므로 confirmPaid() 를 그대로 태운다.
     */
    public function confirmFree(int $orderId): bool
    {
        return $this->confirmPaid($orderId, 'free', null, 'free', ['reason' => 'payable_amount = 0']);
    }

    /**
     * 주문 시도를 실제 주문으로 전환한다.
     *
     * orders 에는 결제가 확정된 주문만 남긴다(이슈 #214). 이 메서드가 그 유일한
     * 진입점이며, OrderAttemptModel::claimForConversion() 의 조건부 UPDATE 로
     * 원자적 클레임을 먼저 잡아 중복 콜백을 막는다.
     *
     * 재고 차감이 실패하면 전환 트랜잭션을 롤백한 뒤, 이미 일어난 PG 청구를
     * 추적할 수 있도록 취소 상태의 주문 + 결제행을 남긴다(→ findRefundPending()).
     * 주문 자체를 만들지 않으면 payments.order_id 를 채울 수 없어 환불 대상이
     * 관리자 화면에서 사라진다.
     *
     * @param string                $targetStatus 'paid'(무료·PG 승인) 또는 'awaiting_payment'(무통장)
     * @param array<string, mixed>  $rawResponse
     *
     * @return int 생성된 주문 id. 실패하면 0.
     */
    public function convertAttempt(
        int $attemptId,
        string $targetStatus,
        string $pgProvider,
        ?string $pgTid,
        string $method,
        array $rawResponse
    ): int {
        $attemptModel = new OrderAttemptModel();
        $now          = date('Y-m-d H:i:s');

        // 클레임을 전환 트랜잭션 **안**에서 잡는다.
        //
        // 밖에서 잡으면(= 먼저 커밋되면) 이후 전환이 예기치 않게 죽었을 때 시도가
        // converted 인 채로 남고, expireStale() 은 pending 만 훑으므로 선점된
        // 쿠폰·포인트가 영구히 묶인다 — 이슈 #214 가 없애려던 유령 레코드가
        // order_attempts 에서 재발하는 꼴이다.
        //
        // 안에서 잡으면 롤백 시 pending 으로 되돌아가 만료 스윕이 정상 회수한다.
        // 조건부 UPDATE 가 행 잠금을 잡으므로 동시 콜백은 커밋될 때까지 대기했다가
        // status='converted' 를 보고 거부된다 — 멱등성은 그대로다.
        $this->db->transStart();

        $attempt = $attemptModel->claimForConversion($attemptId);
        if ($attempt === null) {
            $this->db->transRollback();

            return 0;
        }

        $items = $attempt['items'];

        // 스냅샷이 비었으면 라인 0건짜리 주문이 결제 완료 상태로 만들어진다.
        // 전환 자체를 실패시켜 보상 경로(환불 추적)로 넘긴다.
        if ($items === []) {
            $this->db->transRollback();
            log_message('critical', "[Order] 주문 시도 스냅샷이 비어 전환 불가 — attempt_id={$attemptId}");
            $this->compensateFailedConversion($attempt, '주문 시도 스냅샷 손상 — 주문 취소', $pgTid === null ? null : [
                'pg_provider' => $pgProvider,
                'pg_tid'      => $pgTid,
                'method'      => $method,
                'amount'      => (int) $attempt['payable_amount'],
                'raw'         => $rawResponse,
            ]);

            return 0;
        }

        $orderId = (int) $this->insert([
            'user_id'                => (int) $attempt['user_id'],
            'order_number'           => $attempt['order_number'],
            'status'                 => $targetStatus,
            'total_product_price'    => (int) $attempt['total_product_price'],
            'shipping_fee'           => (int) $attempt['shipping_fee'],
            'total_amount'           => (int) $attempt['total_amount'],
            'coupon_id'              => $attempt['coupon_id'],
            'coupon_discount_amount' => (int) $attempt['coupon_discount_amount'],
            'point_used_amount'      => (int) $attempt['point_used_amount'],
            'point_earned_amount'    => (int) $attempt['point_earned_amount'],
            'payable_amount'         => (int) $attempt['payable_amount'],
            'receiver_name'          => $attempt['receiver_name'],
            'receiver_phone'         => $attempt['receiver_phone'],
            'zipcode'                => $attempt['zipcode'],
            'address1'               => $attempt['address1'],
            'address2'               => $attempt['address2'],
            'delivery_memo'          => $attempt['delivery_memo'],
            'paid_at'                => $targetStatus === 'paid' ? $now : null,
        ], true);

        // order_number 는 attempt 채번 시점에 orders·order_attempts 양쪽을 확인했지만,
        // 그 사이 다른 주문이 같은 번호를 차지했을 가능성이 남는다. INSERT 실패를
        // 그냥 넘기면 order_id 0 으로 하위 INSERT 가 이어져 고아 행이 생긴다.
        //
        // DBDebug=true 지만 트랜잭션 **안**에서는 CI4 가 예외를 삼키고 insert() 가
        // false 를 돌려주며 transStatus() 가 false 가 된다(실측 확인). 따라서
        // try/catch 는 불필요하고 — PHPStan 이 catch.neverThrown 으로 거부한다 —
        // 아래 `=== 0` 가드가 그대로 동작한다. 트랜잭션 밖에서는 예외가 난다.
        if ($orderId === 0) {
            $this->db->transRollback();
            log_message('critical', "[Order] 주문 INSERT 실패 (주문번호 충돌 의심) — attempt_id={$attemptId}, order_number={$attempt['order_number']}");
            $this->compensateFailedConversion($attempt, '주문 생성 실패 — 주문번호 충돌', $pgTid === null ? null : [
                'pg_provider' => $pgProvider,
                'pg_tid'      => $pgTid,
                'method'      => $method,
                'amount'      => (int) $attempt['payable_amount'],
                'raw'         => $rawResponse,
            ]);

            return 0;
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = array_merge($item, ['order_id' => $orderId, 'created_at' => $now]);
        }
        $this->db->table('order_items')->insertBatch($rows);

        // 무통장은 입금이 확인되는 시점(confirmBankTransfer)에 재고를 뺀다.
        if ($targetStatus === 'paid') {
            foreach ($rows as $item) {
                if (! $this->deductItemStock($item)) {
                    $this->db->transRollback();
                    $this->compensateFailedConversion($attempt, '재고 부족으로 결제 확정 실패 — 주문 취소', $pgTid === null ? null : [
                        'pg_provider' => $pgProvider,
                        'pg_tid'      => $pgTid,
                        'method'      => $method,
                        'amount'      => (int) $attempt['payable_amount'],
                        'raw'         => $rawResponse,
                    ]);

                    return 0;
                }
            }
        }

        $paymentOk = $this->db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => $pgProvider,
            'pg_tid'       => $pgTid,
            'method'       => $method,
            'amount'       => (int) $attempt['payable_amount'],
            'status'       => $targetStatus === 'paid' ? 'paid' : 'pending',
            'raw_response' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            'paid_at'      => $targetStatus === 'paid' ? $now : null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // pg_tid UNIQUE 위반 등 INSERT 실패 시 명시적 롤백
        //
        // 이 시점에 이미 PG 승인은 끝난 상태다. 롤백되면 시도는 pending 으로
        // 돌아가 30분 뒤 expireStale() 이 "미결제"로 오인해 쿠폰·포인트를 회수하는데,
        // 여기서는 compensateFailedConversion() 을 부르지 않으므로(재고 차감과 달리
        // 이 실패는 사실상 발생 불가능한 pg_tid 충돌에 가까워 별도 청구 추적 흐름을
        // 새로 만들기보다 즉시 눈에 띄게 로깅해 수동 대응을 유도한다) 최소한 로그로
        // attempt_id·pg_tid·금액을 남긴다.
        if (! $paymentOk || $this->db->affectedRows() === 0) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            log_message(
                'critical',
                "[Order] 결제 INSERT 실패(pg_tid 충돌 의심) — attempt_id={$attemptId}, pg_tid=" . ($pgTid ?? 'null') . ", amount={$attempt['payable_amount']}"
            );

            return 0;
        }

        // 선점해 둔 쿠폰·포인트를 실제 주문에 연결한다. attempt 참조는 추적용으로 남긴다.
        $this->db->table('user_coupons')->where('order_attempt_id', $attemptId)->update(['order_id' => $orderId]);
        $this->db->table('point_logs')->where('order_attempt_id', $attemptId)->update(['order_id' => $orderId]);

        // 주문에 담긴 장바구니 행만 정확히 비운다(같은 상품의 다른 옵션은 남긴다).
        foreach ($rows as $item) {
            $builder = $this->db->table('cart_items')
                ->where('user_id', (int) $attempt['user_id'])
                ->where('product_id', (int) $item['product_id']);
            if ($item['sku_id']) {
                $builder->where('sku_id', (int) $item['sku_id']);
            } else {
                $builder->where('sku_id IS NULL', null, false);
            }
            $builder->delete();
        }

        $this->writeStatusLog($orderId, '', $targetStatus, match (true) {
            $pgProvider === 'free'          => '무료 주문 자동 확정 (쿠폰·포인트로 전액 차감)',
            $pgProvider === 'bank_transfer' => '무통장입금 주문 접수',
            default                         => 'PG 결제 확인 (' . $pgProvider . ')',
        });

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            // PG 승인은 이미 끝났는데 커밋이 실패하면 아무 흔적도 남지 않는다 —
            // 최소한 로그로는 추적 가능하게 남긴다.
            log_message(
                'critical',
                "[Order] 전환 트랜잭션 커밋 실패 — attempt_id={$attemptId}, order_id={$orderId}, pg_tid=" . ($pgTid ?? 'null')
            );

            return 0;
        }

        $attemptModel->linkOrder($attemptId, $orderId);

        return $orderId;
    }

    /**
     * 전환 실패 보상 — 이미 일어난 청구를 추적 가능한 형태로 남긴다.
     *
     * 클레임(claimForConversion)이 전환 트랜잭션 안에서 잡히므로, 재고 부족 등으로
     * 그 트랜잭션이 롤백되면 시도는 pending 으로 되돌아간다. 이 메서드가 시작되는
     * 시점과 실제로 상태를 확정하는 시점 사이에 markFailed()/expireStale() 이 같은
     * 시도를 먼저 걷어갈 수 있으므로, **조건부 UPDATE 를 맨 먼저** 실행해 그
     * 결과(claimed)에 따라 복구 여부를 가른다 — 그래야 양쪽이 동시에 쿠폰·포인트를
     * 복구하는 이중 환급을 막을 수 있다(이슈 #214 회귀).
     *
     * 단, 청구 흔적(취소 주문 + paid 결제행)은 경합에서 지더라도 반드시 남겨야
     * 환불 추적(findRefundPending)이 끊기지 않는다 — 그래서 주문·결제행 생성은
     * claimed 여부와 무관하게 항상 수행한다.
     *
     * 조건부 UPDATE 를 맨 앞에서 실행하므로 order_attempts 행 잠금도 가장 먼저
     * 잡힌다 — convertAttempt() 의 잠금 순서(order_attempts → orders)와 같아져,
     * 반대 순서로 잠그면 생길 수 있던 데드락 가능성도 함께 없앤다.
     *
     * 실패한 트랜잭션이 롤백된 뒤 호출해야 하며, 자체 트랜잭션으로 처리한다.
     *
     * @param array<string, mixed>                                                                                    $attempt
     * @param array{pg_provider: string, pg_tid: string, method: string, amount: int, raw: array<string, mixed>}|null  $charge
     */
    private function compensateFailedConversion(array $attempt, string $note, ?array $charge = null): void
    {
        // 직전 롤백으로 트랜잭션 상태가 false 로 남아 있으면 이 트랜잭션도 롤백된다.
        $this->db->resetTransStatus();
        $this->db->transStart();

        $now = date('Y-m-d H:i:s');

        // 상태 전이를 조건부 UPDATE 로 가장 먼저 확정한다(convertAttempt() 의
        // claimForConversion() 과 동일하게 order_attempts 잠금을 orders 보다
        // 먼저 잡는다). 롤백으로 pending 이 된 시도를 만료 스윕·markFailed 가
        // 먼저 걷어갔다면 쿠폰·포인트는 이미 복구된 것이므로 여기서 또
        // 되돌리면 이중 환급이 된다 — 그 판정을 $claimed 로 가른다.
        $this->db->query(
            'UPDATE order_attempts SET status = ?, failed_at = ?, fail_reason = ?, updated_at = ?
             WHERE id = ? AND status = ?',
            ['failed', $now, $note, $now, $attempt['id'], 'pending']
        );
        $claimed = $this->db->affectedRows() > 0;

        // 청구 흔적(취소 주문 + order_items + paid 결제행)은 $claimed 와 무관하게
        // 항상 남겨야 환불 추적(findRefundPending)이 끊기지 않는다. 게이트는
        // 아래 restoreCoupon()/restorePoints() 호출에만 건다.
        $orderId = (int) $this->insert([
            'user_id'                => (int) $attempt['user_id'],
            'order_number'           => $attempt['order_number'],
            'status'                 => 'cancelled',
            'total_product_price'    => (int) $attempt['total_product_price'],
            'shipping_fee'           => (int) $attempt['shipping_fee'],
            'total_amount'           => (int) $attempt['total_amount'],
            'coupon_id'              => $attempt['coupon_id'],
            'coupon_discount_amount' => (int) $attempt['coupon_discount_amount'],
            'point_used_amount'      => (int) $attempt['point_used_amount'],
            'point_earned_amount'    => (int) $attempt['point_earned_amount'],
            'payable_amount'         => (int) $attempt['payable_amount'],
            'receiver_name'          => $attempt['receiver_name'],
            'receiver_phone'         => $attempt['receiver_phone'],
            'zipcode'                => $attempt['zipcode'],
            'address1'               => $attempt['address1'],
            'address2'               => $attempt['address2'],
            'delivery_memo'          => $attempt['delivery_memo'],
            'cancelled_at'           => $now,
        ], true);

        // 보상 주문 자체가 만들어지지 않으면 하위 INSERT 를 이어갈 이유가 없다.
        // 트랜잭션 전체가 롤백되므로 위에서 확정한 조건부 UPDATE 도 함께
        // 되돌아가 시도는 원래 상태로 복구된다 — 다음 expireStale()/markFailed()
        // 스윕이 정상적으로 걷어간다.
        if ($orderId === 0) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            log_message('critical', "[Order] 보상 주문 생성 실패 — attempt_id={$attempt['id']}, order_number={$attempt['order_number']}");

            return;
        }

        $rows = [];
        foreach ($attempt['items'] as $item) {
            $rows[] = array_merge($item, ['order_id' => $orderId, 'created_at' => $now]);
        }
        if ($rows !== []) {
            $this->db->table('order_items')->insertBatch($rows);
        }

        // 주문은 cancelled 인데 결제가 paid 로 남은 조합이 곧 "환불 필요" 신호다.
        if ($charge !== null && $charge['pg_tid'] !== '') {
            $this->db->table('payments')->ignore(true)->insert([
                'order_id'     => $orderId,
                'pg_provider'  => $charge['pg_provider'],
                'pg_tid'       => $charge['pg_tid'],
                'method'       => $charge['method'],
                'amount'       => (int) $charge['amount'],
                'status'       => 'paid',
                'raw_response' => json_encode($charge['raw'], JSON_UNESCAPED_UNICODE),
                'paid_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // 선점은 order_attempt_id 에 걸려 있으므로 두 키를 함께 넘긴다.
        // PHP 의 + 연산자는 왼쪽 배열의 키를 우선하므로 id 는 새 주문 id 로 덮인다.
        $restoreTarget = ['id' => $orderId, 'order_attempt_id' => $attempt['id']] + $attempt;
        if ($claimed) {
            $this->restoreCoupon($restoreTarget);
            $this->restorePoints($restoreTarget, 'stock');
        }

        // order_id 연결은 이번 호출이 claim 에서 졌더라도 항상 수행한다 — 방금
        // 만든 보상 주문을 시도에서 추적할 수 있어야 한다.
        $this->db->table('order_attempts')->where('id', $attempt['id'])->update([
            'order_id'   => $orderId,
            'updated_at' => $now,
        ]);

        $this->writeStatusLog($orderId, '', 'cancelled', $note);

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            log_message('critical', "[Order] 보상 트랜잭션 커밋 실패 — attempt_id={$attempt['id']}, order_id={$orderId}");
        }

        // 이 트랜잭션의 성공·실패 여부가 다음 호출(같은 요청 내 다른 트랜잭션)에
        // 새어나가지 않도록 항상 리셋한다.
        $this->db->resetTransStatus();
    }

    /**
     * 결제 확정 — PG 콜백 수신 후 호출
     *
     * $pgTid 는 무료 주문처럼 PG 거래가 없는 경우 null 이다. payments.pg_tid 에
     * UNIQUE 제약이 있어 빈 문자열을 쓰면 두 번째 무료 주문이 중복으로 거부된다
     * (MySQL 은 NULL 중복만 허용한다).
     */
    /** @param array<string, mixed> $rawResponse */
    public function confirmPaid(int $orderId, string $pgProvider, ?string $pgTid, string $method, array $rawResponse): bool
    {
        $this->db->transStart();

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('status', 'pending')
            ->get()->getRowArray();

        if (! $order) {
            $this->db->transRollback();
            return false;
        }

        $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();

        foreach ($items as $item) {
            if (! $this->deductItemStock($item)) {
                $this->db->transRollback();
                // PG 청구는 이미 끝난 상태다 — 취소는 관리자가 실행하므로
                // 그때까지 추적할 수 있도록 청구 사실을 함께 남긴다.
                $this->compensateFailedConfirm(
                    $orderId,
                    '재고 부족으로 결제 확정 실패 — 주문 취소',
                    $pgTid === null ? null : [
                        'pg_provider' => $pgProvider,
                        'pg_tid'      => $pgTid,
                        'method'      => $method,
                        'amount'      => (int) $order['payable_amount'],
                        'raw'         => $rawResponse,
                    ],
                );
                return false;
            }
        }

        $now = date('Y-m-d H:i:s');

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'  => 'paid',
            'paid_at' => $now,
        ]);

        $paymentOk = $this->db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => $pgProvider,
            'pg_tid'       => $pgTid,
            'method'       => $method,
            'amount'       => (int) $order['payable_amount'],
            'status'       => 'paid',
            'raw_response' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            'paid_at'      => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // pg_tid UNIQUE 위반 등 INSERT 실패 시 명시적 롤백
        if (! $paymentOk || $this->db->affectedRows() === 0) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            return false;
        }

        // 장바구니 비우기 (product_id + sku_id 조합으로 정확히 삭제)
        foreach ($items as $item) {
            $builder = $this->db->table('cart_items')
                ->where('user_id', (int) $order['user_id'])
                ->where('product_id', (int) $item['product_id']);
            if ($item['sku_id']) {
                $builder->where('sku_id', (int) $item['sku_id']);
            } else {
                $builder->where('sku_id IS NULL', null, false);
            }
            $builder->delete();
        }

        $this->writeStatusLog($orderId, 'pending', 'paid', $pgProvider === 'free'
            ? '무료 주문 자동 확정 (쿠폰·포인트로 전액 차감)'
            : 'PG 결제 확인 (' . $pgProvider . ')');

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * 주문 취소 + 재고/쿠폰/포인트 복구
     */
    public function cancelOrder(int $orderId, int $userId): bool
    {
        $this->db->transStart();

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'awaiting_payment', 'paid'])
            ->get()->getRowArray();

        if (! $order) {
            $this->db->transRollback();
            return false;
        }

        if ($order['status'] === 'paid') {
            $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
            foreach ($items as $item) {
                $this->restoreItemStock($item);
            }
            $this->db->table('payments')
                ->where('order_id', $orderId)
                ->where('status', 'paid')
                ->update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
        }

        $this->restoreCoupon($order);
        $this->restorePoints($order, 'cancel');

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'       => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
        ]);

        $this->writeStatusLog($orderId, $order['status'], 'cancelled', '회원 취소 요청');

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * N분 이상 지난 pending 주문 만료 처리 — 쿠폰·포인트 복구 포함
     */
    public function expirePending(int $minutesOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutesOld} minutes"));
        $now    = date('Y-m-d H:i:s');

        $orders = $this->db->table('orders')
            ->where('status', 'pending')
            ->where('created_at <', $cutoff)
            ->get()->getResultArray();

        $count = 0;
        foreach ($orders as $order) {
            $this->db->transStart();

            $this->restoreCoupon($order);
            $this->restorePoints($order, 'expire');

            $this->db->table('orders')->where('id', $order['id'])->update([
                'status'     => 'expired',
                'expired_at' => $now,
            ]);

            $this->writeStatusLog((int) $order['id'], 'pending', 'expired', '미결제 자동 만료');

            $this->db->transComplete();
            if ($this->db->transStatus()) {
                $count++;
            }
        }

        return $count;
    }

    /** 주문 상태 변경 (단방향 흐름 + delivered 시 포인트 적립 확정) */
    public function updateStatus(int $orderId, string $newStatus): bool
    {
        $allowed = [
            'paid'             => 'preparing',
            'preparing'        => 'shipped',
            'shipped'          => 'delivered',
            'refund_requested' => 'refunded',
        ];

        $order = $this->find($orderId);
        if (! $order) {
            return false;
        }

        if (($allowed[$order['status']] ?? null) !== $newStatus) {
            return false;
        }

        if ($newStatus !== 'delivered' || (int) $order['point_earned_amount'] === 0) {
            $fields = ['status' => $newStatus];
            if ($newStatus === 'delivered') {
                $fields['delivered_at'] = date('Y-m-d H:i:s');
            }
            $ok = $this->update($orderId, $fields);
            if ($ok) {
                $this->writeStatusLog($orderId, $order['status'], $newStatus);
            }
            return $ok;
        }

        // delivered 전환 + 포인트 적립 확정
        $this->db->transStart();

        $this->update($orderId, ['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s')]);

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$order['point_earned_amount'], $order['user_id']]
        );
        $this->db->table('point_logs')->insert([
            'user_id'    => $order['user_id'],
            'type'       => 'earn',
            'amount'     => $order['point_earned_amount'],
            'order_id'   => $orderId,
            'note'       => '배송 완료 포인트 적립',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->writeStatusLog(
            $orderId,
            $order['status'],
            'delivered',
            '배송 완료 확인 · 포인트 ' . number_format((int) $order['point_earned_amount']) . '원 적립'
        );

        $this->db->transComplete();
        $ok = $this->db->transStatus();

        // 배송완료 후 등급 승급 체크 (트랜잭션 외부)
        if ($ok) {
            try {
                $settings = cache()->get('site_settings') ?? [];
                new GradeService()->checkAndUpgrade((int) $order['user_id'], $settings);
            } catch (\Throwable $e) {
                log_message('error', 'GradeService::checkAndUpgrade failed: ' . $e->getMessage());
            }
        }

        return $ok;
    }

    /** 관리자 강제 취소 */
    public function adminCancel(int $orderId): bool
    {
        $this->db->transStart();

        $order = $this->db->table('orders')
            ->whereIn('status', ['pending', 'awaiting_payment', 'paid', 'preparing'])
            ->where('id', $orderId)
            ->get()->getRowArray();

        if (! $order) {
            $this->db->transRollback();
            return false;
        }

        if (in_array($order['status'], ['paid', 'preparing'], true)) {
            $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
            foreach ($items as $item) {
                $this->restoreItemStock($item);
            }
            $this->db->table('payments')
                ->where('order_id', $orderId)
                ->where('status', 'paid')
                ->update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
        }

        $this->restoreCoupon($order);
        $this->restorePoints($order, 'cancel');

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'       => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
        ]);

        $this->writeStatusLog($orderId, $order['status'], 'cancelled', '관리자 강제 취소');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 환불 완료 처리 — 쿠폰 복구 + 포인트 환급 + 적립 취소 */
    public function markRefunded(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'refund_requested')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $this->update($orderId, ['status' => 'refunded']);

        $this->db->table('payments')
            ->where('order_id', $orderId)
            ->where('status', 'paid')
            ->update(['status' => 'refunded', 'cancelled_at' => date('Y-m-d H:i:s')]);

        $this->restoreCoupon($order);

        // 포인트 사용분 환급
        if ((int) $order['point_used_amount'] > 0) {
            $this->db->query(
                'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
                [$order['point_used_amount'], $order['user_id']]
            );
            $this->db->table('point_logs')->insert([
                'user_id'    => $order['user_id'],
                'type'       => 'refund',
                'amount'     => $order['point_used_amount'],
                'order_id'   => $orderId,
                'note'       => '주문 환불 포인트 환급',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // 이미 적립된 포인트 회수
        if ((int) $order['point_earned_amount'] > 0) {
            $earnLog = $this->db->table('point_logs')
                ->where('order_id', $orderId)
                ->where('type', 'earn')
                ->get()->getRowArray();

            if ($earnLog) {
                $this->db->query(
                    'UPDATE users SET point_balance = GREATEST(0, point_balance - ?) WHERE id = ?',
                    [$order['point_earned_amount'], $order['user_id']]
                );
                $this->db->table('point_logs')->insert([
                    'user_id'    => $order['user_id'],
                    'type'       => 'cancel',
                    'amount'     => -(int)$order['point_earned_amount'],
                    'order_id'   => $orderId,
                    'note'       => '환불로 인한 포인트 회수',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->writeStatusLog($orderId, 'refund_requested', 'refunded', '환불 처리 완료');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 교환 사유 코드 목록 */
    public const EXCHANGE_REASON_CODES = [
        'simple_change' => ['label' => '단순 변심',               'payer' => 'buyer'],
        'wrong_size'    => ['label' => '사이즈/색상 오선택',        'payer' => 'buyer'],
        'wrong_item'    => ['label' => '오배송 (잘못된 상품 배송)', 'payer' => 'seller'],
        'damaged'       => ['label' => '상품 파손/불량',            'payer' => 'seller'],
        'missing_item'  => ['label' => '구성품 누락',               'payer' => 'seller'],
    ];

    /** 반품 사유 코드 목록 */
    public const RETURN_REASON_CODES = [
        'simple_change' => ['label' => '단순 변심',               'payer' => 'buyer'],
        'wrong_size'    => ['label' => '사이즈/색상 오선택',        'payer' => 'buyer'],
        'wrong_item'    => ['label' => '오배송 (잘못된 상품 배송)', 'payer' => 'seller'],
        'damaged'       => ['label' => '상품 파손/불량',            'payer' => 'seller'],
        'missing_item'  => ['label' => '구성품 누락',               'payer' => 'seller'],
    ];

    /** 반품 요청 — delivered 상태 + 7일 이내에서만 가능 */
    public function requestReturn(int $orderId, int $userId, string $reasonCode, string $note = ''): bool
    {
        if (! array_key_exists($reasonCode, self::RETURN_REASON_CODES)) {
            return false;
        }

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'delivered')
            ->get()->getRowArray();

        if (! $order) {
            return false;
        }

        // delivered_at 기준 7일 초과 시 거부 (컬럼이 없는 구 주문은 허용)
        if (!empty($order['delivered_at']) && time() > strtotime((string) $order['delivered_at']) + 7 * 24 * 3600) {
            return false;
        }

        $label = self::RETURN_REASON_CODES[$reasonCode]['label'];

        $this->db->transStart();

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'             => 'return_requested',
            'return_reason'      => $label,
            'return_reason_code' => $reasonCode,
            'return_reason_note' => $note !== '' ? $note : null,
        ]);

        $logNote = '회원 반품 요청: ' . $label . ($note !== '' ? ' / ' . mb_substr($note, 0, 80) : '');
        $this->writeStatusLog($orderId, 'delivered', 'return_requested', $logNote);

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 반품 승인 — 재고·쿠폰·포인트 복구 후 return_approved 처리 (환불은 confirmReturnRefund에서) */
    public function approveReturn(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'return_requested')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
        foreach ($items as $item) {
            $this->restoreItemStock($item);
        }

        $this->restoreCoupon($order);
        $this->restorePoints($order, 'refund');

        // 이미 적립된 포인트 회수
        if ((int) $order['point_earned_amount'] > 0) {
            $earnLog = $this->db->table('point_logs')
                ->where('order_id', $orderId)
                ->where('type', 'earn')
                ->get()->getRowArray();

            if ($earnLog) {
                $this->db->query(
                    'UPDATE users SET point_balance = GREATEST(0, point_balance - ?) WHERE id = ?',
                    [$order['point_earned_amount'], $order['user_id']]
                );
                $this->db->table('point_logs')->insert([
                    'user_id'    => $order['user_id'],
                    'type'       => 'cancel',
                    'amount'     => -(int) $order['point_earned_amount'],
                    'order_id'   => $orderId,
                    'note'       => '반품으로 인한 포인트 회수',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->db->table('orders')->where('id', $orderId)->update(['status' => 'return_approved']);

        $this->writeStatusLog($orderId, 'return_requested', 'return_approved', '관리자 반품 승인');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 반품 거부 — delivered 상태로 복원 */
    public function rejectReturn(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'return_requested')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $this->db->table('orders')->where('id', $orderId)->update(['status' => 'delivered']);

        $this->writeStatusLog($orderId, 'return_requested', 'delivered', '관리자 반품 거부');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 환불 완료 확인 — PG 콘솔에서 환불 후 호출 (return_approved → refunded) */
    public function confirmReturnRefund(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'return_approved')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $this->db->table('payments')
            ->where('order_id', $orderId)
            ->where('status', 'paid')
            ->update(['status' => 'refunded', 'cancelled_at' => date('Y-m-d H:i:s')]);

        $this->db->table('orders')->where('id', $orderId)->update(['status' => 'refunded']);

        $this->writeStatusLog($orderId, 'return_approved', 'refunded', '반품 환불 완료');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 교환 요청 — delivered + 7일 이내, 쿠폰·포인트 미변경 */
    public function requestExchange(int $orderId, int $userId, string $reasonCode, string $note = ''): bool
    {
        if (! array_key_exists($reasonCode, self::EXCHANGE_REASON_CODES)) {
            return false;
        }

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'delivered')
            ->get()->getRowArray();

        if (! $order) {
            return false;
        }

        if (!empty($order['delivered_at']) && time() > strtotime((string) $order['delivered_at']) + 7 * 24 * 3600) {
            return false;
        }

        $label = self::EXCHANGE_REASON_CODES[$reasonCode]['label'];

        $this->db->transStart();

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'                => 'exchange_requested',
            'exchange_reason'       => $label,
            'exchange_reason_code'  => $reasonCode,
            'exchange_request_note' => $note ?: null,
        ]);

        $logNote = '회원 교환 요청: ' . $label . ($note !== '' ? ' / ' . mb_substr($note, 0, 80) : '');
        $this->writeStatusLog($orderId, 'delivered', 'exchange_requested', $logNote);

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 교환 승인 — 원상품 재고 복구 (쿠폰·포인트는 유지) */
    public function approveExchange(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'exchange_requested')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $items = $this->db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
        foreach ($items as $item) {
            $this->restoreItemStock($item);
        }

        $this->db->table('orders')->where('id', $orderId)->update(['status' => 'exchange_approved']);

        $this->writeStatusLog($orderId, 'exchange_requested', 'exchange_approved', '관리자 교환 승인');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /** 교환 거부 — delivered 복원 */
    public function rejectExchange(int $orderId): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'exchange_requested')->first();
        if (! $order) {
            return false;
        }

        $this->db->transStart();

        $this->db->table('orders')->where('id', $orderId)->update(['status' => 'delivered']);

        $this->writeStatusLog($orderId, 'exchange_requested', 'delivered', '관리자 교환 거부');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /**
     * 교환 완료 — 대체품 정보 저장 + 재고 차감 + 송장 기록
     *
     * @param array $exchangeItems [['product_id','sku_id','product_name','sku_option_label','product_price','qty'], ...]
     * @param array $tracking      ['exchange_tracking_company','exchange_tracking_number',
     *                              'exchange_return_tracking_company','exchange_return_tracking_number',
     *                              'exchange_seller_shipping_fee']
     */
    /**
     * @param array<int, array<string, mixed>> $exchangeItems
     * @param array<string, mixed>             $tracking
     */
    public function completeExchange(int $orderId, array $exchangeItems, array $tracking = []): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'exchange_approved')->first();
        if (! $order) {
            return false;
        }

        if ($exchangeItems === []) {
            return false;
        }

        // 원주문 금액과 대체품 금액 일치 검증
        $originalTotal = (int) $this->db->table('order_items')
            ->selectSum('subtotal')
            ->where('order_id', $orderId)
            ->get()->getRow()->subtotal;

        $exchangeTotal = 0;
        foreach ($exchangeItems as $item) {
            $exchangeTotal += (int) $item['product_price'] * (int) $item['qty'];
        }

        if ($originalTotal !== $exchangeTotal) {
            return false;
        }

        $this->db->transStart();

        $now = date('Y-m-d H:i:s');
        foreach ($exchangeItems as $item) {
            $qty   = (int) $item['qty'];
            $price = (int) $item['product_price'];
            $row   = [
                'order_id'         => $orderId,
                'product_id'       => (int) $item['product_id'],
                'sku_id'           => empty($item['sku_id']) ? null : (int) $item['sku_id'],
                'product_name'     => $item['product_name'],
                'sku_option_label' => $item['sku_option_label'] ?: null,
                'product_price'    => $price,
                'qty'              => $qty,
                'subtotal'         => $price * $qty,
                'created_at'       => $now,
            ];
            $this->db->table('exchange_items')->insert($row);

            if (! $this->deductItemStock($row)) {
                $this->db->transRollback();
                return false;
            }
        }

        $updateData = ['status' => 'exchange_completed'];

        if (! empty($tracking['exchange_tracking_company'])) {
            $updateData['exchange_tracking_company'] = $tracking['exchange_tracking_company'];
        }
        if (! empty($tracking['exchange_tracking_number'])) {
            $updateData['exchange_tracking_number'] = $tracking['exchange_tracking_number'];
        }
        if (! empty($tracking['exchange_return_tracking_company'])) {
            $updateData['exchange_return_tracking_company'] = $tracking['exchange_return_tracking_company'];
        }
        if (! empty($tracking['exchange_return_tracking_number'])) {
            $updateData['exchange_return_tracking_number'] = $tracking['exchange_return_tracking_number'];
        }
        if (! empty($tracking['exchange_seller_shipping_fee'])) {
            $updateData['exchange_seller_shipping_fee'] = (int) $tracking['exchange_seller_shipping_fee'];
        }

        $this->db->table('orders')->where('id', $orderId)->update($updateData);

        $this->writeStatusLog($orderId, 'exchange_approved', 'exchange_completed', '교환 완료 (대체품 발송 확인)');

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /**
     * 주문 상세
     *
     * @return array<string, mixed>|null
     */
    public function getWithItems(int $orderId, int $userId): ?array
    {
        $order = $this->where('id', $orderId)->where('user_id', $userId)->first();
        if (! $order) {
            return null;
        }

        $order['items']   = $this->fetchOrderItems($orderId);
        $order['payment'] = $this->db->table('payments')->where('order_id', $orderId)->orderBy('id', 'DESC')->get()->getRowArray();

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchOrderItems(int $orderId): array
    {
        return $this->db->table('order_items oi')
            ->select('oi.*, p.slug AS product_slug, p.shipping_type, p.free_threshold, m.file_path AS thumbnail')
            ->join('products p', 'p.id = oi.product_id', 'left')
            ->join('product_images pi', 'pi.product_id = oi.product_id AND pi.is_primary = 1', 'left')
            ->join('media m', 'm.id = pi.media_id', 'left')
            ->where('oi.order_id', $orderId)
            ->get()->getResultArray();
    }

    /**
     * @param  array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, total: int, totalPages: int, currentPage: int, perPage: int}
     */
    public function getByUser(int $userId, array $params = []): array
    {
        $period  = $params['period']  ?? 'all';
        $status  = $params['status']  ?? '';
        $keyword = trim($params['keyword'] ?? '');
        $page    = max(1, (int) ($params['page']    ?? 1));
        $perPage = max(1, (int) ($params['perPage'] ?? 10));

        $builder = $this->db->table('orders')->where('user_id', $userId);

        if ($period === '1m') {
            $builder->where('created_at >=', date('Y-m-d H:i:s', strtotime('-1 month')));
        } elseif ($period === '3m') {
            $builder->where('created_at >=', date('Y-m-d H:i:s', strtotime('-3 months')));
        }

        if ($keyword !== '') {
            $k = $this->db->escapeLikeString($keyword);
            $builder->where("EXISTS (
                SELECT 1 FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = orders.id
                AND (oi.product_name LIKE '%{$k}%' ESCAPE '!'
                     OR p.description LIKE '%{$k}%' ESCAPE '!'
                     OR oi.product_price LIKE '%{$k}%' ESCAPE '!')
            )", null, false);
        }

        if ($status !== '') {
            if ($status === 'cancel') {
                $builder->whereIn('status', ['cancelled', 'expired', 'refunded', 'refund_requested']);
            } else {
                $builder->where('status', $status);
            }
        } else {
            // "전체" 탭 기본 조회에서는 결제가 확정되지 않은 주문을 제외한다.
            // 신규 주문은 order_attempts 에 쌓이므로 여기 걸리는 건 레거시 행뿐이다.
            // 만료 주문은 "취소/환불" 탭(status=cancel)에서 계속 확인 가능하다. (이슈 #214)
            $builder->whereNotIn('status', ['pending', 'expired']);
        }

        $total  = (clone $builder)->countAllResults();
        $orders = $builder->orderBy('id', 'DESC')
                          ->limit($perPage, ($page - 1) * $perPage)
                          ->get()->getResultArray();

        return [
            'items'       => $orders,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, total: int, totalPages: int, currentPage: int, perPage: int}
     */
    public function adminGetAll(array $params = []): array
    {
        $keyword = trim($params['keyword'] ?? '');
        $status  = $params['status']  ?? '';
        $page    = max(1, (int) ($params['page']    ?? 1));
        $perPage = max(1, (int) ($params['perPage'] ?? 20));

        $builder = $this->db->table('orders o')
            ->select('o.*, u.email AS user_email, u.nickname AS user_nickname,
                lp.pg_provider, lp.method AS payment_method,
                (SELECT COUNT(*) FROM order_memos WHERE order_id = o.id) AS memo_count')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->join('payments lp', 'lp.id = (SELECT MAX(id) FROM payments WHERE order_id = o.id)', 'left');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('o.order_number', $keyword)
                ->orLike('o.receiver_name', $keyword)
                ->orLike('u.email', $keyword)
                ->groupEnd();
        }

        if ($status !== '') {
            $builder->where('o.status', $status);
        } else {
            // 결제 확정 전 주문은 관리자 주문 목록에도 노출하지 않는다.
            // 레거시 행은 /admin/order-attempts 로그 페이지에서 조회한다. (이슈 #214)
            $builder->whereNotIn('o.status', ['pending', 'expired']);
        }

        $total  = (clone $builder)->countAllResults(false);
        $orders = $builder->orderBy('o.id', 'DESC')
                          ->limit($perPage, ($page - 1) * $perPage)
                          ->get()->getResultArray();

        return [
            'items'       => $orders,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
            'perPage'     => $perPage,
        ];
    }

    /** @return array<string, mixed>|null */
    public function adminGetWithItems(int $orderId): ?array
    {
        $order = $this->db->table('orders o')
            ->select('o.*, u.email AS user_email, u.nickname AS user_nickname')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->where('o.id', $orderId)
            ->get()->getRowArray();

        if (! $order) {
            return null;
        }

        $order['items']         = $this->fetchOrderItems($orderId);
        try {
            $order['exchange_items'] = $this->db->table('exchange_items')->where('order_id', $orderId)->get()->getResultArray();
        } catch (\Throwable) {
            $order['exchange_items'] = [];
        }
        $order['payment']       = $this->db->table('payments')->where('order_id', $orderId)->orderBy('id', 'DESC')->get()->getRowArray();
        $order['statusLogs']    = $this->db->table('order_status_logs')
            ->where('order_id', $orderId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return $order;
    }

    /**
     * 관리자 대시보드 "최근 주문" 위젯용 조회.
     *
     * 결제 확정 전(pending) 시도와 레거시 만료(expired) 주문은 orders 테이블에
     * 보존만 되고 화면에는 노출하지 않는다(이슈 #214) — 행 자체를 삭제하지 않으므로
     * 여기서 조회 시점에 걸러낸다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentForDashboard(int $limit = 5): array
    {
        return $this->db->table('orders o')
            ->select('o.id, o.order_number, o.status, o.total_amount, o.created_at, u.nickname AS user_nickname')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->whereNotIn('o.status', ['pending', 'expired'])
            ->orderBy('o.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    public function updateTracking(int $orderId, string $company, string $number): bool
    {
        $order = $this->find($orderId);
        if (! $order) {
            return false;
        }

        $ok = $this->update($orderId, [
            'tracking_company' => $company,
            'tracking_number'  => $number,
        ]);

        if ($ok) {
            $this->writeStatusLog(
                $orderId,
                $order['status'],
                $order['status'],
                '운송장 등록: ' . $company . ' ' . $number
            );
        }

        return $ok;
    }

    /**
     * 세션에서 현재 작업자 정보 추출
     *
     * @return array{0: string, 1: int|null, 2: string}
     */
    private function resolveActor(): array
    {
        $session = session();
        $userId  = (int) ($session->get('user_id') ?? 0);
        $role    = $session->get('user_role') ?? '';
        $name    = $session->get('user_nickname') ?? '';

        if ($userId > 0 && $role === 'admin') {
            return ['admin', $userId, $name ?: 'admin'];
        }
        if ($userId > 0) {
            return ['member', $userId, $name ?: '회원'];
        }
        return ['system', null, 'system'];
    }

    /** 주문 상태 변경 로그 기록 */
    /**
     * 확정 실패 보상 — 소진한 쿠폰·포인트를 되돌리고 주문을 취소로 확정한다.
     *
     * 레거시 pending 주문은 생성 시점에 쿠폰을 소진하고 포인트를 차감하는데,
     * 확정 트랜잭션이 롤백돼도 그건 되돌아가지 않는다. pending 주문은 30분 뒤
     * expirePending() 이 걷어가지만 awaiting_payment(무통장)는 그 대상이 아니라
     * 영구히 묶였다 — 그래서 실패한 자리에서 바로 되돌린다.
     *
     * 주문을 취소 상태로 함께 전이시키는 것이 핵심이다. pending 으로 남겨두면
     * expirePending() 이 같은 쿠폰·포인트를 한 번 더 복구해 포인트가 두 배로
     * 환급된다(restorePoints 에는 중복 방어가 없다).
     *
     * 실패한 트랜잭션이 롤백된 뒤 호출해야 하며, 자체 트랜잭션으로 처리한다.
     *
     * @param array{pg_provider: string, pg_tid: string, method: string, amount: int, raw: array<string, mixed>}|null $charge
     *        이미 일어난 PG 청구. 무료 주문·무통장처럼 PG 거래가 없으면 null.
     */
    private function compensateFailedConfirm(int $orderId, string $note, ?array $charge = null): void
    {
        // 직전 롤백으로 트랜잭션 상태가 false 로 남아 있으면 이 트랜잭션도 롤백된다.
        $this->db->resetTransStatus();
        $this->db->transStart();

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->whereIn('status', ['pending', 'awaiting_payment'])
            ->get()->getRowArray();

        if (! $order) {
            $this->db->transComplete();
            return;
        }

        $this->restoreCoupon($order);
        $this->restorePoints($order, 'stock');

        $now = date('Y-m-d H:i:s');

        $this->db->table('orders')->where('id', $orderId)->update([
            'status'       => 'cancelled',
            'cancelled_at' => $now,
        ]);

        // 취소된 주문에 미결 결제행이 남으면 관리자 화면 상태가 어긋난다.
        $this->db->table('payments')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'ready'])
            ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);

        // PG 청구가 이미 일어났다면 그 사실을 남긴다. 재고 차감이 payments INSERT
        // 보다 먼저라 롤백되면 흔적이 통째로 사라져, 고객은 청구됐는데 시스템에는
        // 단서가 로그뿐이었다. 주문은 cancelled 인데 결제가 paid 로 남아 있는 조합이
        // 곧 "환불 필요" 신호가 된다(→ findRefundPending()).
        if ($charge !== null && $charge['pg_tid'] !== '') {
            $this->db->table('payments')->ignore(true)->insert([
                'order_id'     => $orderId,
                'pg_provider'  => $charge['pg_provider'],
                'pg_tid'       => $charge['pg_tid'],
                'method'       => $charge['method'],
                'amount'       => (int) $charge['amount'],
                'status'       => 'paid',
                'raw_response' => json_encode($charge['raw'], JSON_UNESCAPED_UNICODE),
                'paid_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $this->writeStatusLog($orderId, $order['status'], 'cancelled', $note);

        $this->db->transComplete();
    }

    /**
     * 환불이 필요한 주문 — 취소됐는데 PG 청구가 살아 있는 건.
     *
     * 정상 취소 경로(cancelOrder·markRefunded)는 결제행도 함께 정리하므로,
     * 이 조합은 확정 실패로 청구만 남은 경우에만 나온다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRefundPending(): array
    {
        return $this->refundPendingBuilder()->orderBy('o.id', 'DESC')->get()->getResultArray();
    }

    /**
     * 특정 주문의 환불 대상 결제 1건. 없으면 null.
     *
     * 관리자 화면의 "환불 필요" 표시와 실제 취소 가능 여부가 어긋나지 않도록
     * findRefundPending() 과 같은 조건을 쓴다.
     *
     * @return array<string, mixed>|null
     */
    public function findRefundPendingPayment(int $orderId): ?array
    {
        return $this->refundPendingBuilder()->where('o.id', $orderId)->get()->getRowArray();
    }

    private function refundPendingBuilder(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('orders o')
            ->select('o.id, o.order_number, o.user_id, o.cancelled_at,
                      p.id AS payment_id, p.pg_provider, p.pg_tid, p.amount')
            ->join('payments p', 'p.order_id = o.id AND p.status = "paid"', 'inner')
            ->where('o.status', 'cancelled')
            ->where('p.pg_tid IS NOT NULL', null, false);
    }

    private function writeStatusLog(int $orderId, string $from, string $to, ?string $note = null): void
    {
        [$actorType, $actorId, $actorName] = $this->resolveActor();

        $this->db->table('order_status_logs')->insert([
            'order_id'   => $orderId,
            'from_status' => $from,
            'to_status'  => $to,
            'actor_type' => $actorType,
            'actor_id'   => $actorId,
            'actor_name' => $actorName,
            'note'       => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    public function calculateShippingFee(array $items, int $totalProduct): int
    {
        // 무료배송 상품이 하나라도 있으면 본품·애드온 구분 없이 전체 주문을 무료배송으로 처리한다(이슈 #170).
        foreach ($items as $item) {
            if (($item['shipping_type'] ?? '') === 'free') {
                return 0;
            }
        }

        // 조건부 무료 기준 충족 시 전체 주문 무료배송
        foreach ($items as $item) {
            if (($item['shipping_type'] ?? '') === 'conditional'
                && (int) ($item['free_threshold'] ?? 0) > 0
                && $totalProduct >= (int) ($item['free_threshold'] ?? 0)) {
                return 0;
            }
        }

        // 충족되지 않은 경우 개별 배송비 중 최댓값 (여기 도달하면 free 상품은 없으므로 fixed/conditional만 남는다)
        $fee = 0;
        foreach ($items as $item) {
            $fee = max($fee, (int) ($item['shipping_fee'] ?? 0));
        }

        return $fee;
    }

    /**
     * 쿠폰 복구 헬퍼
     *
     * @param array<string, mixed> $order
     */
    private function restoreCoupon(array $order): void
    {
        if (! $order['coupon_id'] || (int) $order['coupon_discount_amount'] === 0) {
            return;
        }

        $this->db->query(
            'UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE id = ?',
            [$order['coupon_id']]
        );

        $builder = $this->db->table('user_coupons')
            ->where('coupon_id', $order['coupon_id'])
            ->where('status', 'used');

        // 전환 전 선점분은 주문이 아니라 시도를 가리킨다. (이슈 #214)
        // attempt 참조가 넘어온 경우에만 그 조건을 쓴다 — null 을 그대로 OR 로
        // 걸면 "order_attempt_id IS NULL" 이 되어 다른 주문의 쿠폰까지 잡는다.
        if (! empty($order['order_attempt_id'])) {
            $builder->where('order_attempt_id', (int) $order['order_attempt_id']);
        } else {
            $builder->where('order_id', $order['id']);
        }

        $uc = $builder->get()->getRowArray();

        if (! $uc) {
            return;
        }

        if ($uc['source'] === 'code') {
            $this->db->table('user_coupons')->where('id', $uc['id'])->delete();
        } else {
            $this->db->table('user_coupons')->where('id', $uc['id'])->update([
                'status'           => 'issued',
                'order_id'         => null,
                'order_attempt_id' => null,
                'used_at'          => null,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 포인트 환급 헬퍼
     *
     * @param array<string, mixed> $order
     */
    private function restorePoints(array $order, string $reason = 'cancel'): void
    {
        if ((int) $order['point_used_amount'] <= 0) {
            return;
        }

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$order['point_used_amount'], $order['user_id']]
        );

        $note = match ($reason) {
            'expire' => '주문 만료 포인트 환급',
            'stock'  => '재고 부족 주문 취소 포인트 환급',
            default  => '주문 취소 포인트 환급',
        };

        $this->db->table('point_logs')->insert([
            'user_id'    => $order['user_id'],
            'type'       => 'refund',
            'amount'     => $order['point_used_amount'],
            'order_id'   => $order['id'],
            'note'       => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 재고 차감 헬퍼 (SKU 있으면 product_skus, 없으면 products)
     * FOR UPDATE 락 + 조건부 UPDATE 패턴 유지
     */
    /**
     * @param array<string, mixed> $item
     */
    private function deductItemStock(array $item): bool
    {
        $qty = (int) $item['qty'];

        if (! empty($item['sku_id'])) {
            $skuId     = (int) $item['sku_id'];
            $productId = (int) $item['product_id'];

            $row = $this->db->query(
                'SELECT stock FROM product_skus WHERE id = ? FOR UPDATE',
                [$skuId]
            )->getRow();

            if (! $row || (int) $row->stock < $qty) {
                return false;
            }

            $this->db->query(
                'UPDATE product_skus SET stock = stock - ? WHERE id = ? AND stock >= ?',
                [$qty, $skuId, $qty]
            );

            if ($this->db->affectedRows() === 0) {
                return false;
            }

            // products.stock도 SKU 재고 차감분만큼 동기화
            $this->db->query(
                'SELECT stock FROM products WHERE id = ? FOR UPDATE',
                [$productId]
            );
            $this->db->query(
                'UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?',
                [$qty, $productId]
            );
            $this->db->query(
                'UPDATE products SET status = "sold_out" WHERE id = ? AND stock = 0 AND status = "on_sale"',
                [$productId]
            );

            return true;
        }

        $productId = (int) $item['product_id'];

        $row = $this->db->query(
            'SELECT stock FROM products WHERE id = ? FOR UPDATE',
            [$productId]
        )->getRow();

        if (! $row || (int) $row->stock < $qty) {
            return false;
        }

        $this->db->query(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?',
            [$qty, $productId, $qty]
        );

        if ($this->db->affectedRows() === 0) {
            return false;
        }

        $this->db->query(
            'UPDATE products SET status = "sold_out" WHERE id = ? AND stock = 0 AND status = "on_sale"',
            [$productId]
        );

        return true;
    }

    /**
     * 재고 복구 헬퍼 (SKU 있으면 product_skus, 없으면 products)
     *
     * @param array<string, mixed> $item
     */
    private function restoreItemStock(array $item): void
    {
        $qty = (int) $item['qty'];

        if (! empty($item['sku_id'])) {
            $this->db->query(
                'UPDATE product_skus SET stock = stock + ? WHERE id = ?',
                [$qty, (int) $item['sku_id']]
            );

            // products.stock도 SKU 복구분만큼 동기화
            $productId = (int) $item['product_id'];
            $this->db->query(
                'UPDATE products SET stock = stock + ?,
                    status = IF(status = "sold_out" AND stock + ? > 0, "on_sale", status)
                 WHERE id = ?',
                [$qty, $qty, $productId]
            );
            return;
        }

        $productId = (int) $item['product_id'];
        $this->db->query(
            'UPDATE products SET stock = stock + ?,
                status = IF(status = "sold_out" AND stock + ? > 0, "on_sale", status)
             WHERE id = ?',
            [$qty, $qty, $productId]
        );
    }
}
