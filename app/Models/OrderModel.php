<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\GradeService;
use App\Libraries\ItemPricing;
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
     * 조건부 UPDATE 를 실행하고 실제로 반영됐는지 돌려준다.
     *
     * 검증과 소비 사이에 상태가 바뀌는 경쟁 상태를 막기 위한 "조건을 WHERE 에 걸고
     * 반영 행 수를 확인한다" 패턴의 공통 구현이다. affectedRows() 를 이 한 곳에서만
     * 읽어, 호출부마다 독립적인 판정을 얻는다.
     *
     * @param array<int, mixed> $bindings
     */
    private function runGuardedUpdate(string $sql, array $bindings): bool
    {
        $this->db->query($sql, $bindings);

        return $this->db->affectedRows() > 0;
    }

    /**
     * 결제 대기 주문 생성 — 쿠폰 확정 + 포인트 차감까지 트랜잭션 내 처리
     */
    /**
     * @param array<string, mixed>          $shippingData
     * @param array<int, array<string, mixed>> $cartItems
     */
    public function createPending(
        int $userId,
        array $shippingData,
        array $cartItems,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscountAmount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0
    ): int {
        // 옵션 추가금까지 포함한 실제 단가로 합산한다 — order_items 와 같은 계산이어야
        // total_product_price 와 SUM(order_items.subtotal) 이 어긋나지 않는다. (이슈 #124)
        $totalProduct = ItemPricing::totalProductPrice($cartItems);

        $shippingFee   = $this->calculateShippingFee($cartItems, $totalProduct);
        $totalAmount   = $totalProduct + $shippingFee;
        $payableAmount = max(0, $totalAmount - $couponDiscountAmount - $pointUsed);
        $orderNumber   = $this->generateOrderNumber();
        $now           = date('Y-m-d H:i:s');

        $this->db->transStart();

        $orderId = (int) $this->insert([
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
        ], true);

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
            $items[]   = [
                'order_id'         => $orderId,
                'product_id'       => (int) $item['product_id'],
                'sku_id'           => isset($item['sku_id']) && $item['sku_id'] ? (int) $item['sku_id'] : null,
                'parent_product_id' => isset($item['parent_product_id']) && $item['parent_product_id'] ? (int) $item['parent_product_id'] : null,
                'sku_option_label' => ($item['sku_label'] ?? '') ?: null,
                'product_name'     => $item['name'],
                'product_price'    => $price,
                'cost_price'       => $costMap[(int) $item['product_id']] ?? 0,
                'qty'              => $qty,
                'subtotal'         => $price * $qty,
                'created_at'       => $now,
            ];
        }
        $this->db->table('order_items')->insertBatch($items);

        // 주문 총액과 라인 합계는 정의상 같아야 한다. 어긋나면 청구액과 기록이
        // 따로 노는 상태이므로 주문을 만들지 않는다. (이슈 #124)
        if (array_sum(array_column($items, 'subtotal')) !== $totalProduct) {
            $this->db->transRollback();
            log_message('critical', "[Order] 금액 정합성 불일치 — order_number={$orderNumber}");

            return 0;
        }

        // 쿠폰 확정 (free_shipping은 couponDiscountAmount=배송비이므로 couponId 존재 여부로만 판단)
        //
        // 검증은 트랜잭션 밖에서 끝났으므로, 그 사이 쿠폰이 소진됐을 수 있다.
        // 아래 포인트 차감과 동일하게 조건부 UPDATE + affectedRows 검사로 확정한다. (이슈 #123)
        if ($couponId) {
            // 이 UPDATE 가 coupons 행 잠금을 잡아 동일 쿠폰에 대한 동시 요청을 직렬화한다.
            $couponClaimed = $this->runGuardedUpdate(
                'UPDATE coupons SET used_count = used_count + 1
                 WHERE id = ? AND (total_qty IS NULL OR used_count < total_qty)',
                [$couponId]
            );
            if (! $couponClaimed) {
                $this->db->transRollback();
                return 0;
            }

            if ($userCouponId) {
                $consumed = $this->runGuardedUpdate(
                    'UPDATE user_coupons SET status = ?, order_id = ?, used_at = ?, updated_at = ?
                     WHERE id = ? AND user_id = ? AND status = ?',
                    ['used', $orderId, $now, $now, $userCouponId, $userId, 'issued']
                );
                if (! $consumed) {
                    $this->db->transRollback();
                    return 0;
                }
            } else {
                // 코드 입력 — issued 상태 쿠폰이 있으면 사용 처리, 없으면 신규 INSERT
                $existingUC = $this->db->table('user_coupons')
                    ->where('user_id', $userId)
                    ->where('coupon_id', $couponId)
                    ->where('status', 'issued')
                    ->get()->getRowArray();

                if ($existingUC) {
                    $consumed = $this->runGuardedUpdate(
                        'UPDATE user_coupons SET status = ?, order_id = ?, used_at = ?, updated_at = ?
                         WHERE id = ? AND status = ?',
                        ['used', $orderId, $now, $now, $existingUC['id'], 'issued']
                    );
                    if (! $consumed) {
                        $this->db->transRollback();
                        return 0;
                    }
                } else {
                    $this->db->table('user_coupons')->insert([
                        'user_id'    => $userId,
                        'coupon_id'  => $couponId,
                        'order_id'   => $orderId,
                        'source'     => 'code',
                        'status'     => 'used',
                        'issued_at'  => $now,
                        'used_at'    => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // 포인트 차감 (FOR UPDATE + 조건부 UPDATE)
        if ($pointUsed > 0) {
            $this->db->query('SELECT point_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $affected = $this->db->query(
                'UPDATE users SET point_balance = point_balance - ? WHERE id = ? AND point_balance >= ?',
                [$pointUsed, $userId, $pointUsed]
            );
            if ($this->db->affectedRows() === 0) {
                $this->db->transRollback();
                return 0;
            }
            $this->db->table('point_logs')->insert([
                'user_id'    => $userId,
                'type'       => 'use',
                'amount'     => -$pointUsed,
                'order_id'   => $orderId,
                'note'       => '주문 포인트 사용',
                'created_at' => $now,
            ]);
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $orderId : 0;
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
     * createPending() 은 주문 생성 시점에 쿠폰을 소진하고 포인트를 차감하는데,
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

    private function generateOrderNumber(): string
    {
        $prefix = 'ORD-' . date('Ymd') . '-';
        $seq    = str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        return $prefix . $seq;
    }

    /** @param array<int, array<string, mixed>> $items */
    public function calculateShippingFee(array $items, int $totalProduct): int
    {
        // 조건부 무료 기준 충족 시 전체 주문 무료배송
        foreach ($items as $item) {
            if (($item['shipping_type'] ?? '') === 'conditional'
                && (int) ($item['free_threshold'] ?? 0) > 0
                && $totalProduct >= (int) ($item['free_threshold'] ?? 0)) {
                return 0;
            }
        }

        // 충족되지 않은 경우 개별 배송비 중 최댓값
        $fee = 0;
        foreach ($items as $item) {
            $itemFee = match ($item['shipping_type'] ?? 'free') {
                'free'    => 0,
                'fixed'   => (int) ($item['shipping_fee'] ?? 0),
                default   => (int) ($item['shipping_fee'] ?? 0),
            };
            $fee = max($fee, $itemFee);
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

        $uc = $this->db->table('user_coupons')
            ->where('coupon_id', $order['coupon_id'])
            ->where('order_id', $order['id'])
            ->where('status', 'used')
            ->get()->getRowArray();

        if (! $uc) {
            return;
        }

        if ($uc['source'] === 'code') {
            $this->db->table('user_coupons')->where('id', $uc['id'])->delete();
        } else {
            $this->db->table('user_coupons')->where('id', $uc['id'])->update([
                'status'     => 'issued',
                'order_id'   => null,
                'used_at'    => null,
                'updated_at' => date('Y-m-d H:i:s'),
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
