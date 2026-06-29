<?php

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
     * 결제 대기 주문 생성 — 쿠폰 확정 + 포인트 차감까지 트랜잭션 내 처리
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
        $totalProduct = 0;
        foreach ($cartItems as $item) {
            $price        = (int) ($item['discount_price'] ?? $item['price']);
            $totalProduct += $price * (int) $item['qty'];
        }

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
        if (! empty($productIds)) {
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
            $basePrice = (int) ($item['discount_price'] ?? $item['price']);
            $priceDiff = (int) ($item['price_diff'] ?? 0);
            $price     = $basePrice + $priceDiff;
            $qty       = (int) $item['qty'];
            $items[]   = [
                'order_id'         => $orderId,
                'product_id'       => (int) $item['product_id'],
                'sku_id'           => isset($item['sku_id']) && $item['sku_id'] ? (int) $item['sku_id'] : null,
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

        // 쿠폰 확정 (free_shipping은 couponDiscountAmount=배송비이므로 couponId 존재 여부로만 판단)
        if ($couponId) {
            $this->db->query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$couponId]);

            if ($userCouponId) {
                $this->db->table('user_coupons')
                    ->where('id', $userCouponId)
                    ->where('user_id', $userId)
                    ->where('status', 'issued')
                    ->update(['status' => 'used', 'order_id' => $orderId, 'used_at' => $now, 'updated_at' => $now]);
            } else {
                // 코드 입력 — issued 상태 쿠폰이 있으면 사용 처리, 없으면 신규 INSERT
                $existingUC = $this->db->table('user_coupons')
                    ->where('user_id', $userId)
                    ->where('coupon_id', $couponId)
                    ->where('status', 'issued')
                    ->get()->getRowArray();

                if ($existingUC) {
                    $this->db->table('user_coupons')
                        ->where('id', $existingUC['id'])
                        ->update(['status' => 'used', 'order_id' => $orderId, 'used_at' => $now, 'updated_at' => $now]);
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
     * 결제 확정 — PG 콜백 수신 후 호출
     */
    public function confirmPaid(int $orderId, string $pgProvider, string $pgTid, string $method, array $rawResponse): bool
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

        $this->writeStatusLog($orderId, 'pending', 'paid', 'PG 결제 확인 (' . $pgProvider . ')');

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

            $this->writeStatusLog($order['id'], 'pending', 'expired', '미결제 자동 만료');

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
        if (! $order) return false;

        if (($allowed[$order['status']] ?? null) !== $newStatus) {
            return false;
        }

        if ($newStatus !== 'delivered' || (int) $order['point_earned_amount'] === 0) {
            $fields = ['status' => $newStatus];
            if ($newStatus === 'delivered') $fields['delivered_at'] = date('Y-m-d H:i:s');
            $ok = (bool) $this->update($orderId, $fields);
            if ($ok) $this->writeStatusLog($orderId, $order['status'], $newStatus);
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

        $this->writeStatusLog($orderId, $order['status'], 'delivered',
            '배송 완료 확인 · 포인트 ' . number_format((int) $order['point_earned_amount']) . '원 적립');

        $this->db->transComplete();
        $ok = $this->db->transStatus();

        // 배송완료 후 등급 승급 체크 (트랜잭션 외부)
        if ($ok) {
            try {
                $settings = cache()->get('site_settings') ?? [];
                (new GradeService())->checkAndUpgrade((int) $order['user_id'], $settings);
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
        if (! $order) return false;

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
        if (! array_key_exists($reasonCode, self::RETURN_REASON_CODES)) return false;

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'delivered')
            ->get()->getRowArray();

        if (! $order) return false;

        // delivered_at 기준 7일 초과 시 거부 (컬럼이 없는 구 주문은 허용)
        if (! empty($order['delivered_at'])) {
            if (time() > strtotime($order['delivered_at']) + 7 * 24 * 3600) {
                return false;
            }
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
        if (! $order) return false;

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
        if (! $order) return false;

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
        if (! $order) return false;

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
        if (! array_key_exists($reasonCode, self::EXCHANGE_REASON_CODES)) return false;

        $order = $this->db->table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'delivered')
            ->get()->getRowArray();

        if (! $order) return false;

        if (! empty($order['delivered_at'])) {
            if (time() > strtotime($order['delivered_at']) + 7 * 24 * 3600) {
                return false;
            }
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
        if (! $order) return false;

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
        if (! $order) return false;

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
    public function completeExchange(int $orderId, array $exchangeItems, array $tracking = []): bool
    {
        $order = $this->where('id', $orderId)->where('status', 'exchange_approved')->first();
        if (! $order) return false;

        if (empty($exchangeItems)) return false;

        // 원주문 금액과 대체품 금액 일치 검증
        $originalTotal = (int) $this->db->table('order_items')
            ->selectSum('subtotal')
            ->where('order_id', $orderId)
            ->get()->getRow()->subtotal;

        $exchangeTotal = 0;
        foreach ($exchangeItems as $item) {
            $exchangeTotal += (int) $item['product_price'] * (int) $item['qty'];
        }

        if ($originalTotal !== $exchangeTotal) return false;

        $this->db->transStart();

        $now = date('Y-m-d H:i:s');
        foreach ($exchangeItems as $item) {
            $qty   = (int) $item['qty'];
            $price = (int) $item['product_price'];
            $row   = [
                'order_id'         => $orderId,
                'product_id'       => (int) $item['product_id'],
                'sku_id'           => ! empty($item['sku_id']) ? (int) $item['sku_id'] : null,
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

    /** 주문 상세 */
    public function getWithItems(int $orderId, int $userId): ?array
    {
        $order = $this->where('id', $orderId)->where('user_id', $userId)->first();
        if (! $order) return null;

        $order['items']   = $this->fetchOrderItems($orderId);
        $order['payment'] = $this->db->table('payments')->where('order_id', $orderId)->orderBy('id', 'DESC')->get()->getRowArray();

        return $order;
    }

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

    public function adminGetWithItems(int $orderId): ?array
    {
        $order = $this->db->table('orders o')
            ->select('o.*, u.email AS user_email, u.nickname AS user_nickname')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->where('o.id', $orderId)
            ->get()->getRowArray();

        if (! $order) return null;

        $order['items']         = $this->fetchOrderItems($orderId);
        try {
            $order['exchange_items'] = $this->db->table('exchange_items')->where('order_id', $orderId)->get()->getResultArray();
        } catch (\Throwable $e) {
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
        if (! $order) return false;

        $ok = (bool) $this->update($orderId, [
            'tracking_company' => $company,
            'tracking_number'  => $number,
        ]);

        if ($ok) {
            $this->writeStatusLog($orderId, $order['status'], $order['status'],
                '운송장 등록: ' . $company . ' ' . $number);
        }

        return $ok;
    }

    /** 세션에서 현재 작업자 정보 추출 */
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

    /** 쿠폰 복구 헬퍼 */
    private function restoreCoupon(array $order): void
    {
        if (! $order['coupon_id'] || (int) $order['coupon_discount_amount'] === 0) return;

        $this->db->query(
            'UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE id = ?',
            [$order['coupon_id']]
        );

        $uc = $this->db->table('user_coupons')
            ->where('coupon_id', $order['coupon_id'])
            ->where('order_id', $order['id'])
            ->where('status', 'used')
            ->get()->getRowArray();

        if (! $uc) return;

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

    /** 포인트 환급 헬퍼 */
    private function restorePoints(array $order, string $reason = 'cancel'): void
    {
        if ((int) $order['point_used_amount'] <= 0) return;

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$order['point_used_amount'], $order['user_id']]
        );

        $note = match ($reason) {
            'expire' => '주문 만료 포인트 환급',
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

            if (! $row || (int) $row->stock < $qty) return false;

            $this->db->query(
                'UPDATE product_skus SET stock = stock - ? WHERE id = ? AND stock >= ?',
                [$qty, $skuId, $qty]
            );

            if ($this->db->affectedRows() === 0) return false;

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

        if (! $row || (int) $row->stock < $qty) return false;

        $this->db->query(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?',
            [$qty, $productId, $qty]
        );

        if ($this->db->affectedRows() === 0) return false;

        $this->db->query(
            'UPDATE products SET status = "sold_out" WHERE id = ? AND stock = 0 AND status = "on_sale"',
            [$productId]
        );

        return true;
    }

    /**
     * 재고 복구 헬퍼 (SKU 있으면 product_skus, 없으면 products)
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
