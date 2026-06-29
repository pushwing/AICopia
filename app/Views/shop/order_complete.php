<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$payment    = $order['payment'] ?? null;
$items      = $order['items']   ?? [];
$pgLabels   = [
    'toss'     => '토스페이먼츠',
    'inicis'   => 'KG이니시스',
    'nicepay'  => '나이스페이',
    'kakaopay' => '카카오페이',
    'naverpay' => '네이버페이',
    'payco'    => 'PAYCO',
];
$methodLabels = [
    'card'            => '신용/체크카드',
    'virtual_account' => '가상계좌',
    'transfer'        => '계좌이체',
    'phone'           => '휴대폰',
    'kakaopay'        => '카카오페이',
    'naverpay'        => '네이버페이',
    'payco'           => 'PAYCO',
];
?>

<div class="container py-5" style="max-width:680px">

    <!-- 완료 헤더 -->
    <div class="text-center mb-5">
        <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3"
             style="width:80px;height:80px">
            <i class="bi bi-check-lg text-success" style="font-size:2.5rem"></i>
        </div>
        <h3 class="fw-bold mb-1">주문이 완료되었습니다</h3>
        <p class="text-muted">
            주문번호 <strong class="text-dark"><?= esc($order['order_number']) ?></strong>
        </p>
    </div>

    <!-- 주문 상품 -->
    <div class="card mb-3">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-bag-check me-2 text-success"></i>주문 상품
        </div>
        <div class="card-body p-0">
            <?php foreach ($items as $item): ?>
            <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                <div class="flex-grow-1 small">
                    <div class="fw-semibold mb-1"><?= esc($item['product_name']) ?></div>
                    <div class="text-muted">
                        <?= number_format($item['product_price']) ?>원 × <?= (int) $item['qty'] ?>개
                    </div>
                </div>
                <div class="fw-bold text-end">
                    <?= number_format($item['subtotal']) ?>원
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 결제 정보 -->
    <div class="card mb-3">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-credit-card me-2 text-success"></i>결제 정보
        </div>
        <div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-4 fw-normal text-muted">상품 합계</dt>
                <dd class="col-8"><?= number_format($order['total_product_price']) ?>원</dd>

                <dt class="col-4 fw-normal text-muted">배송비</dt>
                <dd class="col-8"><?= (int) $order['shipping_fee'] > 0 ? number_format($order['shipping_fee']) . '원' : '무료' ?></dd>

                <?php if ((int) $order['coupon_discount_amount'] > 0): ?>
                <dt class="col-4 fw-normal text-muted">쿠폰 할인</dt>
                <dd class="col-8 text-danger">- <?= number_format($order['coupon_discount_amount']) ?>원</dd>
                <?php endif; ?>

                <?php if ((int) $order['point_used_amount'] > 0): ?>
                <dt class="col-4 fw-normal text-muted">포인트 사용</dt>
                <dd class="col-8 text-danger">- <?= number_format($order['point_used_amount']) ?>원</dd>
                <?php endif; ?>

                <dt class="col-4 fw-bold text-dark">최종 결제 금액</dt>
                <dd class="col-8 fw-bold text-primary fs-6"><?= number_format($order['payable_amount'] ?? $order['total_amount']) ?>원</dd>

                <?php if ($payment): ?>
                <dt class="col-4 fw-normal text-muted mt-2">결제 수단</dt>
                <dd class="col-8 mt-2">
                    <?= esc($pgLabels[$payment['pg_provider']] ?? $payment['pg_provider']) ?>
                    <?php if ($payment['method']): ?>
                    · <?= esc($methodLabels[$payment['method']] ?? $payment['method']) ?>
                    <?php endif; ?>
                </dd>

                <dt class="col-4 fw-normal text-muted">결제 일시</dt>
                <dd class="col-8"><?= $payment['paid_at'] ? date('Y년 n월 j일 G시 i분', strtotime($payment['paid_at'])) : '-' ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- 배송지 -->
    <div class="card mb-4">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-geo-alt me-2 text-success"></i>배송지
        </div>
        <div class="card-body small">
            <div class="fw-semibold mb-1">
                <?= esc($order['receiver_name']) ?>
                <span class="text-muted fw-normal ms-2"><?= esc($order['receiver_phone']) ?></span>
            </div>
            <div class="text-muted">
                (<?= esc($order['zipcode']) ?>)
                <?= esc($order['address1']) ?>
                <?= ! empty($order['address2']) ? ' ' . esc($order['address2']) : '' ?>
            </div>
            <?php if (! empty($order['delivery_memo'])): ?>
            <div class="text-muted mt-1">
                <i class="bi bi-chat-left-text me-1"></i><?= esc($order['delivery_memo']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 버튼 -->
    <div class="d-flex gap-2">
        <a href="/shop" class="btn btn-outline-secondary flex-fill">쇼핑 계속하기</a>
        <a href="/mypage/orders" class="btn btn-primary flex-fill">주문 내역 보기</a>
    </div>

</div>

<?= $this->endSection() ?>
