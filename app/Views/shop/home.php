<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?= view('components/banner_slot', ['banners' => $mainTopBanners]) ?>

<?php
// 신상품·할인 밴드에 같은 상품이 중복 노출되지 않도록, 신상품에서 보여 준 상품을
// 할인 밴드에서 제외한다(slug 기준). welcome 홈과 동일한 규칙.
$shownSlugs         = array_column($newProducts ?? [], 'slug');
$discountedProducts = array_values(array_filter($discountedProducts ?? [], static fn ($p) => ! in_array($p['slug'], $shownSlugs, true)));
?>

<!-- 신상품 -->
<?php if (!empty($newProducts)): ?>
<section class="py-5">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold mb-0">신상품</h5>
            <a href="/shop" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
            <?php foreach ($newProducts as $p): ?>
            <?= view('shop/components/product_card', ['p' => $p, 'card_category' => true]) ?>
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
            <?php foreach ($discountedProducts as $p): ?>
            <?php /* 할인 밴드는 전부 재고 있는 할인 상품 — 품절 스크림 없이 할인율 배지를 항상 노출 */ ?>
            <?= view('shop/components/product_card', ['p' => $p, 'card_category' => true, 'card_scrim' => false]) ?>
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
