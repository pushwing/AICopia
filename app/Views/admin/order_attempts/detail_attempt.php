<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '주문 시도 상세' ?>

<?= $this->section('content') ?>

<?php
$statusBadge = [
    'pending'   => 'secondary',
    'converted' => 'success',
    'failed'    => 'danger',
    'expired'   => 'dark',
];
$statusLabel = \App\Models\OrderAttemptModel::STATUS_LABELS[$attempt['status']] ?? $attempt['status'];
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="/admin/order-attempts" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i> 목록
    </a>
    <div>
        <h5 class="fw-bold mb-0">주문 시도 상세</h5>
        <div class="text-muted small"><?= esc($attempt['order_number']) ?></div>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-primary bg-opacity-75 fs-6">주문 시도</span>
        <span class="badge bg-<?= $statusBadge[$attempt['status']] ?? 'secondary' ?> fs-6"><?= esc($statusLabel) ?></span>
    </div>
</div>

<?php if ($attempt['status'] === 'converted' && ! empty($attempt['order_id'])): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-check-circle-fill"></i>
    이 시도는 결제가 확정되어 주문으로 전환되었습니다.
    <a href="/admin/orders/<?= (int) $attempt['order_id'] ?>" class="ms-1">주문 상세로 이동 →</a>
</div>
<?php endif; ?>

<?php if (! empty($attempt['fail_reason'])): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    실패 사유: <?= esc($attempt['fail_reason']) ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- 상품 목록 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">주문 상품</div>
            <div class="card-body p-0">
                <?php if (empty($attempt['items'])): ?>
                <div class="p-3 text-muted small">상품 정보가 없습니다.</div>
                <?php else: foreach ($attempt['items'] as $item): ?>
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
                    <?= esc($attempt['receiver_name']) ?>
                    <span class="text-muted fw-normal ms-2"><?= esc($attempt['receiver_phone']) ?></span>
                </div>
                <div class="text-muted">
                    (<?= esc($attempt['zipcode']) ?>)
                    <?= esc($attempt['address1']) ?>
                    <?= ! empty($attempt['address2']) ? ' ' . esc($attempt['address2']) : '' ?>
                </div>
                <?php if (! empty($attempt['delivery_memo'])): ?>
                <div class="text-muted mt-1">배송 메모: <?= esc($attempt['delivery_memo']) ?></div>
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
                        <?= esc($attempt['user_nickname'] ?? '-') ?>
                        <?php if (! empty($attempt['user_email'])): ?>
                        <span class="text-muted ms-1">(<?= esc($attempt['user_email']) ?>)</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-4 fw-normal text-muted">상품 합계</dt>
                    <dd class="col-8"><?= number_format((int) $attempt['total_product_price']) ?>원</dd>

                    <dt class="col-4 fw-normal text-muted">배송비</dt>
                    <dd class="col-8"><?= number_format((int) $attempt['shipping_fee']) ?>원</dd>

                    <?php if ((int) ($attempt['coupon_discount_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">쿠폰 할인</dt>
                    <dd class="col-8 text-danger">- <?= number_format((int) $attempt['coupon_discount_amount']) ?>원</dd>
                    <?php endif; ?>

                    <?php if ((int) ($attempt['point_used_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">포인트 사용</dt>
                    <dd class="col-8 text-danger">- <?= number_format((int) $attempt['point_used_amount']) ?>원</dd>
                    <?php endif; ?>

                    <dt class="col-4 fw-bold text-dark border-top pt-2 mt-1">실결제액</dt>
                    <dd class="col-8 fw-bold text-primary border-top pt-2 mt-1"><?= number_format((int) $attempt['payable_amount']) ?>원</dd>

                    <?php if (! empty($attempt['pg_provider'])): ?>
                    <dt class="col-4 fw-normal text-muted mt-2">결제 수단</dt>
                    <dd class="col-8 mt-2 fw-semibold"><?= esc($pgLabels[$attempt['pg_provider']] ?? $attempt['pg_provider']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- 타임라인 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">타임스탬프</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-muted">생성</dt>
                    <dd class="col-7"><?= esc($attempt['created_at']) ?></dd>

                    <?php if (! empty($attempt['converted_at'])): ?>
                    <dt class="col-5 fw-normal text-muted">전환</dt>
                    <dd class="col-7"><?= esc($attempt['converted_at']) ?></dd>
                    <?php endif; ?>

                    <?php if (! empty($attempt['failed_at'])): ?>
                    <dt class="col-5 fw-normal text-muted">실패</dt>
                    <dd class="col-7"><?= esc($attempt['failed_at']) ?></dd>
                    <?php endif; ?>

                    <?php if (! empty($attempt['expired_at'])): ?>
                    <dt class="col-5 fw-normal text-muted">만료</dt>
                    <dd class="col-7"><?= esc($attempt['expired_at']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (! empty($attempt['fail_reason'])): ?>
        <div class="card">
            <div class="card-header fw-semibold bg-white">실패 사유</div>
            <div class="card-body small text-muted"><?= esc($attempt['fail_reason']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
