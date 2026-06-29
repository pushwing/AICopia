<?php
/**
 * 최근 본 상품 컴포넌트
 * 필요 변수: $recentProducts (array)
 */
if (empty($recentProducts)) return;
?>
<div class="container py-4 border-top">
    <h6 class="fw-bold mb-3 text-muted">최근 본 상품</h6>
    <div class="d-flex gap-3 overflow-auto pb-2" style="scrollbar-width:thin">
        <?php foreach ($recentProducts as $rp):
            $rIsSoldOut    = $rp['status'] === 'sold_out' || (int) $rp['stock'] === 0;
            $rDisplayPrice = $rp['discount_price'] ?? $rp['price'];
        ?>
        <a href="/shop/<?= esc($rp['slug']) ?>"
           class="text-decoration-none text-dark flex-shrink-0"
           style="width:130px">
            <div class="position-relative rounded overflow-hidden mb-1"
                 style="aspect-ratio:1;background:#f8f9fa">
                <?php if (! empty($rp['primary_image'])): ?>
                <img src="/<?= esc(ltrim($rp['primary_image'], '/')) ?>"
                     alt="<?= esc($rp['name']) ?>"
                     style="width:100%;height:100%;object-fit:cover"
                     loading="lazy">
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    <i class="bi bi-image"></i>
                </div>
                <?php endif; ?>
                <?php if ($rIsSoldOut): ?>
                <div class="position-absolute inset-0 d-flex align-items-center justify-content-center"
                     style="background:rgba(0,0,0,.35)">
                    <span class="badge bg-dark small">품절</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="small text-truncate fw-semibold"><?= esc($rp['name']) ?></div>
            <div class="small fw-bold <?= $rp['discount_price'] ? 'text-danger' : '' ?>">
                <?= number_format($rDisplayPrice) ?>원
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
