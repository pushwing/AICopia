<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
// 본품 → 그 본품의 추가구성상품 순서로 묶는다. 주문 상세와 동일한 규칙(AddonGrouping)을 써서
// 화면마다 그룹핑 결과가 어긋나지 않게 한다.
$items = \App\Libraries\AddonGrouping::order($items ?? []);

// 장바구니 진입 시 구매 가능한(품절이 아닌) 상품은 기본으로 전체 선택된 상태로 시작한다.
$hasPurchasableItem = (bool) array_filter($items, static fn (array $item): bool => $item['is_available']);
?>

<div class="container py-4">

    <h4 class="fw-bold mb-4">장바구니</h4>

    <?php /* 플래시 메시지는 레이아웃 상단에서 한 번만 출력한다. */ ?>

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
                    <input type="checkbox" id="selectAll" class="form-check-input"
                           <?= $hasPurchasableItem ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="selectAll">전체 선택</label>
                </div>
                <form method="post" action="/cart/clear"
                      data-confirm="장바구니를 전체 비우시겠습니까?" data-confirm-danger>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger">전체 삭제</button>
                </form>
            </div>

            <?php foreach ($items as $item):
                $isSoldOut = ! $item['is_available'];
                $isAddon   = ! empty($item['is_addon']);
            ?>
            <div class="card mb-2 cart-item <?= $isSoldOut ? 'opacity-75' : '' ?> <?= $isAddon ? 'ps-4 border-start border-3' : '' ?>"
                 data-cart-id="<?= (int) $item['id'] ?>"
                 data-product-id="<?= (int) $item['product_id'] ?>"
                 data-sku-id="<?= (int) ($item['sku_id'] ?? 0) ?>"
                 data-price="<?= (int) $item['display_price'] ?>"
                 <?php if ($isAddon): ?>data-parent-product-id="<?= (int) $item['parent_product_id'] ?>"<?php endif; ?>>
                <div class="card-body py-3">
                    <?php /* 좁은 화면에서는 수량·삭제 묶음(고정폭)이 자리를 다 차지해
                             상품명 칸이 한 글자 폭까지 눌린다 — 줄바꿈을 허용하고
                             상품 정보에 최소 폭을 줘서 공간이 부족하면 아래로 내린다. */ ?>
                    <div class="d-flex align-items-start gap-3 flex-wrap">

                        <!-- 체크박스 -->
                        <div class="pt-1 flex-shrink-0">
                            <input type="checkbox" class="form-check-input item-check"
                                   aria-label="<?= esc($item['name']) ?> 선택"
                                   <?= $isSoldOut ? 'disabled data-soldout="1"' : 'checked' ?>>
                        </div>

                        <?php if ($isAddon): ?>
                        <span class="badge bg-secondary align-self-start">추가구성</span>
                        <?php endif; ?>

                        <!-- 이미지 -->
                        <a href="/shop/<?= esc($item['slug']) ?>" class="flex-shrink-0">
                            <?php if ($item['primary_image']): ?>
                            <img src="<?= esc($item['primary_image']) ?>" alt="" class="cart-item-img rounded"
                                 style="width:80px;height:80px;object-fit:cover">
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted rounded"
                                 style="width:80px;height:80px;background:#f1f3f5">
                                <i class="bi bi-image"></i>
                            </div>
                            <?php endif; ?>
                        </a>

                        <!-- 상품 정보 -->
                        <div class="flex-grow-1" style="min-width:140px">
                            <a href="/shop/<?= esc($item['slug']) ?>"
                               class="text-decoration-none text-dark fw-semibold text-clamp-2 mb-1">
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
                        <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0 ms-auto">
                            <?php if (! $isSoldOut): ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="input-group input-group-sm" style="width:108px">
                                    <button type="button" class="btn btn-outline-secondary qty-minus" aria-label="수량 줄이기">−</button>
                                    <input type="number" class="form-control text-center qty-input" aria-label="수량"
                                           value="<?= (int) $item['qty'] ?>"
                                           min="1" max="<?= (int) $item['stock'] ?>">
                                    <button type="button" class="btn btn-outline-secondary qty-plus" aria-label="수량 늘리기">+</button>
                                </div>
                                <span class="qty-status text-success small d-none" role="status" aria-live="polite">
                                    <i class="bi bi-check2"></i> 저장됨
                                </span>
                            </div>
                            <div class="fw-bold small line-total">
                                <?= number_format($item['display_price'] * $item['qty']) ?>원
                            </div>
                            <?php else: ?>
                            <div class="text-muted small">수량 <?= (int) $item['qty'] ?>개</div>
                            <?php endif; ?>

                            <form method="post" action="/cart/delete" class="d-inline"
                                  data-confirm="이 상품을 장바구니에서 삭제하시겠습니까?" data-confirm-danger>
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

                    <!-- 선택한 상품의 cart_items.id 만 담아 전송한다(주문서는 이 목록만 보여준다) -->
                    <form id="checkoutForm" method="post" action="/cart/checkout">
                        <?= csrf_field() ?>
                        <div id="checkoutInputs"></div>
                        <button id="btnCheckout" type="submit" class="btn btn-primary w-100 mb-2" disabled>
                            주문하기
                        </button>
                    </form>
                    <a href="/shop" class="btn btn-outline-secondary w-100">쇼핑 계속하기</a>
                </div>
            </div>
        </div>

    </div>

    <!-- 모바일 스티키 결제 바 (요약이 상품 아래로 밀려도 주문하기가 항상 손닿게) -->
    <div class="d-lg-none position-fixed bottom-0 start-0 end-0 bg-white border-top d-flex align-items-center gap-2 px-2 py-2"
         id="cartStickyBar" style="z-index:1040">
        <div class="flex-shrink-0 ps-1">
            <div class="text-muted" style="font-size:.8rem;line-height:1.1"><span id="stickyCount">0개</span> 선택</div>
            <div class="fw-bold" id="stickyTotal">0원</div>
        </div>
        <button type="submit" form="checkoutForm" id="btnStickyCheckout" class="btn btn-primary flex-grow-1 py-2" disabled>
            주문하기
        </button>
    </div>
    <div class="d-lg-none" style="height:76px"></div>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    // 자동 저장 AJAX 용 CSRF (regenerate=false 라 한 토큰을 반복 사용 가능)
    const CART_CSRF = { name: '<?= csrf_token() ?>', hash: '<?= csrf_hash() ?>' };

    function updateSummary() {
        const countEl = document.getElementById('selectedCount');
        if (! countEl) return; // 장바구니가 비어있으면 요약 영역 자체가 렌더링되지 않는다.

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
        countEl.textContent = count + '개';
        const totalStr = total.toLocaleString('ko-KR') + '원';
        document.getElementById('selectedTotal').textContent = totalStr;

        const btn = document.getElementById('btnCheckout');
        if (btn) btn.disabled = (count === 0);

        // 모바일 스티키 결제 바 동기화
        const sc = document.getElementById('stickyCount');
        const st = document.getElementById('stickyTotal');
        const sb = document.getElementById('btnStickyCheckout');
        if (sc) sc.textContent = count + '개';
        if (st) st.textContent = totalStr;
        if (sb) sb.disabled = (count === 0);
    }

    // 주문 폼 제출 — 체크된 카드의 cart id 를 hidden 으로 실어 보낸다.
    document.getElementById('checkoutForm')?.addEventListener('submit', function (e) {
        const holder = document.getElementById('checkoutInputs');
        holder.innerHTML = '';

        document.querySelectorAll('.cart-item').forEach(function (card) {
            const check = card.querySelector('.item-check');
            if (! check || ! check.checked) return;

            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'cart_ids[]';
            input.value = card.dataset.cartId;
            holder.appendChild(input);
        });

        if (! holder.children.length) {
            e.preventDefault();
            toast('주문할 상품을 선택해주세요.', 'warning');
        }
    });

    // 본품 체크 상태에 따라 연결된 애드온 체크박스를 잠그거나 푼다.
    // - 본품 미체크: 애드온을 체크 해제하고 잠근다(직전 선택 상태는 기억해둔다).
    // - 본품 체크: 애드온 잠금을 풀고, 잠기기 전 선택 상태로 복원한다.
    // 품절로 이미 disabled된 애드온(data-soldout="1")은 건드리지 않는다.
    function syncAddonLock(parentCheckbox) {
        const parentCard = parentCheckbox.closest('.cart-item');
        if (! parentCard) return;
        const parentProductId = parentCard.dataset.productId;

        document.querySelectorAll('.cart-item[data-parent-product-id="' + parentProductId + '"]').forEach(function (addonCard) {
            const addonCheck = addonCard.querySelector('.item-check');
            if (! addonCheck || addonCheck.dataset.soldout === '1') return;

            if (parentCheckbox.checked) {
                if (addonCheck.disabled) {
                    addonCheck.disabled = false;
                    addonCheck.checked  = addonCheck.dataset.prevChecked === '1';
                }
            } else if (! addonCheck.disabled) {
                addonCheck.dataset.prevChecked = addonCheck.checked ? '1' : '0';
                addonCheck.checked  = false;
                addonCheck.disabled = true;
            }
        });
    }

    // 전체 선택 토글
    document.getElementById('selectAll')?.addEventListener('change', function () {
        document.querySelectorAll('.item-check:not([disabled])').forEach(function (c) {
            c.checked = this.checked;
        }, this);
        document.querySelectorAll('.item-check').forEach(syncAddonLock);
        updateSummary();
    });

    // 개별 체크 → 합계 갱신 + 연결된 애드온 잠금 동기화
    document.querySelectorAll('.item-check').forEach(function (c) {
        c.addEventListener('change', function () {
            syncAddonLock(c);
            updateSummary();
        });
    });

    // 수량 +/− / input → 행 합계·요약 갱신 + 서버 자동 저장(디바운스)
    // '보이는 수량 = 주문 수량' 불변식 유지 — 별도 '수정' 버튼 없이 변경 즉시 영속한다.
    document.querySelectorAll('.cart-item').forEach(function (card) {
        const input     = card.querySelector('.qty-input');
        const minus     = card.querySelector('.qty-minus');
        const plus      = card.querySelector('.qty-plus');
        const lineTotal = card.querySelector('.line-total');
        const status    = card.querySelector('.qty-status');
        const price     = parseInt(card.dataset.price || 0);
        const productId = card.dataset.productId;
        const skuId     = card.dataset.skuId || '0';
        let persistTimer = null;

        if (! input) return;

        function refreshLine() {
            const qty = parseInt(input.value) || 1;
            if (lineTotal) lineTotal.textContent = (price * qty).toLocaleString('ko-KR') + '원';
            updateSummary();
        }

        function showSaved() {
            if (! status) return;
            status.classList.remove('d-none');
            clearTimeout(status._t);
            status._t = setTimeout(function () { status.classList.add('d-none'); }, 1500);
        }

        function persist() {
            const qty  = parseInt(input.value) || 1;
            const body = new FormData();
            body.append(CART_CSRF.name, CART_CSRF.hash);
            body.append('product_id', productId);
            body.append('qty', qty);
            if (skuId !== '0') body.append('sku_id', skuId);

            fetch('/cart/update', { method: 'POST', body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        // 서버가 재고로 클램프한 실제 수량으로 동기화
                        if (parseInt(input.value) !== data.qty) { input.value = data.qty; refreshLine(); }
                        showSaved();
                    }
                })
                .catch(function () {});
        }
        function schedulePersist() {
            clearTimeout(persistTimer);
            persistTimer = setTimeout(persist, 500);
        }

        minus?.addEventListener('click', function () {
            input.value = Math.max(1, parseInt(input.value) - 1);
            refreshLine();
            schedulePersist();
        });
        plus?.addEventListener('click', function () {
            input.value = Math.min(parseInt(input.max || 999), parseInt(input.value) + 1);
            refreshLine();
            schedulePersist();
        });
        input.addEventListener('input', function () { refreshLine(); schedulePersist(); });
        input.addEventListener('blur', persist);
    });

    // 장바구니 썸네일 404 폴백 — 로드 실패 시 bi-image 플레이스홀더로 교체
    document.querySelectorAll('.cart-item-img').forEach(function (img) {
        function fail() {
            if (img.dataset.imgFallback) return;
            img.dataset.imgFallback = '1';
            const ph = document.createElement('div');
            ph.className = 'd-flex align-items-center justify-content-center text-muted rounded';
            ph.style.cssText = 'width:80px;height:80px;background:#f1f3f5';
            const icon = document.createElement('i');
            icon.className = 'bi bi-image';
            ph.appendChild(icon);
            if (img.parentNode) img.parentNode.replaceChild(ph, img);
        }
        img.addEventListener('error', fail);
        if (img.complete && img.naturalWidth === 0) fail();
    });

    // 로드 시점에 본품 체크 상태에 따라 애드온 잠금 상태를 먼저 맞추고,
    // 기본 선택된 상품 수/합계를 요약에 반영한다.
    document.querySelectorAll('.item-check').forEach(syncAddonLock);
    updateSummary();
})();
</script>
<?= $this->endSection() ?>
