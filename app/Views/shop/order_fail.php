<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-5" style="max-width:520px">

    <!-- 실패 헤더 -->
    <div class="text-center mb-5">
        <div class="rounded-circle bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-3"
             style="width:80px;height:80px">
            <i class="bi bi-x-lg text-danger" style="font-size:2.5rem"></i>
        </div>
        <h3 class="fw-bold mb-2">결제에 실패했습니다</h3>
        <?php if ($message): ?>
        <p class="text-muted mb-0"><?= esc($message) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($order): ?>
    <div class="card mb-4">
        <div class="card-body small">
            <dl class="row mb-0">
                <dt class="col-4 fw-normal text-muted">주문번호</dt>
                <dd class="col-8"><?= esc($order['order_number']) ?></dd>
                <dt class="col-4 fw-normal text-muted">결제 금액</dt>
                <dd class="col-8"><?= number_format($order['total_amount']) ?>원</dd>
                <dt class="col-4 fw-normal text-muted">주문 상태</dt>
                <dd class="col-8">
                    <span class="badge bg-secondary">
                        <?= \App\Models\OrderModel::STATUS_LABELS[$order['status']] ?? $order['status'] ?>
                    </span>
                </dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border small text-muted mb-4">
        <i class="bi bi-info-circle me-1"></i>
        결제 미완료 주문은 30분 후 자동으로 취소됩니다.
        장바구니에 상품이 보존되어 있으니 다시 시도해주세요.
    </div>

    <div class="d-flex gap-2">
        <a href="/cart" class="btn btn-outline-secondary flex-fill">장바구니로 돌아가기</a>
        <a href="/order" class="btn btn-primary flex-fill">다시 주문하기</a>
    </div>

</div>

<?= $this->endSection() ?>
