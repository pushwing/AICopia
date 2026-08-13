<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
use App\Models\OrderModel;

// 색 = 라이프사이클 의미로 일관화한다(주황=대기/주의, 파랑=배송중, 초록=배송완료만,
// 회색·info=처리 중, 빨강·검정=종료-부정). 같은 색이 서로 다른 뜻을 갖지 않게 한다.
$statusBadge = [
    'pending'           => ['secondary', '결제 대기'],   // 처리 전 중립
    'awaiting_payment'  => ['warning',   '입금 대기'],   // 사용자 조치 필요(대기)
    'paid'              => ['info',       '결제 완료'],   // 처리 중(미완료)
    'preparing'         => ['info',       '배송 준비'],   // 처리 중
    'shipped'           => ['primary',    '배송 중'],     // 진행 중(주의 아님)
    'delivered'         => ['success',    '배송 완료'],   // 종료-완료(초록은 여기만)
    'cancelled'         => ['danger',     '취소'],
    'expired'           => ['secondary',  '만료'],
    'refund_requested'  => ['warning',    '환불 요청'],   // 대기/주의(입금대기와 같은 뜻)
    'refunded'          => ['dark',       '환불 완료'],
];
?>

<div class="container py-4" style="max-width:1100px">
<div class="row g-4">

<div class="col-lg-3 min-w-0">
    <?= $this->include('components/mypage_sidebar') ?>
</div>

<div class="col-lg-9 min-w-0">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-bold mb-0">주문 내역</h4>
    </div>

    <!-- ─── 검색창 ──────────────────────────────────────────────────────────── -->
    <form method="get" action="/mypage/orders" class="mb-3">
        <input type="hidden" name="period" value="<?= esc($period) ?>">
        <input type="hidden" name="status" value="<?= esc($status) ?>">
        <div class="input-group">
            <input type="text" name="keyword" class="form-control"
                   placeholder="상품명, 설명, 가격으로 검색" aria-label="주문 검색"
                   value="<?= esc($keyword) ?>">
            <button class="btn btn-outline-secondary" type="submit" aria-label="검색">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($keyword !== ''): ?>
            <a href="?period=<?= esc($period) ?>&status=<?= esc($status) ?>"
               class="btn btn-outline-danger" title="검색 초기화" aria-label="검색어 지우기">
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

    <!-- ─── 상태 필터 ──────────────────────────────────────────────────────────
         nav-tabs 대신 감싸지는 pill 로 렌더 — 모바일에서 탭 메타포가 다행 랩으로
         깨지고 페이지가 가로로 넘치던 문제 해소(DESIGN.md "가로 스크롤 탭 금지").
         활성 상태 언어(btn-dark)는 위 기간 필터와 통일한다. -->
    <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="주문 상태 필터">
        <?php foreach ($statusTabs as $val => $label): ?>
        <a href="?period=<?= esc($period) ?>&status=<?= $val ?>&keyword=<?= esc($keyword) ?>"
           class="btn btn-sm rounded-pill <?= $status === $val ? 'btn-dark' : 'btn-outline-secondary' ?>"
           <?= $status === $val ? 'aria-current="page"' : '' ?>>
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>



    <!-- ─── 주문 목록 ──────────────────────────────────────────────────────── -->
    <?php if (empty($items)):
        // 검색/필터로 0건인지, 실제로 주문이 없는지 구분한다.
        $hasFilter = ($keyword ?? '') !== '' || ($status ?? 'all') !== 'all' || ($period ?? 'all') !== 'all';
    ?>

    <div class="text-center text-muted py-5">
        <i class="bi bi-bag-x fs-1 d-block mb-3"></i>
        <?php if ($hasFilter): ?>
        <p class="mb-3">검색·필터 조건에 맞는 주문이 없습니다.</p>
        <a href="/mypage/orders" class="btn btn-outline-secondary">필터 초기화</a>
        <?php else: ?>
        <p class="mb-3">주문 내역이 없습니다.</p>
        <a href="/shop" class="btn btn-primary">쇼핑하러 가기</a>
        <?php endif; ?>
    </div>

    <?php else: ?>

    <div class="d-flex flex-column gap-3">
        <?php foreach ($items as $order):
            [$badgeColor, $badgeLabel] = $statusBadge[$order['status']] ?? ['secondary', $order['status']];
            // 결제 확정 전 주문은 목록에 나타나지 않으므로 pending 은 취소 가능 상태에서 제외한다. (이슈 #214)
            $canCancel = in_array($order['status'], ['awaiting_payment', 'paid'], true);
        ?>
        <div class="card">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
                <div class="small min-w-0">
                    <span class="text-muted me-2"><?= date('Y년 n월 j일', strtotime($order['created_at'])) ?></span>
                    <span class="fw-semibold"><?= esc($order['order_number']) ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <span class="badge bg-<?= $badgeColor ?>"><?= $badgeLabel ?></span>
                </div>
            </div>
            <div class="card-body py-3">
                <?php $orderName = $order['_name_summary'] ?? ''; ?>
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <?php // 대표 썸네일 — 주문 첫 상품의 대표 이미지(없으면 플레이스홀더). 클릭 시 주문 상세로. ?>
                        <a href="/mypage/orders/<?= esc($order['order_number']) ?>" class="flex-shrink-0" aria-label="주문 상세 보기">
                            <?php if (! empty($order['_thumbnail'])): ?>
                            <?php // 이미지 로드 실패(파일 삭제·404) 시 아래 플레이스홀더로 교체 — main.js 의 .order-thumb-img 핸들러 ?>
                            <img src="/<?= esc($order['_thumbnail']) ?>" alt="<?= esc($orderName) ?>"
                                 class="rounded border order-thumb-img" style="width:56px;height:56px;object-fit:cover" loading="lazy">
                            <div class="rounded border bg-light d-flex align-items-center justify-content-center text-muted order-thumb-ph"
                                 style="width:56px;height:56px;display:none"><i class="bi bi-image fs-5"></i></div>
                            <?php else: ?>
                            <div class="rounded border bg-light d-flex align-items-center justify-content-center text-muted"
                                 style="width:56px;height:56px"><i class="bi bi-image fs-5"></i></div>
                            <?php endif; ?>
                        </a>
                        <div class="min-w-0">
                            <div class="fw-semibold mb-1 text-truncate"><?= esc($orderName) ?></div>
                            <div class="text-muted small">
                                총 <?= number_format($order['payable_amount'] ?? $order['total_amount']) ?>원
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <a href="/mypage/orders/<?= esc($order['order_number']) ?>"
                       class="btn btn-sm btn-dark">상세</a>
                    <button type="button" class="btn btn-sm btn-outline-primary btn-reorder"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        <i class="bi bi-arrow-clockwise me-1"></i>재주문
                    </button>
                    <?php if ($canCancel): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-cancel"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-order-number="<?= esc($order['order_number'], 'attr') ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        주문 취소
                    </button>
                    <?php endif; ?>
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

