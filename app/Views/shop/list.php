<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4">

    <!-- 헤더 -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold">쇼핑</h4>
        <form method="get" action="/shop" class="d-flex gap-2">
            <input type="text" name="keyword" class="form-control form-control-sm" style="width:200px"
                   placeholder="상품명 검색" value="<?= esc($keyword ?? '') ?>">
            <?php if ($curCat): ?>
            <input type="hidden" name="category_id" value="<?= $curCat ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <?php if (! empty($expandedTerms ?? []) && ! empty($keyword)): ?>
    <div class="alert alert-light border d-flex align-items-center flex-wrap gap-1 py-2 mb-3">
        <i class="bi bi-stars text-primary me-1"></i>
        <span class="small text-muted me-1">AI 연관 검색:</span>
        <?php foreach ($expandedTerms as $term): ?>
        <a href="/shop?keyword=<?= urlencode($term) ?>" class="badge bg-primary-subtle text-primary text-decoration-none border border-primary-subtle"><?= esc($term) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?= view('shop/components/recommend', ['recommended' => $recommended ?? []]) ?>

    <div class="row g-4">

        <!-- 필터 사이드바 -->
        <div class="col-lg-2 col-md-3">
            <!-- 카테고리 -->
            <div class="list-group list-group-flush mb-3">
                <a href="/shop" class="list-group-item list-group-item-action <?= ! $curCat ? 'active' : '' ?> py-2">
                    전체
                </a>
                <?php foreach ($tree as $parent): ?>
                <a href="/shop?category_id=<?= $parent['id'] ?>"
                   class="list-group-item list-group-item-action py-2 fw-semibold <?= $curCat == $parent['id'] ? 'active' : '' ?>">
                    <?= esc($parent['name']) ?>
                </a>
                <?php foreach ($parent['children'] as $child): ?>
                <a href="/shop?category_id=<?= $child['id'] ?>"
                   class="list-group-item list-group-item-action py-1 ps-4 small <?= $curCat == $child['id'] ? 'active' : '' ?>">
                    — <?= esc($child['name']) ?>
                </a>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <!-- 가격·할인 필터 -->
            <?php
            $filterBase = array_filter([
                'keyword'     => $keyword ?? '',
                'category_id' => $curCat ?: '',
                'sort'        => $curSort ?? '',
            ], fn($v) => $v !== '');
            ?>
            <form method="get" action="/shop" id="filterForm">
                <?php foreach ($filterBase as $k => $v): ?>
                <input type="hidden" name="<?= esc($k) ?>" value="<?= esc($v) ?>">
                <?php endforeach; ?>
                <div class="border rounded p-3 bg-white small">
                    <div class="fw-semibold mb-2">가격 범위</div>
                    <div class="d-flex align-items-center gap-1 mb-2">
                        <input type="number" name="price_min" class="form-control form-control-sm"
                               placeholder="최소" min="0" step="1000"
                               value="<?= esc($priceMin ?? '') ?>" style="width:0;flex:1">
                        <span class="text-muted">~</span>
                        <input type="number" name="price_max" class="form-control form-control-sm"
                               placeholder="최대" min="0" step="1000"
                               value="<?= esc($priceMax ?? '') ?>" style="width:0;flex:1">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="only_discount" value="1"
                               id="chkDiscount" <?= ($onlyDiscount ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chkDiscount">할인 상품만</label>
                    </div>
                    <div class="d-grid gap-1">
                        <button type="submit" class="btn btn-sm btn-dark">필터 적용</button>
                        <a href="/shop<?= $curCat ? '?category_id='.$curCat : '' ?>" class="btn btn-sm btn-outline-secondary">초기화</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- 상품 목록 -->
        <div class="col-lg-10 col-md-9">

            <!-- 정렬 -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">총 <?= number_format($total) ?>개 상품</span>
                <div class="d-flex gap-1">
                    <?php
                    $sorts = ['latest' => '최신순', 'price_asc' => '가격 낮은순', 'price_desc' => '가격 높은순'];
                    $safeGet = array_intersect_key($_GET, array_flip(['keyword', 'category_id', 'price_min', 'price_max', 'only_discount']));
                    foreach ($sorts as $sortVal => $sortLabel):
                        $qs = http_build_query(array_merge($safeGet, ['sort' => $sortVal, 'page' => 1]));
                    ?>
                    <a href="/shop?<?= $qs ?>"
                       class="btn btn-sm <?= $curSort === $sortVal ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= $sortLabel ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (empty($items)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-bag-x fs-1 d-block mb-2"></i>
                <?= $keyword ? '검색 결과가 없습니다.' : '등록된 상품이 없습니다.' ?>
            </div>
            <?php else: ?>

            <!-- 상품 그리드 -->
            <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
                <?php foreach ($items as $p):
                    $isSoldOut = $p['status'] === 'sold_out' || $p['stock'] == 0;
                    $displayPrice = $p['discount_price'] ?? $p['price'];
                    $hasDiscount  = $p['discount_price'] !== null;
                ?>
                <div class="col">
                    <div class="card h-100 product-card position-relative">
                        <!-- 찜 버튼 -->
                        <?php $isWishedItem = in_array((int) $p['id'], $wishedIds ?? [], true); ?>
                        <button class="btn-wish position-absolute btn btn-sm"
                                style="top:6px;right:6px;z-index:2;padding:2px 6px;background:rgba(255,255,255,.85);border:none"
                                data-slug="<?= esc($p['slug']) ?>"
                                data-csrf="<?= csrf_token() ?>"
                                data-csrf-val="<?= csrf_hash() ?>"
                                data-loggedin="<?= session()->get('user_id') ? 'true' : 'false' ?>"
                                title="찜하기">
                            <i class="bi <?= $isWishedItem ? 'bi-heart-fill' : 'bi-heart' ?> text-danger"></i>
                        </button>
                        <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark">
                            <!-- 이미지 -->
                            <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                                <?php if ($p['primary_image']): ?>
                                <img src="<?= esc($p['primary_image']) ?>" alt="<?= esc($p['name']) ?>"
                                     style="width:100%;height:100%;object-fit:cover"
                                     loading="lazy">
                                <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                    <i class="bi bi-image fs-1"></i>
                                </div>
                                <?php endif; ?>
                                <!-- 품절 배지 -->
                                <?php if ($isSoldOut): ?>
                                <div class="position-absolute inset-0 d-flex align-items-center justify-content-center"
                                     style="background:rgba(0,0,0,.4)">
                                    <span class="badge bg-dark fs-6">품절</span>
                                </div>
                                <?php endif; ?>
                                <!-- 할인율 배지 -->
                                <?php if ($hasDiscount && ! $isSoldOut):
                                    $rate = round((1 - $p['discount_price'] / $p['price']) * 100);
                                ?>
                                <span class="badge bg-danger position-absolute" style="top:8px;right:8px"><?= $rate ?>%</span>
                                <?php endif; ?>
                            </div>
                            <!-- 정보 -->
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
                                <!-- 배송비 뱃지 -->
                                <?php if ($p['shipping_type'] === 'free'): ?>
                                <span class="badge bg-light text-success border border-success small mt-1">무료배송</span>
                                <?php elseif ($p['shipping_type'] === 'conditional'): ?>
                                <span class="badge bg-light text-secondary border small mt-1"><?= number_format((int)$p['free_threshold']) ?>원 이상 무료</span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <!-- 빠른 장바구니 -->
                        <?php if (! $isSoldOut): ?>
                            <?php if ($p['has_options']): ?>
                            <a href="/shop/<?= esc($p['slug']) ?>"
                               class="btn btn-sm btn-outline-secondary w-100 py-2"
                               style="border-radius:0 0 calc(.375rem - 1px) calc(.375rem - 1px)">
                                <i class="bi bi-bag"></i> 옵션 선택
                            </a>
                            <?php else: ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary w-100 py-2 btn-quick-cart"
                                    style="border-radius:0 0 calc(.375rem - 1px) calc(.375rem - 1px)"
                                    data-product-id="<?= $p['id'] ?>"
                                    data-csrf="<?= csrf_token() ?>"
                                    data-csrf-val="<?= csrf_hash() ?>">
                                <i class="bi bi-cart-plus"></i> 담기
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 페이지네이션 -->
            <?php if ($totalPages > 1):
                $baseQs = array_intersect_key($_GET, array_flip(['keyword', 'category_id', 'sort', 'price_min', 'price_max', 'only_discount']));
            ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
                    <li class="page-item <?= $pg === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="/shop?<?= http_build_query(array_merge($baseQs, ['page' => $pg])) ?>">
                            <?= $pg ?>
                        </a>
                    </li>
                    <?php endfor; ?>
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
document.querySelectorAll('.btn-quick-cart').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var productId = btn.dataset.productId;
        var csrfName  = btn.dataset.csrf;
        var csrfVal   = btn.dataset.csrfVal;

        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('/cart/add', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'product_id=' + productId + '&qty=1&' + csrfName + '=' + encodeURIComponent(csrfVal)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.csrf_hash) {
                document.querySelectorAll('.btn-quick-cart').forEach(function(b) {
                    b.dataset.csrfVal = data.csrf_hash;
                });
            }
            if (data.success) {
                btn.innerHTML = '<i class="bi bi-check2"></i> 담김';
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
                // 카트 카운트 업데이트
                var badge = document.getElementById('cartBadge');
                if (badge && data.cartCount !== undefined) {
                    badge.textContent = data.cartCount;
                    badge.style.display = data.cartCount > 0 ? '' : 'none';
                }
                setTimeout(function() {
                    btn.innerHTML = original;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                    btn.disabled = false;
                }, 1500);
            } else {
                btn.innerHTML = original;
                btn.disabled = false;
                alert(data.message || '장바구니 담기에 실패했습니다.');
            }
        })
        .catch(function() {
            btn.innerHTML = original;
            btn.disabled = false;
        });
    });
});

document.querySelectorAll('.btn-wish').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (btn.dataset.loggedin !== 'true') {
            window.location.href = '/auth/login';
            return;
        }

        var slug     = btn.dataset.slug;
        var csrfName = btn.dataset.csrf;
        var csrfVal  = btn.dataset.csrfVal;

        fetch('/shop/' + slug + '/wish', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: csrfName + '=' + encodeURIComponent(csrfVal)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            var icon = btn.querySelector('i');
            if (data.wished) {
                icon.classList.remove('bi-heart');
                icon.classList.add('bi-heart-fill');
            } else {
                icon.classList.remove('bi-heart-fill');
                icon.classList.add('bi-heart');
            }
            if (data.csrf_hash) btn.dataset.csrfVal = data.csrf_hash;
        })
        .catch(function() {});
    });
});
</script>
<?= $this->endSection() ?>
