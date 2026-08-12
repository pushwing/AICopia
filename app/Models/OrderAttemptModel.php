<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\ItemPricing;
use CodeIgniter\Model;

/**
 * 주문 시도 — 결제가 확정되기 전 단계를 전담한다.
 *
 * orders 에는 결제가 확정된 주문만 남긴다(이슈 #214). 주문서 제출 시점의
 * 금액·배송지·상품 스냅샷은 이 테이블에 쌓이고, 결제가 확정되는 순간
 * OrderModel::convertAttempt() 가 orders 로 옮긴다.
 *
 * 쿠폰·포인트 선점은 orders 시절과 동일하게 이 시점에 일어난다. 결제창이 떠
 * 있는 동안 같은 쿠폰이 다른 창에서 또 쓰이는 걸 막아야 하기 때문이다(이슈 #123).
 * 소유자 키만 order_id 에서 order_attempt_id 로 바뀌었다.
 */
class OrderAttemptModel extends Model
{
    protected $table         = 'order_attempts';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id', 'order_number', 'status',
        'total_product_price', 'shipping_fee', 'total_amount',
        'coupon_id', 'coupon_discount_amount', 'point_used_amount', 'point_earned_amount', 'payable_amount',
        'receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2', 'delivery_memo',
        'items_snapshot', 'pg_provider', 'order_id', 'fail_reason',
        'converted_at', 'failed_at', 'expired_at',
    ];

    public const STATUS_LABELS = [
        'pending'   => '결제 진행 중',
        'converted' => '주문 생성됨',
        'failed'    => '결제 실패·이탈',
        'expired'   => '시간 초과',
    ];

    /**
     * 주문 시도 생성 + 쿠폰·포인트 선점.
     *
     * @param array<string, mixed>             $shippingData
     * @param array<int, array<string, mixed>> $cartItems
     *
     * @return int attempt id. 빈 장바구니, 선점 실패(쿠폰 소진·포인트 부족), 금액 불일치,
     *             주문번호 채번 실패면 0.
     */
    public function createAttempt(
        int $userId,
        array $shippingData,
        array $cartItems,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscountAmount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0,
        ?string $pgProvider = null
    ): int {
        // 빈 스냅샷으로 PG 결제창이 뜨는 것을 결제 전에 차단한다.
        if ($cartItems === []) {
            return 0;
        }

        $orderModel = new OrderModel();

        // 옵션 추가금까지 포함한 실제 단가로 합산한다 — items_snapshot 과
        // total_product_price 가 어긋나지 않아야 한다. (이슈 #124)
        $totalProduct  = ItemPricing::totalProductPrice($cartItems);
        $shippingFee   = $orderModel->calculateShippingFee($cartItems, $totalProduct);
        $totalAmount   = $totalProduct + $shippingFee;
        $payableAmount = max(0, $totalAmount - $couponDiscountAmount - $pointUsed);
        $now           = date('Y-m-d H:i:s');

        // order_attempts.order_number 와 orders.order_number 는 서로 다른 UNIQUE
        // 인덱스다. attempt 쪽만 비어 있는 번호를 확정하면, 결제가 끝난 뒤 전환
        // 시점에 orders 쪽에서 UNIQUE 위반이 나 "결제는 됐는데 주문이 없는" 상태가
        // 될 수 있다 — 두 테이블 모두에서 비어 있는 번호만 채번한다.
        $orderNumber = $this->generateOrderNumber();
        if ($orderNumber === null) {
            log_message('critical', '[OrderAttempt] 주문번호 채번 실패 — 10회 연속 충돌');

            return 0;
        }

        $items = $this->buildItemsSnapshot($cartItems);

        // 총액과 라인 합계는 정의상 같아야 한다. 어긋나면 청구액과 기록이 따로
        // 노는 상태이므로 시도 자체를 만들지 않는다. (이슈 #124)
        if (array_sum(array_column($items, 'subtotal')) !== $totalProduct) {
            log_message('critical', "[OrderAttempt] 금액 정합성 불일치 — order_number={$orderNumber}");

            return 0;
        }

        $this->db->transStart();

        // generateOrderNumber() 가 직전에 두 테이블 모두 비어 있음을 확인했지만, 그
        // 확인과 이 INSERT 사이에 다른 요청이 같은 번호를 먼저 잡는 잔여 경합은 여전히
        // 남는다 — order_attempts 의 UNIQUE 인덱스가 최종 방어선이다.
        //
        // 이 INSERT 는 예외를 던지지 않는다: DBDebug=true(app/Config/Database.php:36,174)
        // 라도, 바로 위 transStart() 로 이미 트랜잭션이 열려 있는 상태(transDepth !== 0)
        // 에서는 CodeIgniter 가 UNIQUE 위반을 예외로 다시 던지지 않고 insert() 가
        // false 를 반환하도록 삼킨다(BaseConnection::query() — transException 을 켜지
        // 않는 한 항상 이렇다. 트랜잭션 밖에서 단독 호출하면 예외가 던져진다). 이는
        // try/catch 로 감싸봤어도 PHPStan 이 "Dead catch" 로 잡아낼 만큼 실측·정적
        // 분석 모두로 확인된 동작이다.
        //
        // 대신 실패는 두 겹으로 이미 0 으로 수렴한다: insert() 가 false 를 반환하면
        // (int) false === 0 이라 $attemptId 자체가 0 이 되고, 실패한 쿼리는
        // handleTransStatus() 를 통해 $this->db->transStatus() 를 false 로 남기므로
        // 아래 transComplete() 이후의 최종 반환문이 $attemptId 값과 무관하게 0 을
        // 반환한다.
        $attemptId = (int) $this->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => 'pending',
            'total_product_price'    => $totalProduct,
            'shipping_fee'           => $shippingFee,
            'total_amount'           => $totalAmount,
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => $couponDiscountAmount,
            'point_used_amount'      => $pointUsed,
            'point_earned_amount'    => $pointEarned,
            'payable_amount'         => $payableAmount,
            'receiver_name'          => $shippingData['receiver_name'],
            'receiver_phone'         => $shippingData['receiver_phone'],
            'zipcode'                => $shippingData['zipcode'],
            'address1'               => $shippingData['address1'],
            'address2'               => $shippingData['address2'] ?? null,
            'delivery_memo'          => $shippingData['delivery_memo'] ?? null,
            'items_snapshot'         => json_encode($items, JSON_UNESCAPED_UNICODE),
            'pg_provider'            => $pgProvider,
        ], true);

        if (! $this->preemptCoupon($attemptId, $userId, $couponId, $userCouponId, $now)) {
            $this->db->transRollback();

            return 0;
        }

        if (! $this->preemptPoints($attemptId, $userId, $pointUsed, $now)) {
            $this->db->transRollback();

            return 0;
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $attemptId : 0;
    }

    /**
     * order_items 로 그대로 전환 가능한 라인 배열을 만든다(order_id 만 비어 있다).
     *
     * @param array<int, array<string, mixed>> $cartItems
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItemsSnapshot(array $cartItems): array
    {
        $productIds = array_column($cartItems, 'product_id');
        $costMap    = [];
        if ($productIds !== []) {
            $rows = $this->db->table('products')
                ->select('id, cost_price')
                ->whereIn('id', $productIds)
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $costMap[(int) $row['id']] = (float) $row['cost_price'];
            }
        }

        $items = [];
        foreach ($cartItems as $item) {
            $price = ItemPricing::unitPrice($item);
            $qty   = (int) $item['qty'];

            $items[] = [
                'product_id'        => (int) $item['product_id'],
                'sku_id'            => isset($item['sku_id']) && $item['sku_id'] ? (int) $item['sku_id'] : null,
                'parent_product_id' => isset($item['parent_product_id']) && $item['parent_product_id'] ? (int) $item['parent_product_id'] : null,
                'sku_option_label'  => ($item['sku_label'] ?? '') ?: null,
                'product_name'      => $item['name'],
                'product_price'     => $price,
                'cost_price'        => $costMap[(int) $item['product_id']] ?? 0,
                'qty'               => $qty,
                'subtotal'          => $price * $qty,
            ];
        }

        return $items;
    }

    /**
     * 쿠폰 선점. 트랜잭션 안에서 호출해야 한다.
     *
     * coupons 에 대한 조건부 UPDATE 가 행 잠금을 잡아 동일 쿠폰의 동시 요청을
     * 직렬화한다 — 이 잠금이 이슈 #123(쿠폰 이중 사용)의 방어선이다.
     */
    private function preemptCoupon(int $attemptId, int $userId, ?int $couponId, ?int $userCouponId, string $now): bool
    {
        if (! $couponId) {
            return true;
        }

        $claimed = $this->runGuardedUpdate(
            'UPDATE coupons SET used_count = used_count + 1
             WHERE id = ? AND (total_qty IS NULL OR used_count < total_qty)',
            [$couponId]
        );
        if (! $claimed) {
            return false;
        }

        if ($userCouponId) {
            return $this->runGuardedUpdate(
                'UPDATE user_coupons SET status = ?, order_attempt_id = ?, used_at = ?, updated_at = ?
                 WHERE id = ? AND user_id = ? AND status = ?',
                ['used', $attemptId, $now, $now, $userCouponId, $userId, 'issued']
            );
        }

        // 코드 입력 — issued 상태 쿠폰이 있으면 사용 처리, 없으면 신규 INSERT
        $existing = $this->db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->where('status', 'issued')
            ->get()->getRowArray();

        if ($existing) {
            return $this->runGuardedUpdate(
                'UPDATE user_coupons SET status = ?, order_attempt_id = ?, used_at = ?, updated_at = ?
                 WHERE id = ? AND status = ?',
                ['used', $attemptId, $now, $now, $existing['id'], 'issued']
            );
        }

        $this->db->table('user_coupons')->insert([
            'user_id'          => $userId,
            'coupon_id'        => $couponId,
            'order_id'         => null,
            'order_attempt_id' => $attemptId,
            'source'           => 'code',
            'status'           => 'used',
            'issued_at'        => $now,
            'used_at'          => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return true;
    }

    /** 포인트 선점 (FOR UPDATE 락 + 조건부 UPDATE). 트랜잭션 안에서 호출해야 한다. */
    private function preemptPoints(int $attemptId, int $userId, int $pointUsed, string $now): bool
    {
        if ($pointUsed <= 0) {
            return true;
        }

        $this->db->query('SELECT point_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
        $this->db->query(
            'UPDATE users SET point_balance = point_balance - ? WHERE id = ? AND point_balance >= ?',
            [$pointUsed, $userId, $pointUsed]
        );
        if ($this->db->affectedRows() === 0) {
            return false;
        }

        $this->db->table('point_logs')->insert([
            'user_id'          => $userId,
            'type'             => 'use',
            'amount'           => -$pointUsed,
            'order_id'         => null,
            'order_attempt_id' => $attemptId,
            'note'             => '주문 포인트 사용',
            'created_at'       => $now,
        ]);

        return true;
    }

    /**
     * 전환을 위한 원자적 클레임.
     *
     * 조건부 UPDATE 가 행 잠금을 잡아 동시 콜백을 직렬화한다. 두 번째 요청은
     * affectedRows 가 0 이라 null 을 받는다 — orders.status='pending' 을 조건으로
     * 걸던 기존 confirmPaid() 의 멱등성 가드를 그대로 옮긴 것이다.
     *
     * @return array<string, mixed>|null 클레임에 성공한 attempt. 이미 처리됐으면 null.
     */
    public function claimForConversion(int $attemptId): ?array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE order_attempts SET status = ?, converted_at = ?, updated_at = ?
             WHERE id = ? AND status = ?',
            ['converted', $now, $now, $attemptId, 'pending']
        );

        if ($this->db->affectedRows() === 0) {
            return null;
        }

        $attempt = $this->db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        return $attempt === null ? null : $this->withItems($attempt);
    }

    /** 전환으로 만들어진 주문을 시도에 연결한다. */
    public function linkOrder(int $attemptId, int $orderId): void
    {
        $this->db->table('order_attempts')->where('id', $attemptId)->update([
            'order_id'   => $orderId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 결제 실패·이탈 확정 + 쿠폰·포인트 복구.
     *
     * 상태 전이를 조건부 UPDATE 로 먼저 확정하고, 성공했을 때만 복구를 돌린다.
     * 그래서 두 번 불려도 포인트가 두 번 환급되지 않는다.
     *
     * @return bool 이번 호출이 실제로 복구를 수행했으면 true
     */
    public function markFailed(int $attemptId, string $reason): bool
    {
        return $this->finalizeStale($attemptId, 'failed', $reason);
    }

    /**
     * 해당 회원의 진행 중 시도를 모두 실패 처리하고 쿠폰·포인트를 되돌린다.
     *
     * 나이스페이처럼 취소 URL 이 없거나 사용자가 브라우저를 그냥 닫으면 어떤 콜백도
     * 오지 않아 선점이 남는다. 주문서에 다시 들어온다는 건 그 시도를 포기했다는
     * 뜻이므로 이 시점에 걷어낸다. (이슈 #214)
     *
     * @return int 실제로 걷어낸 건수
     */
    public function failPendingForUser(int $userId, string $reason): int
    {
        $rows = $this->db->table('order_attempts')
            ->select('id')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->get()->getResultArray();

        $count = 0;
        foreach ($rows as $row) {
            // markFailed() 가 상태 전이(조건부 UPDATE)와 복구를 원자적으로 처리한다 —
            // 여기서 복구 로직을 새로 쓰지 않는다.
            if ($this->markFailed((int) $row['id'], $reason)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * N분 이상 지난 pending 시도를 만료 처리한다.
     *
     * 결제 실패 콜백이 오지 않은 경우의 안전망이다. 정상 이탈은 markFailed() 가
     * 즉시 걷어가므로 여기까지 오는 건 콜백 자체가 유실된 경우다.
     *
     * @return int 실제로 만료 처리된 건수
     */
    public function expireStale(int $minutesOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutesOld} minutes"));

        $rows = $this->db->table('order_attempts')
            ->select('id')
            ->where('status', 'pending')
            ->where('created_at <', $cutoff)
            ->get()->getResultArray();

        $count = 0;
        foreach ($rows as $row) {
            if ($this->finalizeStale((int) $row['id'], 'expired', '결제 미완료 자동 만료')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 사용자 소유의 진행 중 시도 1건. 콜백에서 쓴다.
     *
     * @return array<string, mixed>|null
     */
    public function findPendingForUser(int $attemptId, int $userId): ?array
    {
        $attempt = $this->db->table('order_attempts')
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->get()->getRowArray();

        return $attempt === null ? null : $this->withItems($attempt);
    }

    /**
     * pending 시도를 종료 상태로 확정하고 쿠폰·포인트를 되돌린다.
     *
     * 상태 전이가 이번 호출에서 실제로 일어났을 때만 복구한다 — 이 순서가
     * 이중 환급을 구조적으로 막는다.
     */
    private function finalizeStale(int $attemptId, string $status, string $reason): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->transStart();

        $column = $status === 'failed' ? 'failed_at' : 'expired_at';
        $this->db->query(
            "UPDATE order_attempts SET status = ?, {$column} = ?, fail_reason = ?, updated_at = ?
             WHERE id = ? AND status = ?",
            [$status, $now, $reason, $now, $attemptId, 'pending']
        );

        if ($this->db->affectedRows() === 0) {
            $this->db->transComplete();

            return false;
        }

        $attempt = $this->db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->restoreCoupon($attempt);
        $this->restorePoints($attempt, $status);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * 선점한 쿠폰을 되돌린다.
     *
     * @param array<string, mixed> $attempt
     */
    private function restoreCoupon(array $attempt): void
    {
        // 선점(preemptCoupon)은 coupon_id 유무만으로 일어나고 할인 금액과는
        // 무관하다 — 무료배송 쿠폰을 배송비 0원 주문에 쓰면 할인액이 0이 될 수
        // 있는데, 여기서 금액 조건을 추가로 걸면 그런 케이스만 복구가 누락돼
        // coupons.used_count 가 부풀고 user_coupons 가 'used' 로 영구히 묶인다.
        // 복구 조건은 반드시 선점 조건과 대칭이어야 한다.
        if (! $attempt['coupon_id']) {
            return;
        }

        $this->db->query(
            'UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE id = ?',
            [$attempt['coupon_id']]
        );

        $uc = $this->db->table('user_coupons')
            ->where('coupon_id', $attempt['coupon_id'])
            ->where('order_attempt_id', $attempt['id'])
            ->where('status', 'used')
            ->get()->getRowArray();

        if (! $uc) {
            return;
        }

        // 코드 입력으로 그 자리에서 만들어진 행은 되돌릴 원래 상태가 없다.
        if ($uc['source'] === 'code') {
            $this->db->table('user_coupons')->where('id', $uc['id'])->delete();

            return;
        }

        $this->db->table('user_coupons')->where('id', $uc['id'])->update([
            'status'           => 'issued',
            'order_attempt_id' => null,
            'used_at'          => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 선점한 포인트를 환급한다.
     *
     * @param array<string, mixed> $attempt
     */
    private function restorePoints(array $attempt, string $status): void
    {
        $used = (int) $attempt['point_used_amount'];
        if ($used <= 0) {
            return;
        }

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$used, $attempt['user_id']]
        );

        $this->db->table('point_logs')->insert([
            'user_id'          => $attempt['user_id'],
            'type'             => 'refund',
            'amount'           => $used,
            'order_id'         => null,
            'order_attempt_id' => $attempt['id'],
            'note'             => $status === 'expired' ? '주문 만료 포인트 환급' : '결제 미완료 포인트 환급',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * items_snapshot 을 디코드해 items 키로 붙인다. PG 어댑터가 기대하는
     * 주문 배열 형태(order_number·payable_amount·receiver_name·items)를 만족시킨다.
     *
     * @param array<string, mixed> $attempt
     *
     * @return array<string, mixed>
     */
    public function withItems(array $attempt): array
    {
        $decoded = json_decode((string) ($attempt['items_snapshot'] ?? '[]'), true);

        // `?:` 는 디코드 실패(false)와 정상 빈 배열([])을 구분하지 못한다. 스냅샷이
        // 깨진 채로 조용히 [] 가 되면, 전환 시점에 상품 라인이 0건인 주문이 결제
        // 완료 상태로 만들어질 수 있다 — 실패는 반드시 로깅한다.
        if (! is_array($decoded)) {
            $attemptId = $attempt['id'] ?? '?';
            log_message('critical', "[OrderAttempt] items_snapshot 디코드 실패 — attempt_id={$attemptId}");
            $decoded = [];
        }

        $attempt['items'] = $decoded;

        return $attempt;
    }

    /** @param array<int, mixed> $bindings */
    private function runGuardedUpdate(string $sql, array $bindings): bool
    {
        $this->db->query($sql, $bindings);

        return $this->db->affectedRows() > 0;
    }

    /**
     * order_attempts 와 orders 양쪽에 없는 주문번호를 채번한다.
     * 최대 10회 시도하며, 모두 충돌하면 null 을 반환한다.
     *
     * 동시 요청이 같은 후보를 고르는 경합은 order_attempts 의 UNIQUE 인덱스가
     * 최종 방어선으로 잡는다(INSERT 실패 → 트랜잭션 롤백, createAttempt() 의
     * INSERT 주변 주석 참고) — 결제 이전이라 허용된다.
     */
    private function generateOrderNumber(): ?string
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = $this->orderNumberCandidate();

            $existsInAttempts = $this->db->table('order_attempts')
                ->where('order_number', $candidate)
                ->countAllResults() > 0;
            $existsInOrders = $this->db->table('orders')
                ->where('order_number', $candidate)
                ->countAllResults() > 0;

            if (! $existsInAttempts && ! $existsInOrders) {
                return $candidate;
            }
        }

        return null;
    }

    /** 후보 주문번호 1개. 테스트가 시퀀스를 주입할 수 있도록 분리돼 있다. */
    protected function orderNumberCandidate(): string
    {
        return 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    }
}
