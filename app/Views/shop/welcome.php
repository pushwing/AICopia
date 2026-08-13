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
            <?php // 첫 슬라이드는 LCP 후보 → fetchpriority=high, 나머지는 지연 로드 ?>
            <img src="/<?= esc($b['image_path']) ?>" class="d-block w-100"
                 style="max-height:520px;object-fit:cover" alt="<?= esc($b['title'] ?? '') ?>"
                 <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
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
<!-- 배너 없을 때 기본 Hero — 중립 서피스(브랜드 드라마는 클라이언트 주입 배너의 몫,
     Flat-By-Default·Empty-Brand 규칙에 따라 default 는 장식 그라디언트를 쓰지 않는다) -->
<div class="bg-light border-bottom py-5">
    <div class="container py-4 text-center">
        <h1 class="display-5 fw-bold mb-3">새로운 컬렉션</h1>
        <p class="lead text-muted mb-4">트렌디한 스타일을 합리적인 가격으로 만나보세요</p>
        <a href="/shop" class="btn btn-primary btn-lg px-5">쇼핑 시작하기</a>
    </div>
</div>
<?php endif; ?>

<!-- ── 카테고리 바로가기 ───────────────────────────────────────────────────── -->
<?php if (!empty($categories)): ?>
<section class="py-4 bg-light border-bottom">
    <div class="container">
        <div class="d-flex flex-wrap gap-2 justify-content-center">
            <a href="/shop" class="btn btn-sm btn-outline-dark rounded-pill px-4">전체</a>
            <?php /* 레일에는 상위 카테고리만 — 하위는 /shop 목록에서 좁힌다(플랫 나열 인지부하 방지) */ ?>
            <?php foreach ($categories as $cat): ?>
            <a href="/shop?category_id=<?= $cat['id'] ?>"
               class="btn btn-sm btn-outline-secondary rounded-pill px-4">
                <?= esc($cat['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$featuredTitle  = $wcfg['welcome_featured_title']  ?? '기획전';
$newTitle       = $wcfg['welcome_new_title']        ?? '신상품';
$discountTitle  = $wcfg['welcome_discount_title']   ?? '할인 상품';

// 세 밴드(기획전·신상품·할인)에 같은 상품이 중복 노출되지 않도록,
// 위 밴드에서 이미 보여 준 상품을 아래 밴드에서 제외한다(slug 기준 — 모든 카드가 slug 를 쓴다).
// 기획전은 큐레이션이므로 그대로 두고, 신상품 → 할인 순으로 걸러 낸다.
// 걸러진 결과가 비면 아래의 `if (!empty(...))` 가드가 해당 밴드를 통째로 숨긴다.
$shownSlugs         = array_column($featuredProducts ?? [], 'slug');
$newProducts        = array_values(array_filter($newProducts ?? [], static fn ($p) => ! in_array($p['slug'], $shownSlugs, true)));
$shownSlugs         = array_merge($shownSlugs, array_column($newProducts, 'slug'));
$discountedProducts = array_values(array_filter($discountedProducts ?? [], static fn ($p) => ! in_array($p['slug'], $shownSlugs, true)));
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
            <?php foreach ($featuredProducts as $p): ?>
            <?= view('shop/components/product_card', ['p' => $p, 'card_pick' => true, 'card_category' => true]) ?>
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
            <a href="/shop?sort=latest" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
            <?php foreach ($newProducts as $p): ?>
            <?= view('shop/components/product_card', ['p' => $p, 'card_category' => true]) ?>
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
            <a href="/shop?only_discount=1" class="text-decoration-none small text-muted">전체보기 <i class="bi bi-chevron-right"></i></a>
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

<!-- ── 하단 배너 ──────────────────────────────────────────────────────────── -->
<?= view('components/banner_slot', ['banners' => $mainBotBanners]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= $this->endSection() ?>
