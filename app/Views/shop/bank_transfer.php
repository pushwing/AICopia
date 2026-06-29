<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-5" style="max-width:560px">

    <?php if ($order['status'] === 'paid'): ?>
    <!-- 입금 확인 완료 -->
    <div class="text-center mb-4">
        <div class="display-6 mb-2">✅</div>
        <h4 class="fw-bold">입금이 확인되었습니다</h4>
        <p class="text-muted">주문이 결제 완료 처리되었습니다.</p>
    </div>
    <?php else: ?>
    <!-- 입금 대기 중 -->
    <div class="text-center mb-4">
        <div class="display-6 mb-2">🏦</div>
        <h4 class="fw-bold">입금 계좌 안내</h4>
        <p class="text-muted small">아래 계좌로 입금하시면 확인 후 주문이 처리됩니다.</p>
    </div>

    <!-- 계좌 정보 카드 -->
    <div class="card border-primary mb-4">
        <div class="card-body py-4">
            <dl class="row mb-0">
                <dt class="col-4 text-muted fw-normal small">은행</dt>
                <dd class="col-8 fw-semibold"><?= esc($settings['bank_name'] ?? '—') ?></dd>

                <dt class="col-4 text-muted fw-normal small">계좌번호</dt>
                <dd class="col-8">
                    <span class="fw-bold fs-5 font-monospace" id="accountNumber">
                        <?= esc($settings['bank_account'] ?? '—') ?>
                    </span>
                    <?php if (! empty($settings['bank_account'])): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="btnCopy">복사</button>
                    <?php endif; ?>
                </dd>

                <dt class="col-4 text-muted fw-normal small">예금주</dt>
                <dd class="col-8 fw-semibold"><?= esc($settings['bank_holder'] ?? '—') ?></dd>

                <dt class="col-4 text-muted fw-normal small border-top pt-2 mt-1">입금 금액</dt>
                <dd class="col-8 fw-bold text-primary fs-5 border-top pt-2 mt-1">
                    <?= number_format((int) ($order['payable_amount'] ?? $order['total_amount'])) ?>원
                </dd>
            </dl>
        </div>
    </div>

    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        입금자명을 <strong><?= esc($order['receiver_name']) ?></strong> 으로 정확히 입력해 주세요.<br>
        입금 확인 후 영업일 기준 1~2일 내 발송됩니다.
    </div>
    <?php endif; ?>

    <!-- 주문 요약 -->
    <div class="card mb-4">
        <div class="card-header fw-semibold bg-white small">주문 정보</div>
        <div class="card-body small">
            <div class="d-flex justify-content-between mb-1">
                <span class="text-muted">주문 번호</span>
                <span class="font-monospace"><?= esc($order['order_number']) ?></span>
            </div>
            <?php foreach ($order['items'] as $item): ?>
            <div class="d-flex justify-content-between text-muted mb-1">
                <span class="text-truncate me-2"><?= esc($item['product_name']) ?> × <?= (int) $item['qty'] ?></span>
                <span class="flex-shrink-0"><?= number_format($item['subtotal']) ?>원</span>
            </div>
            <?php endforeach; ?>
            <?php if ((int) ($order['coupon_discount_amount'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between text-muted mb-1 small">
                <span>쿠폰 할인</span>
                <span class="text-danger">- <?= number_format($order['coupon_discount_amount']) ?>원</span>
            </div>
            <?php endif; ?>
            <?php if ((int) ($order['point_used_amount'] ?? 0) > 0): ?>
            <div class="d-flex justify-content-between text-muted mb-1 small">
                <span>포인트 사용</span>
                <span class="text-danger">- <?= number_format($order['point_used_amount']) ?>원</span>
            </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top pt-2 mt-1 fw-semibold">
                <span>최종 결제금액</span>
                <span class="text-primary"><?= number_format((int) ($order['payable_amount'] ?? $order['total_amount'])) ?>원</span>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="/mypage/orders" class="btn btn-outline-secondary flex-fill">주문 내역 보기</a>
        <a href="/shop" class="btn btn-primary flex-fill">쇼핑 계속하기</a>
    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.getElementById('btnCopy')?.addEventListener('click', function () {
    const account = document.getElementById('accountNumber').textContent.trim();
    navigator.clipboard.writeText(account).then(() => {
        this.textContent = '복사됨!';
        setTimeout(() => { this.textContent = '복사'; }, 2000);
    });
});
</script>
<?= $this->endSection() ?>
