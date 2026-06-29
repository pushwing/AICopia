<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4" style="max-width:900px">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/mypage/orders" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="fw-bold mb-0">찜한 상품 <span class="text-muted fs-6 fw-normal">(<?= number_format($total) ?>)</span></h5>
    </div>

    <?php if (empty($items)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-heart display-5 d-block mb-3"></i>
        찜한 상품이 없습니다.
    </div>
    <?php else: ?>

    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-3">
        <?php foreach ($items as $p):
            $isSoldOut    = $p['status'] === 'sold_out' || (int) $p['stock'] === 0;
            $displayPrice = $p['discount_price'] ?? $p['price'];
            $hasDiscount  = $p['discount_price'] !== null;
        ?>
        <div class="col" id="wish-item-<?= (int) $p['id'] ?>">
            <div class="card h-100 product-card position-relative">
                <!-- 찜 해제 버튼 -->
                <button class="btn-wish-remove btn btn-sm position-absolute"
                        style="top:6px;right:6px;z-index:2;padding:2px 6px;background:rgba(255,255,255,.85);border:none"
                        data-slug="<?= esc($p['slug']) ?>"
                        data-item="<?= (int) $p['id'] ?>"
                        data-csrf="<?= csrf_token() ?>"
                        data-csrf-val="<?= csrf_hash() ?>"
                        title="찜 해제">
                    <i class="bi bi-heart-fill text-danger"></i>
                </button>
                <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                        <?php if (! empty($p['primary_image'])): ?>
                        <img src="/<?= esc(ltrim($p['primary_image'], '/')) ?>"
                             alt="<?= esc($p['name']) ?>"
                             style="width:100%;height:100%;object-fit:cover"
                             loading="lazy">
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                            <i class="bi bi-image fs-1"></i>
                        </div>
                        <?php endif; ?>
                        <?php if ($isSoldOut): ?>
                        <div class="position-absolute inset-0 d-flex align-items-center justify-content-center"
                             style="background:rgba(0,0,0,.4)">
                            <span class="badge bg-dark fs-6">품절</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <div class="fw-semibold text-truncate" style="font-size:.9rem"><?= esc($p['name']) ?></div>
                        <div class="mt-1">
                            <?php if ($hasDiscount): ?>
                            <span class="text-muted text-decoration-line-through small"><?= number_format($p['price']) ?>원</span>
                            <span class="text-danger fw-bold ms-1"><?= number_format($displayPrice) ?>원</span>
                            <?php else: ?>
                            <span class="fw-bold"><?= number_format($displayPrice) ?>원</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($p['shipping_type'] === 'free'): ?>
                        <span class="badge bg-light text-success border border-success small mt-1">무료배송</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        </div>
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

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('.btn-wish-remove').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (! confirm('찜 목록에서 제거하시겠습니까?')) return;
        var fd = new FormData();
        fd.append(btn.dataset.csrf, btn.dataset.csrfVal);
        fetch('/shop/' + btn.dataset.slug + '/wish', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (! data.wished) {
                    var el = document.getElementById('wish-item-' + btn.dataset.item);
                    if (el) el.remove();
                }
            });
    });
});
</script>
<?= $this->endSection() ?>
