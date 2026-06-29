<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4">

    <h4 class="fw-bold mb-4">장바구니</h4>

    <?php if (empty($items)): ?>

    <div class="text-center text-muted py-5">
        <i class="bi bi-cart-x fs-1 d-block mb-3"></i>
        <p class="mb-3">장바구니가 비어있습니다.</p>
        <a href="/shop" class="btn btn-primary">쇼핑 계속하기</a>
    </div>

    <?php else: ?>

    <div class="row g-4">

        <!-- ─── 상품 목록 ──────────────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <div class="d-flex align-items-center justify-content-between mb-2 px-1">
                <div class="form-check mb-0">
                    <input type="checkbox" id="selectAll" class="form-check-input">
                    <label class="form-check-label fw-semibold" for="selectAll">전체 선택</label>
                </div>
                <form method="post" action="/cart/clear"
                      onsubmit="return confirm('장바구니를 전체 비우시겠습니까?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">전체 삭제</button>
                </form>
            </div>

            <?php foreach ($items as $item):
                $isSoldOut = ! $item['is_available'];
            ?>
            <div class="card mb-2 cart-item <?= $isSoldOut ? 'opacity-75' : '' ?>"
                 data-product-id="<?= (int) $item['product_id'] ?>"
                 data-sku-id="<?= (int) ($item['sku_id'] ?? 0) ?>"
                 data-price="<?= (int) $item['display_price'] ?>">
                <div class="card-body py-3">
                    <div class="d-flex align-items-start gap-3">

                        <!-- 체크박스 -->
                        <div class="pt-1 flex-shrink-0">
                            <input type="checkbox" class="form-check-input item-check"
                                   <?= $isSoldOut ? 'disabled' : '' ?>>
                        </div>

                        <!-- 이미지 -->
                        <a href="/shop/<?= esc($item['slug']) ?>" class="flex-shrink-0">
                            <?php if ($item['primary_image']): ?>
                            <img src="<?= esc($item['primary_image']) ?>" alt=""
                                 style="width:80px;height:80px;object-fit:cover;border-radius:6px">
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted"
                                 style="width:80px;height:80px;background:#f1f3f5;border-radius:6px">
                                <i class="bi bi-image"></i>
                            </div>
                            <?php endif; ?>
                        </a>

                        <!-- 상품 정보 -->
                        <div class="flex-grow-1 min-w-0">
                            <a href="/shop/<?= esc($item['slug']) ?>"
                               class="text-decoration-none text-dark fw-semibold d-block text-truncate mb-1">
                                <?= esc($item['name']) ?>
                            </a>
                            <?php if (! empty($item['sku_label'])): ?>
                            <div class="text-muted small mb-1">
                                <i class="bi bi-tag me-1"></i><?= esc($item['sku_label']) ?>
                            </div>
                            <?php endif; ?>

                            <div class="small mb-1">
                                <?php if ($item['discount_price']): ?>
                                <span class="text-muted text-decoration-line-through me-1"><?= number_format($item['price']) ?>원</span>
                                <span class="text-danger fw-semibold"><?= number_format($item['display_price']) ?>원</span>
                                <?php else: ?>
                                <span class="fw-semibold"><?= number_format($item['display_price']) ?>원</span>
                                <?php endif; ?>
                            </div>

                            <!-- 배송비 -->
                            <?php if ($item['shipping_type'] === 'free'): ?>
                            <span class="badge bg-light text-success border border-success small">무료배송</span>
                            <?php elseif ($item['shipping_type'] === 'fixed'): ?>
                            <span class="badge bg-light text-secondary border small">배송비 <?= number_format($item['shipping_fee']) ?>원</span>
                            <?php else: ?>
                            <span class="badge bg-light text-secondary border small"><?= number_format($item['free_threshold']) ?>원 이상 무료</span>
                            <?php endif; ?>

                            <?php if ($isSoldOut): ?>
                            <div class="text-danger small mt-1">
                                <i class="bi bi-exclamation-circle me-1"></i>품절 상품 — 결제 불가
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 수량 + 삭제 -->
                        <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0">
                            <?php if (! $isSoldOut): ?>
                            <div class="d-flex align-items-center gap-1">
                                <div class="input-group input-group-sm" style="width:108px">
                                    <button type="button" class="btn btn-outline-secondary qty-minus">−</button>
                                    <input type="number" class="form-control text-center qty-input"
                                           value="<?= (int) $item['qty'] ?>"
                                           min="1" max="<?= (int) $item['stock'] ?>">
                                    <button type="button" class="btn btn-outline-secondary qty-plus">+</button>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary qty-update"
                                        data-product-id="<?= (int) $item['product_id'] ?>"
                                        data-sku-id="<?= (int) ($item['sku_id'] ?? 0) ?>"
                                        data-csrf="<?= csrf_token() ?>"
                                        data-csrf-val="<?= csrf_hash() ?>">
                                    수정
                                </button>
                            </div>
                            <div class="fw-bold small line-total">
                                <?= number_format($item['display_price'] * $item['qty']) ?>원
                            </div>
                            <?php else: ?>
                            <div class="text-muted small">수량 <?= (int) $item['qty'] ?>개</div>
                            <?php endif; ?>

                            <form method="post" action="/cart/delete" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                <?php if (! empty($item['sku_id'])): ?>
                                <input type="hidden" name="sku_id" value="<?= (int) $item['sku_id'] ?>">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">삭제</button>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <!-- ─── 주문 요약 ──────────────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card sticky-top" style="top:1rem">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">주문 요약</h6>

                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">선택 상품</span>
                        <span id="selectedCount" class="small">0개</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                        <span class="fw-semibold">합계</span>
                        <span id="selectedTotal" class="fw-bold fs-5">0원</span>
                    </div>

                    <div class="text-muted small mb-3">
                        <i class="bi bi-info-circle me-1"></i>배송비는 결제 시 확인됩니다.
                    </div>

                    <button id="btnCheckout" class="btn btn-primary w-100 mb-2" disabled>
                        주문하기
                    </button>
                    <a href="/shop" class="btn btn-outline-secondary w-100">쇼핑 계속하기</a>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    function updateSummary() {
        let count = 0;
        let total = 0;
        document.querySelectorAll('.cart-item').forEach(function (card) {
            const check = card.querySelector('.item-check');
            if (! check || ! check.checked) return;
            count++;
            const price = parseInt(card.dataset.price || 0);
            const qty   = parseInt(card.querySelector('.qty-input')?.value || 1);
            total += price * qty;
        });
        document.getElementById('selectedCount').textContent = count + '개';
        document.getElementById('selectedTotal').textContent = total.toLocaleString('ko-KR') + '원';

        const btn = document.getElementById('btnCheckout');
        if (btn) btn.disabled = (count === 0);
    }

    // 주문하기 버튼 — 선택 상품 product_id 목록을 세션에 저장 후 주문서로 이동
    document.getElementById('btnCheckout')?.addEventListener('click', function () {
        window.location.href = '/order';
    });

    // 전체 선택 토글
    document.getElementById('selectAll')?.addEventListener('change', function () {
        document.querySelectorAll('.item-check:not([disabled])').forEach(function (c) {
            c.checked = this.checked;
        }, this);
        updateSummary();
    });

    // 개별 체크 → 합계 갱신
    document.querySelectorAll('.item-check').forEach(function (c) {
        c.addEventListener('change', updateSummary);
    });

    // 수량 +/− / input → 행 합계 + 주문 요약 갱신
    document.querySelectorAll('.cart-item').forEach(function (card) {
        const input     = card.querySelector('.qty-input');
        const minus     = card.querySelector('.qty-minus');
        const plus      = card.querySelector('.qty-plus');
        const updateBtn = card.querySelector('.qty-update');
        const lineTotal = card.querySelector('.line-total');
        const price     = parseInt(card.dataset.price || 0);

        if (! input) return;

        function refreshLine() {
            const qty = parseInt(input.value) || 1;
            if (lineTotal) lineTotal.textContent = (price * qty).toLocaleString('ko-KR') + '원';
            updateSummary();
        }

        minus?.addEventListener('click', function () {
            input.value = Math.max(1, parseInt(input.value) - 1);
            refreshLine();
        });
        plus?.addEventListener('click', function () {
            input.value = Math.min(parseInt(input.max || 999), parseInt(input.value) + 1);
            refreshLine();
        });
        input.addEventListener('input', refreshLine);

        // 수정 버튼 — Ajax
        updateBtn?.addEventListener('click', function () {
            const btn       = this;
            const productId = btn.dataset.productId;
            const skuId     = btn.dataset.skuId || '0';
            const qty       = parseInt(input.value) || 1;
            const body      = new FormData();
            body.append(btn.dataset.csrf, btn.dataset.csrfVal);
            body.append('product_id', productId);
            body.append('qty', qty);
            if (skuId !== '0') body.append('sku_id', skuId);

            btn.disabled    = true;
            btn.textContent = '저장 중';

            fetch('/cart/update', { method: 'POST', body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        input.value = data.qty;
                        refreshLine();
                    }
                    btn.textContent = '수정';
                    btn.disabled    = false;
                })
                .catch(function () {
                    btn.textContent = '수정';
                    btn.disabled    = false;
                });
        });
    });
})();
</script>
<?= $this->endSection() ?>
