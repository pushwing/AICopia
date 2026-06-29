<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
use App\Models\OrderModel;

$statusBadge = [
    'pending'           => ['secondary', '결제 대기'],
    'awaiting_payment'  => ['primary',   '입금 대기'],
    'paid'              => ['success',   '결제 완료'],
    'preparing'         => ['info',      '배송 준비'],
    'shipped'           => ['warning',   '배송 중'],
    'delivered'         => ['success',   '배송 완료'],
    'cancelled'         => ['danger',    '취소'],
    'expired'           => ['secondary', '만료'],
    'refund_requested'  => ['warning',   '환불 요청'],
    'refunded'          => ['dark',      '환불 완료'],
];
?>

<div class="container py-4" style="max-width:860px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-bold mb-0">주문 내역</h4>
        <div class="d-flex gap-2">
            <a href="/mypage/coupons" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-ticket-perforated me-1"></i>쿠폰
            </a>
            <a href="/mypage/points" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-star me-1"></i>포인트
            </a>
            <a href="/mypage/addresses" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-geo-alt me-1"></i>배송지
            </a>
        </div>
    </div>

    <!-- ─── 검색창 ──────────────────────────────────────────────────────────── -->
    <form method="get" action="/mypage/orders" class="mb-3">
        <input type="hidden" name="period" value="<?= esc($period) ?>">
        <input type="hidden" name="status" value="<?= esc($status) ?>">
        <div class="input-group">
            <input type="text" name="keyword" class="form-control"
                   placeholder="상품명, 설명, 가격으로 검색"
                   value="<?= esc($keyword) ?>">
            <button class="btn btn-outline-secondary" type="submit">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($keyword !== ''): ?>
            <a href="?period=<?= esc($period) ?>&status=<?= esc($status) ?>"
               class="btn btn-outline-danger" title="검색 초기화">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- ─── 기간 필터 ──────────────────────────────────────────────────────── -->
    <div class="d-flex gap-2 mb-3">
        <?php foreach (['1m' => '1개월', '3m' => '3개월', 'all' => '전체'] as $val => $label): ?>
        <a href="?period=<?= $val ?>&status=<?= esc($status) ?>&keyword=<?= esc($keyword) ?>"
           class="btn btn-sm <?= $period === $val ? 'btn-dark' : 'btn-outline-secondary' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ─── 상태 탭 ────────────────────────────────────────────────────────── -->
    <ul class="nav nav-tabs mb-3">
        <?php foreach ($statusTabs as $val => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $status === $val ? 'active' : '' ?>"
               href="?period=<?= esc($period) ?>&status=<?= $val ?>&keyword=<?= esc($keyword) ?>">
                <?= $label ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>



    <!-- ─── 주문 목록 ──────────────────────────────────────────────────────── -->
    <?php if (empty($items)): ?>

    <div class="text-center text-muted py-5">
        <i class="bi bi-bag-x fs-1 d-block mb-3"></i>
        <p class="mb-3">주문 내역이 없습니다.</p>
        <a href="/shop" class="btn btn-primary">쇼핑하러 가기</a>
    </div>

    <?php else: ?>

    <div class="d-flex flex-column gap-3">
        <?php foreach ($items as $order):
            [$badgeColor, $badgeLabel] = $statusBadge[$order['status']] ?? ['secondary', $order['status']];
            $canCancel = in_array($order['status'], ['pending', 'awaiting_payment', 'paid'], true);
        ?>
        <div class="card">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                <div class="small">
                    <span class="text-muted me-2"><?= date('Y년 n월 j일', strtotime($order['created_at'])) ?></span>
                    <span class="fw-semibold"><?= esc($order['order_number']) ?></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-<?= $badgeColor ?>"><?= $badgeLabel ?></span>
                    <a href="/mypage/orders/<?= esc($order['order_number']) ?>"
                       class="btn btn-sm btn-outline-secondary">상세</a>
                </div>
            </div>
            <div class="card-body py-3">
                <?php $orderName = $order['_name_summary'] ?? ''; ?>
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="fw-semibold mb-1"><?= esc($orderName) ?></div>
                        <div class="text-muted small">
                            총 <?= number_format($order['total_amount']) ?>원
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                    <?php if ($canCancel): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-cancel"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        주문 취소
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-reorder"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        <i class="bi bi-arrow-clockwise me-1"></i>재주문
                    </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ─── 페이징 ──────────────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-4 d-flex justify-content-center">
        <ul class="pagination pagination-sm mb-0">
            <?php if ($currentPage > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?period=<?= esc($period) ?>&status=<?= esc($status) ?>&keyword=<?= esc($keyword) ?>&page=<?= $currentPage - 1 ?>">‹</a>
            </li>
            <?php endif; ?>

            <?php
            $start = max(1, $currentPage - 2);
            $end   = min($totalPages, $currentPage + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?period=<?= esc($period) ?>&status=<?= esc($status) ?>&keyword=<?= esc($keyword) ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?period=<?= esc($period) ?>&status=<?= esc($status) ?>&keyword=<?= esc($keyword) ?>&page=<?= $currentPage + 1 ?>">›</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    document.querySelectorAll('.btn-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (! confirm('주문을 취소하시겠습니까?\n취소 후에는 되돌릴 수 없습니다.')) return;

            const orderId = btn.dataset.orderId;
            const body    = new FormData();
            body.append(btn.dataset.csrf, btn.dataset.csrfVal);
            body.append('order_id', orderId);

            btn.disabled    = true;
            btn.textContent = '처리 중...';

            fetch('/mypage/orders/cancel', { method: 'POST', body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || '취소에 실패했습니다.');
                        btn.disabled    = false;
                        btn.textContent = '주문 취소';
                    }
                })
                .catch(function () {
                    alert('오류가 발생했습니다. 다시 시도해주세요.');
                    btn.disabled    = false;
                    btn.textContent = '주문 취소';
                });
        });
    });
})();

document.querySelectorAll('.btn-reorder').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (! confirm('해당 주문의 상품을 장바구니에 담으시겠습니까?')) return;
        var body = new FormData();
        body.append(btn.dataset.csrf, btn.dataset.csrfVal);
        body.append('order_id', btn.dataset.orderId);
        btn.disabled = true;
        fetch('/mypage/orders/reorder', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                alert(data.message);
                if (data.success) {
                    var badge = document.getElementById('cartBadge');
                    if (badge) {
                        badge.textContent   = data.cartCount;
                        badge.style.display = data.cartCount > 0 ? '' : 'none';
                    }
                }
                btn.disabled = false;
            })
            .catch(function () {
                alert('오류가 발생했습니다. 다시 시도해주세요.');
                btn.disabled = false;
            });
    });
});
</script>
<?= $this->endSection() ?>
