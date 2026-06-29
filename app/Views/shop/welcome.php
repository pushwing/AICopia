<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- ── Hero 슬라이더 ──────────────────────────────────────────────────────── -->
<?php if (!empty($heroBanners)): ?>
<div id="heroCarousel" class="carousel slide" data-bs-ride="carousel">
    <div class="carousel-indicators">
        <?php foreach ($heroBanners as $i => $b): ?>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= $i ?>"
                class="<?= $i === 0 ? 'active' : '' ?>"></button>
        <?php endforeach; ?>
    </div>
    <div class="carousel-inner">
        <?php foreach ($heroBanners as $i => $b): ?>
        <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
            <?php if ($b['link_url']): ?>
            <a href="<?= esc($b['link_url']) ?>" target="<?= esc($b['link_target']) ?>">
            <?php endif; ?>
            <img src="/<?= esc($b['image_path']) ?>" class="d-block w-100"
                 style="max-height:520px;object-fit:cover" alt="">
            <?php if ($b['link_url']): ?></a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($heroBanners) > 1): ?>
    <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
    </button>
    <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
    </button>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- 배너 없을 때 기본 Hero -->
<div class="bg-dark text-white py-5" style="background: linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%) !important;">
    <div class="container py-4 text-center">
        <h1 class="display-5 fw-bold mb-3">새로운 컬렉션</h1>
        <p class="lead text-white-50 mb-4">트렌디한 스타일을 합리적인 가격으로 만나보세요</p>
        <a href="/shop" class="btn btn-light btn-lg px-5">쇼핑 시작하기</a>
    </div>
</div>
<?php endif; ?>

<!-- ── 카테고리 바로가기 ───────────────────────────────────────────────────── -->
<?php if (!empty($categories)): ?>
<section class="py-4 bg-light border-bottom">
    <div class="container">
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a href="/shop" class="btn btn-sm btn-outline-dark rounded-pill px-4">전체</a>
            <?php foreach ($categories as $cat): ?>
            <a href="/shop?category_id=<?= $cat['id'] ?>"
               class="btn btn-sm btn-outline-secondary rounded-pill px-4">
                <?= esc($cat['name']) ?>
            </a>
            <?php foreach ($cat['children'] as $child): ?>
            <a href="/shop?category_id=<?= $child['id'] ?>"
               class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                <?= esc($child['name']) ?>
            </a>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$featuredTitle  = $wcfg['welcome_featured_title']  ?? '기획전';
$newTitle       = $wcfg['welcome_new_title']        ?? '신상품';
$discountTitle  = $wcfg['welcome_discount_title']   ?? '할인 상품';
?>
<!-- ── 기획전 상품 ─────────────────────────────────────────────────────────── -->
<?php if (!empty($featuredProducts)): ?>
<section class="py-5">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <span class="badge bg-danger me-2">PICK</span>
                <span class="fw-bold fs-5"><?= esc($featuredTitle) ?></span>
            </div>
            <a href="/shop" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
            <?php foreach ($featuredProducts as $p):
                $isSoldOut    = $p['status'] === 'sold_out' || $p['stock'] == 0;
                $displayPrice = $p['discount_price'] ?? $p['price'];
                $hasDiscount  = $p['discount_price'] !== null;
            ?>
            <div class="col">
                <div class="card h-100 product-card border-danger position-relative">
                    <span class="badge bg-danger position-absolute" style="top:8px;left:8px;z-index:1">PICK</span>
                    <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                        <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                            <?php if ($p['primary_image']): ?>
                            <img src="<?= esc($p['primary_image']) ?>" alt="<?= esc($p['name']) ?>"
                                 style="width:100%;height:100%;object-fit:cover" loading="lazy">
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
                            <?php if ($hasDiscount && !$isSoldOut):
                                $rate = round((1 - $p['discount_price'] / $p['price']) * 100);
                            ?>
                            <span class="badge bg-danger position-absolute" style="top:8px;right:8px"><?= $rate ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-2">
                            <div class="small text-muted mb-1"><?= esc($p['category_name'] ?? '') ?></div>
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
</section>
<?php endif; ?>

<!-- ── 신상품 ─────────────────────────────────────────────────────────────── -->
<?php if (!empty($newProducts)): ?>
<section class="py-5 <?= !empty($featuredProducts) ? 'bg-light' : '' ?>">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <span class="badge bg-dark me-2">NEW</span>
                <span class="fw-bold fs-5"><?= esc($newTitle) ?></span>
            </div>
            <a href="/shop" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
            <?php foreach ($newProducts as $p):
                $isSoldOut    = $p['status'] === 'sold_out' || $p['stock'] == 0;
                $displayPrice = $p['discount_price'] ?? $p['price'];
                $hasDiscount  = $p['discount_price'] !== null;
            ?>
            <div class="col">
                <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="card h-100 product-card">
                        <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                            <?php if ($p['primary_image']): ?>
                            <img src="<?= esc($p['primary_image']) ?>" alt="<?= esc($p['name']) ?>"
                                 style="width:100%;height:100%;object-fit:cover" loading="lazy">
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
                            <?php if ($hasDiscount && !$isSoldOut):
                                $rate = round((1 - $p['discount_price'] / $p['price']) * 100);
                            ?>
                            <span class="badge bg-danger position-absolute" style="top:8px;right:8px"><?= $rate ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-2">
                            <div class="small text-muted mb-1"><?= esc($p['category_name'] ?? '') ?></div>
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
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── 할인 상품 ──────────────────────────────────────────────────────────── -->
<?php if (!empty($discountedProducts)): ?>
<section class="py-5 <?= empty($featuredProducts) ? 'bg-light' : '' ?>">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <span class="badge bg-danger me-2">SALE</span>
                <span class="fw-bold fs-5"><?= esc($discountTitle) ?></span>
            </div>
            <a href="/shop" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
            <?php foreach ($discountedProducts as $p):
                $displayPrice = $p['discount_price'];
                $rate         = round((1 - $p['discount_price'] / $p['price']) * 100);
            ?>
            <div class="col">
                <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="card h-100 product-card">
                        <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                            <?php if ($p['primary_image']): ?>
                            <img src="<?= esc($p['primary_image']) ?>" alt="<?= esc($p['name']) ?>"
                                 style="width:100%;height:100%;object-fit:cover" loading="lazy">
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                <i class="bi bi-image fs-1"></i>
                            </div>
                            <?php endif; ?>
                            <span class="badge bg-danger position-absolute" style="top:8px;right:8px"><?= $rate ?>%</span>
                        </div>
                        <div class="card-body p-2">
                            <div class="small text-muted mb-1"><?= esc($p['category_name'] ?? '') ?></div>
                            <div class="fw-semibold text-truncate" style="font-size:.9rem"><?= esc($p['name']) ?></div>
                            <div class="mt-1">
                                <span class="text-muted text-decoration-line-through small"><?= number_format($p['price']) ?>원</span>
                                <span class="text-danger fw-bold ms-1"><?= number_format($displayPrice) ?>원</span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── 하단 배너 ──────────────────────────────────────────────────────────── -->
<?= view('components/banner_slot', ['banners' => $mainBotBanners]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->endSection() ?>
