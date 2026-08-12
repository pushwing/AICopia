<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '레거시 주문 상세' ?>

<?= $this->section('content') ?>

<?php
$payment = $order['payment'] ?? null;
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="/admin/order-attempts" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i> 목록
    </a>
    <div>
        <h5 class="fw-bold mb-0">레거시 주문 상세</h5>
        <div class="text-muted small"><?= esc($order['order_number']) ?></div>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-secondary bg-opacity-75 fs-6">레거시 주문</span>
        <span class="badge bg-dark fs-6"><?= esc($statusLabels[$order['status']] ?? $order['status']) ?></span>
    </div>
</div>

<div class="alert alert-secondary d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-info-circle-fill"></i>
    이 주문은 결제 확정 전 상태(<?= esc($statusLabels[$order['status']] ?? $order['status']) ?>)로 남아있는 레거시 데이터입니다.
    주문 관리 목록에는 노출되지 않으며 이 로그 페이지에서만 조회할 수 있습니다.
</div>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- 상품 목록 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">주문 상품</div>
            <div class="card-body p-0">
                <?php if (empty($order['items'])): ?>
                <div class="p-3 text-muted small">상품 정보가 없습니다.</div>
                <?php else: foreach ($order['items'] as $item): ?>
                <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                    <div class="flex-grow-1 small">
                        <div class="fw-semibold mb-1"><?= esc($item['product_name'] ?? '') ?></div>
                        <?php if (! empty($item['sku_option_label'])): ?>
                        <div class="text-muted" style="font-size:.75rem;margin-bottom:.1rem">
                            <i class="bi bi-tag me-1"></i><?= esc($item['sku_option_label']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted">
                            <?= number_format((int) ($item['product_price'] ?? 0)) ?>원 × <?= (int) ($item['qty'] ?? 0) ?>개
                        </div>
                    </div>
                    <div class="fw-bold"><?= number_format((int) ($item['subtotal'] ?? 0)) ?>원</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- 배송지 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">배송지</div>
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
                <div class="text-muted mt-1">배송 메모: <?= esc($order['delivery_memo']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 금액 내역 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">금액 내역</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 fw-normal text-muted">회원</dt>
                    <dd class="col-8">
                        <?= esc($order['user_nickname'] ?? '-') ?>
                        <?php if (! empty($order['user_email'])): ?>
                        <span class="text-muted ms-1">(<?= esc($order['user_email']) ?>)</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-4 fw-normal text-muted">상품 합계</dt>
                    <dd class="col-8"><?= number_format((int) $order['total_product_price']) ?>원</dd>

                    <dt class="col-4 fw-normal text-muted">배송비</dt>
                    <dd class="col-8"><?= number_format((int) $order['shipping_fee']) ?>원</dd>

                    <?php if ((int) ($order['coupon_discount_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">쿠폰 할인</dt>
                    <dd class="col-8 text-danger">- <?= number_format((int) $order['coupon_discount_amount']) ?>원</dd>
                    <?php endif; ?>

                    <?php if ((int) ($order['point_used_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">포인트 사용</dt>
                    <dd class="col-8 text-danger">- <?= number_format((int) $order['point_used_amount']) ?>원</dd>
                    <?php endif; ?>

                    <dt class="col-4 fw-bold text-dark border-top pt-2 mt-1">실결제액</dt>
                    <dd class="col-8 fw-bold text-primary border-top pt-2 mt-1"><?= number_format((int) ($order['payable_amount'] ?? $order['total_amount'])) ?>원</dd>

                    <?php if ($payment): ?>
                    <dt class="col-4 fw-normal text-muted mt-2">결제 수단</dt>
                    <dd class="col-8 mt-2 fw-semibold"><?= esc($pgLabels[$payment['pg_provider']] ?? $payment['pg_provider']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- 타임스탬프 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">타임스탬프</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-muted">생성</dt>
                    <dd class="col-7"><?= esc($order['created_at']) ?></dd>

                    <?php if (! empty($order['updated_at'])): ?>
                    <dt class="col-5 fw-normal text-muted">수정</dt>
                    <dd class="col-7"><?= esc($order['updated_at']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
