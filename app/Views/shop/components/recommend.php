<?php
/**
 * 개인화 추천 상품 블록 (재사용 partial).
 *
 * @var array  $recommended  RecommendationService::forUser() 결과
 * @var string $recoTitle    블록 제목 (선택)
 */
$recommended = $recommended ?? [];
if (empty($recommended)) {
    return;
}
$recoTitle = $recoTitle ?? '회원님을 위한 추천 상품';
?>
<div class="mb-4">
    <h6 class="fw-bold mb-3"><i class="bi bi-stars text-primary me-1"></i><?= esc($recoTitle) ?></h6>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
        <?php foreach ($recommended as $p):
            $isSoldOut    = $p['status'] === 'sold_out' || (int) $p['stock'] === 0;
            $displayPrice = $p['discount_price'] ?? $p['price'];
            $hasDiscount  = $p['discount_price'] !== null;
        ?>
        <div class="col">
            <div class="card h-100 product-card">
                <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                        <?php if (! empty($p['primary_image'])): ?>
                        <?php /* primary_image 는 attachPrimaryImages() 가 base_url() 로 만든 절대 URL 이므로 그대로 사용 */ ?>
                        <img src="<?= esc($p['primary_image']) ?>"
                             alt="<?= esc($p['name']) ?>"
                             style="width:100%;height:100%;object-fit:cover" loading="lazy">
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                            <i class="bi bi-image fs-1"></i>
                        </div>
                        <?php endif; ?>
                        <?php if ($isSoldOut): ?>
                        <div class="product-soldout-scrim">
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
                    </div>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
