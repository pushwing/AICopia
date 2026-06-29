<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?= view('components/banner_slot', ['banners' => $mainTopBanners]) ?>

<!-- 신상품 -->
<?php if (!empty($newProducts)): ?>
<section class="py-5">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0">신상품</h5>
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

<!-- 할인 상품 -->
<?php if (!empty($discountedProducts)): ?>
<section class="py-5 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0">할인 상품</h5>
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

<?php if (!empty($promotions)): ?>
<section class="py-5">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0">기획전</h5>
            <a href="/promotions" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row g-3">
            <?php foreach ($promotions as $p): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <a href="/promotion/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="card border-0 shadow-sm h-100 promotion-card">
                        <div class="position-relative overflow-hidden" style="aspect-ratio:16/7">
                            <?php if (!empty($p['banner_image'])): ?>
                            <img src="<?= base_url(esc($p['banner_image'])) ?>" alt="<?= esc($p['title']) ?>"
                                 class="w-100 h-100" style="object-fit:cover" loading="lazy">
                            <?php else: ?>
                            <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-light text-muted">
                                <i class="bi bi-megaphone fs-1"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-3">
                            <div class="fw-semibold mb-1"><?= esc($p['title']) ?></div>
                            <div class="small text-muted">
                                <?php
                                $s = $p['start_date'] ? date('Y.m.d', strtotime($p['start_date'])) : null;
                                $e = $p['end_date']   ? date('Y.m.d', strtotime($p['end_date']))   : null;
                                if ($s && $e)   echo $s . ' ~ ' . $e;
                                elseif ($s)     echo $s . ' ~';
                                elseif ($e)     echo '~ ' . $e;
                                else            echo '상시 진행';
                                ?>
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

<?= view('components/banner_slot', ['banners' => $mainBotBanners]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<style>
.promotion-card { transition: transform .15s, box-shadow .15s; }
.promotion-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
</style>
<?= $this->endSection() ?>