</div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    // 대표 썸네일 로드 실패(파일 삭제·404) 시 형제 플레이스홀더로 교체한다.
    document.querySelectorAll('.order-thumb-img').forEach(function (img) {
        function fallback() {
            img.style.display = 'none';
            var ph = img.parentNode.querySelector('.order-thumb-ph');
            if (ph) { ph.style.display = 'flex'; }
        }
        img.addEventListener('error', fallback);
        // 스크립트 실행 전 이미 실패한 이미지도 처리한다.
        if (img.complete && img.naturalWidth === 0) { fallback(); }
    });
})();
(function () {
    document.querySelectorAll('.btn-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orderNo = btn.dataset.orderNumber || '';
            confirmDialog({
                title:       '주문 취소',
                message:     (orderNo ? '[' + orderNo + ']\n' : '') + '이 주문을 취소하시겠습니까?\n취소 후에는 되돌릴 수 없습니다.',
                confirmText: '주문 취소',
                cancelText:  '닫기',
                danger:      true
            }).then(function (ok) {
                if (! ok) return;

                const body = new FormData();
                body.append(btn.dataset.csrf, btn.dataset.csrfVal);
                body.append('order_id', btn.dataset.orderId);

                btn.disabled    = true;
                btn.textContent = '처리 중...';

                fetch('/mypage/orders/cancel', { method: 'POST', body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            toast('주문이 취소되었습니다.', 'success');
                            // full reload 대신 해당 카드만 제자리 제거(스크롤·필터 컨텍스트 유지)
                            var card = btn.closest('.card');
                            if (card) card.remove();
                        } else {
                            toast(data.message || '취소에 실패했습니다.', 'error');
                            btn.disabled    = false;
                            btn.textContent = '주문 취소';
                        }
                    })
                    .catch(function () {
                        toast('오류가 발생했습니다. 다시 시도해주세요.', 'error');
                        btn.disabled    = false;
                        btn.textContent = '주문 취소';
                    });
            });
        });
    });
})();

document.querySelectorAll('.btn-reorder').forEach(function (btn) {
    btn.addEventListener('click', function () {
        confirmDialog({
            title:       '재주문',
            message:     '이 주문의 상품을 장바구니에 담으시겠습니까?',
            confirmText: '장바구니에 담기'
        }).then(function (ok) {
            if (! ok) return;
            var body = new FormData();
            body.append(btn.dataset.csrf, btn.dataset.csrfVal);
            body.append('order_id', btn.dataset.orderId);
            btn.disabled = true;
            fetch('/mypage/orders/reorder', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    toast(data.message, data.success ? 'success' : 'error');
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
                    toast('오류가 발생했습니다. 다시 시도해주세요.', 'error');
                    btn.disabled = false;
                });
        });
    });
});
</script>
<?= $this->endSection() ?>
