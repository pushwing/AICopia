<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4" style="max-width:1100px">
<div class="row g-4">

<div class="col-lg-3 min-w-0">
    <?= $this->include('components/mypage_sidebar') ?>
</div>

<div class="col-lg-9 min-w-0">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h5 class="fw-bold mb-0">찜한 상품 <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h5>
    </div>

    <?php if (empty($items)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-heart display-5 d-block mb-3"></i>
        찜한 상품이 없습니다.
    </div>
    <?php else: ?>

    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-3">
        <?php foreach ($items as $p): ?>
        <?php /* 찜 목록: 할인 배지·카테고리 없이, 찜 해제 버튼 + 무료배송 배지만 노출 */ ?>
        <?= view('shop/components/product_card', [
            'p'                 => $p,
            'card_discountBadge' => false,
            'card_shipping'     => 'free',
            'card_wish'         => 'remove',
            'card_colId'        => 'wish-item-' . (int) $p['id'],
        ], ['saveData' => false]) ?>
        <?php endforeach; ?>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
            <li class="page-item <?= $pg === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="/mypage/wishlist?page=<?= $pg ?>"><?= $pg ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php endif; ?>

    <?php if (! empty($recommended ?? [])): ?>
    <hr class="my-4">
    <?= view('shop/components/recommend', ['recommended' => $recommended]) ?>
    <?php endif; ?>
</div>

</div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('.btn-wish-remove').forEach(function (btn) {
    btn.addEventListener('click', function () {
        confirmDialog({
            title: '찜 해제', message: '찜 목록에서 제거하시겠습니까?',
            confirmText: '제거', danger: true
        }).then(function (ok) {
            if (! ok) return;
            var fd = new FormData();
            fd.append(btn.dataset.csrf, btn.dataset.csrfVal);
            fetch('/shop/' + btn.dataset.slug + '/wish', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (! data.wished) {
                        var el = document.getElementById('wish-item-' + btn.dataset.item);
                        if (el) el.remove();
                        toast('찜 목록에서 제거했습니다.', 'success');
                    }
                })
                .catch(function () { toast('오류가 발생했습니다.', 'error'); });
        });
    });
});
</script>
<?= $this->endSection() ?>
