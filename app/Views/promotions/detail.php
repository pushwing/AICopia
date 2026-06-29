<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-5">

    <!-- 배너 이미지 -->
    <?php if (! empty($promotion['banner_image'])): ?>
    <div class="mb-4 rounded overflow-hidden">
        <img src="<?= base_url(esc($promotion['banner_image'])) ?>"
             alt="<?= esc($promotion['title']) ?>"
             class="w-100" style="max-height:400px;object-fit:cover">
    </div>
    <?php endif; ?>

    <!-- 기획전 제목 -->
    <h2 class="fw-bold mb-1"><?= esc($promotion['title']) ?></h2>

    <?php if ($promotion['start_date'] || $promotion['end_date']): ?>
    <p class="text-muted small mb-4">
        <i class="bi bi-calendar3 me-1"></i>
        <?= $promotion['start_date'] ? date('Y.m.d', strtotime($promotion['start_date'])) : '' ?>
        <?= ($promotion['start_date'] && $promotion['end_date']) ? ' ~ ' : '' ?>
        <?= $promotion['end_date']   ? date('Y.m.d', strtotime($promotion['end_date']))   : '' ?>
    </p>
    <?php endif; ?>

    <!-- 기획전 설명 (WYSIWYG) -->
    <?php if (! empty($promotion['description'])): ?>
    <div class="promotion-desc mb-5">
        <?= $promotion['description'] ?>
    </div>
    <?php endif; ?>

    <!-- 구분선 -->
    <?php if (! empty($products)): ?>
    <hr class="my-4">
    <h5 class="fw-bold mb-4">기획전 상품</h5>

    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-4">
        <?php foreach ($products as $product):
            $hasDiscount  = $product['discount_price'] !== null;
            $displayPrice = $hasDiscount ? $product['discount_price'] : $product['price'];
            $discountRate = $hasDiscount ? round((1 - $product['discount_price'] / $product['price']) * 100) : 0;
            $isSoldOut    = $product['status'] === 'sold_out' || $product['stock'] == 0;
        ?>
        <div class="col">
            <a href="/shop/<?= esc($product['slug']) ?>" class="text-decoration-none text-dark">
                <div class="card border-0 shadow-sm h-100 product-card">
                    <div class="position-relative overflow-hidden" style="aspect-ratio:1">
                        <?php if (! empty($product['primary_image'])): ?>
                        <img src="<?= esc($product['primary_image']) ?>"
                             alt="<?= esc($product['name']) ?>"
                             class="card-img-top w-100 h-100" style="object-fit:cover">
                        <?php else: ?>
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-light text-muted">
                            <i class="bi bi-image fs-2"></i>
                        </div>
                        <?php endif; ?>
                        <?php if ($isSoldOut): ?>
                        <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
                             style="background:rgba(0,0,0,.35)">
                            <span class="badge bg-dark fs-6">품절</span>
                        </div>
                        <?php elseif ($hasDiscount): ?>
                        <span class="position-absolute top-0 end-0 badge bg-danger m-2"><?= $discountRate ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <div class="small text-truncate mb-1"><?= esc($product['name']) ?></div>
                        <?php if ($hasDiscount): ?>
                        <div class="text-muted text-decoration-line-through" style="font-size:.75rem">
                            <?= number_format($product['price']) ?>원
                        </div>
                        <?php endif; ?>
                        <div class="fw-bold <?= $hasDiscount ? 'text-danger' : '' ?>">
                            <?= number_format($displayPrice) ?>원
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<style>
.promotion-desc img { max-width: 100%; height: auto; }
.product-card { transition: transform .15s, box-shadow .15s; }
.product-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }
</style>
<?= $this->endSection() ?>
