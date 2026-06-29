<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$totalAmount  = (int) ($totalAmount  ?? 0);
$totalProduct = (int) ($totalProduct ?? 0);
$shippingFee  = (int) ($shippingFee  ?? 0);
$pointBalance = (int) ($pointBalance ?? 0);
$_grade        = $authUser['grade'] ?? 'bronze';
$pointEarnRate = (float) ($settings['point_earn_rate_' . $_grade] ?? $settings['point_earn_rate'] ?? 1);
$minPayable   = (int) ($settings['min_payable_amount'] ?? 0);
$userCoupons  = $userCoupons ?? [];
?>

<div class="container py-4">

    <h4 class="fw-bold mb-4">주문서</h4>

    <form id="checkoutForm" novalidate>
        <?= csrf_field() ?>

        <div class="row g-4">

            <!-- ─── 왼쪽: 배송지 + 상품 + 쿠폰 + 포인트 + 결제수단 ─────────────── -->
            <div class="col-lg-8">

                <!-- 배송지 -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold bg-white">
                        <i class="bi bi-geo-alt me-2 text-primary"></i>배송지
                    </div>
                    <div class="card-body">

                        <?php if (! empty($savedAddresses)): ?>
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="small fw-semibold text-muted">저장된 배송지</span>
                                <a href="/mypage/addresses" class="small text-primary text-decoration-none" target="_blank">
                                    <i class="bi bi-pencil-square me-1"></i>배송지 관리
                                </a>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($savedAddresses as $addr): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary addr-card <?= $addr['is_default'] ? 'active border-primary text-primary' : '' ?>"
                                        data-name="<?= esc($addr['receiver_name'], 'attr') ?>"
                                        data-phone="<?= esc($addr['receiver_phone'], 'attr') ?>"
                                        data-zip="<?= esc($addr['zipcode'], 'attr') ?>"
                                        data-addr1="<?= esc($addr['address1'], 'attr') ?>"
                                        data-addr2="<?= esc($addr['address2'] ?? '', 'attr') ?>">
                                    <?php if ($addr['is_default']): ?>
                                    <i class="bi bi-star-fill me-1 small"></i>
                                    <?php endif; ?>
                                    <?= esc($addr['receiver_name']) ?>
                                    <span class="text-muted small ms-1 d-none d-sm-inline">
                                        <?= esc(mb_substr($addr['address1'], 0, 12)) ?>…
                                    </span>
                                </button>
                                <?php endforeach; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnNewAddr">
                                    <i class="bi bi-plus me-1"></i>새 배송지
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">받는 분 <span class="text-danger">*</span></label>
                                <input type="text" name="receiver_name" class="form-control"
                                       placeholder="이름" maxlength="100"
                                       value="<?= esc($savedAddress['receiver_name'] ?? '') ?>"
                                       required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-semibold">연락처 <span class="text-danger">*</span></label>
                                <input type="tel" name="receiver_phone" class="form-control"
                                       placeholder="010-0000-0000" maxlength="20"
                                       value="<?= esc($savedAddress['receiver_phone'] ?? '') ?>"
                                       required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">주소 <span class="text-danger">*</span></label>
                                <div class="input-group mb-2">
                                    <input type="text" name="zipcode" id="zipcode" class="form-control"
                                           placeholder="우편번호" maxlength="10"
                                           value="<?= esc($savedAddress['zipcode'] ?? '') ?>"
                                           readonly required>
                                    <button type="button" class="btn btn-outline-secondary" id="btnPostcode">
                                        주소 검색
                                    </button>
                                </div>
                                <input type="text" name="address1" id="address1" class="form-control mb-2"
                                       placeholder="기본 주소"
                                       value="<?= esc($savedAddress['address1'] ?? '') ?>"
                                       readonly required>
                                <input type="text" name="address2" id="address2" class="form-control"
                                       placeholder="상세 주소 (동, 호수 등)"
                                       value="<?= esc($savedAddress['address2'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">배송 메모</label>
                                <select class="form-select" id="deliveryMemoSelect">
                                    <option value="">선택 안 함</option>
                                    <option value="문 앞에 놔주세요">문 앞에 놔주세요</option>
                                    <option value="경비실에 맡겨주세요">경비실에 맡겨주세요</option>
                                    <option value="배송 전 연락 주세요">배송 전 연락 주세요</option>
                                    <option value="직접 입력">직접 입력</option>
                                </select>
                                <input type="text" name="delivery_memo_custom" id="deliveryMemoCustom"
                                       class="form-control mt-2 d-none"
                                       placeholder="배송 메모를 입력하세요" maxlength="200">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" name="save_address" id="saveAddress"
                                           class="form-check-input" value="1">
                                    <label class="form-check-label small" for="saveAddress">
                                        이 배송지를 저장하기
                                    </label>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- 주문 상품 -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold bg-white">
                        <i class="bi bi-bag me-2 text-primary"></i>주문 상품
                        <span class="text-muted fw-normal ms-1">(<?= count($available) ?>개)</span>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($available as $item):
                            $price = (int) ($item['discount_price'] ?? $item['price']);
                        ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <?php if ($item['primary_image']): ?>
                            <img src="<?= esc($item['primary_image']) ?>" alt=""
                                 style="width:64px;height:64px;object-fit:cover;border-radius:6px;flex-shrink:0">
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted flex-shrink-0"
                                 style="width:64px;height:64px;background:#f1f3f5;border-radius:6px">
                                <i class="bi bi-image"></i>
                            </div>
                            <?php endif; ?>

                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold small text-truncate mb-1"><?= esc($item['name']) ?></div>
                                <?php if (! empty($item['sku_label'])): ?>
                                <div class="text-muted" style="font-size:.75rem;margin-bottom:.15rem">
                                    <i class="bi bi-tag me-1"></i><?= esc($item['sku_label']) ?>
                                </div>
                                <?php endif; ?>
                                <div class="text-muted small">
                                    <?= number_format($price) ?>원 × <?= (int) $item['qty'] ?>개
                                </div>
                            </div>
                            <div class="fw-bold text-end flex-shrink-0">
                                <?= number_format($price * (int) $item['qty']) ?>원
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 쿠폰 -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold bg-white">
                        <i class="bi bi-ticket-perforated me-2 text-primary"></i>쿠폰
                    </div>
                    <div class="card-body">

                        <?php if (! empty($userCoupons)): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">보유 쿠폰 선택</label>
                            <select class="form-select form-select-sm" id="couponSelect">
                                <option value="">-- 쿠폰을 선택하세요 --</option>
                                <?php foreach ($userCoupons as $uc): ?>
                                <option value="<?= (int) $uc['user_coupon_id'] ?>"
                                        data-name="<?= esc($uc['name'], 'attr') ?>"
                                        data-type="<?= esc($uc['type'], 'attr') ?>"
                                        data-value="<?= (int) $uc['discount_value'] ?>"
                                        data-max="<?= (int) $uc['max_discount_amount'] ?>"
                                        data-min="<?= (int) $uc['min_order_amount'] ?>">
                                    <?= esc($uc['name']) ?>
                                    (<?php
                                        if ($uc['type'] === 'free_shipping') echo '무료배송';
                                        elseif ($uc['type'] === 'fixed')    echo number_format($uc['discount_value']) . '원 할인';
                                        else                                 echo $uc['discount_value'] . '% 할인';
                                    ?>)
                                    <?php if ($uc['expires_at']): ?>
                                    · <?= date('n월 j일', strtotime($uc['expires_at'])) ?>까지
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="small text-muted">또는</span>
                        </div>
                        <?php endif; ?>

                        <div class="input-group input-group-sm">
                            <input type="text" id="couponCodeInput" class="form-control"
                                   placeholder="쿠폰 코드 입력" maxlength="50"
                                   style="text-transform:uppercase">
                            <button type="button" class="btn btn-outline-primary" id="btnApplyCoupon">
                                적용
                            </button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="btnRemoveCoupon">
                                취소
                            </button>
                        </div>
                        <div id="couponMsg" class="small mt-2"></div>

                    </div>
                </div>

                <!-- 포인트 -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold bg-white">
                        <i class="bi bi-star me-2 text-primary"></i>포인트
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-muted">보유 포인트</span>
                            <span class="fw-semibold" id="displayBalance"><?= number_format($pointBalance) ?>P</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="number" id="pointUseInput" class="form-control"
                                   placeholder="사용할 포인트" min="0" max="<?= $pointBalance ?>"
                                   step="1" value="0">
                            <button type="button" class="btn btn-outline-secondary" id="btnPointAll">
                                전액 사용
                            </button>
                        </div>
                        <div id="pointMsg" class="small mt-2 text-muted">
                            <?= $pointBalance > 0 ? "최대 {$pointBalance}P 사용 가능" : "사용 가능한 포인트가 없습니다." ?>
                        </div>
                        <?php if ($pointEarnRate > 0): ?>
                        <div class="small text-success mt-2">
                            <i class="bi bi-plus-circle me-1"></i>
                            이번 주문 <span id="earnEstimate">0</span>P 적립 예정 (배송완료 시 확정)
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 결제 수단 -->
                <div class="card">
                    <div class="card-header fw-semibold bg-white">
                        <i class="bi bi-credit-card me-2 text-primary"></i>결제 수단
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($pgProviders as $key => $label): ?>
                            <div class="col-6 col-sm-4">
                                <input type="radio" class="btn-check" name="pg_provider"
                                       id="pg_<?= $key ?>" value="<?= $key ?>"
                                       <?= $key === array_key_first($pgProviders) ? 'checked' : '' ?>>
                                <label class="btn btn-outline-secondary w-100 py-3 small fw-semibold"
                                       for="pg_<?= $key ?>">
                                    <?= esc($label) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (! empty($settings['bank_account'])): ?>
                        <div id="bankTransferInfo" class="d-none mt-3 p-3 bg-light rounded border">
                            <div class="small fw-semibold mb-2"><i class="bi bi-bank me-1"></i>입금 계좌 안내</div>
                            <dl class="row mb-0 small">
                                <dt class="col-4 text-muted fw-normal">은행</dt>
                                <dd class="col-8 mb-1"><?= esc($settings['bank_name'] ?? '—') ?></dd>
                                <dt class="col-4 text-muted fw-normal">계좌번호</dt>
                                <dd class="col-8 mb-1 fw-bold font-monospace"><?= esc($settings['bank_account']) ?></dd>
                                <dt class="col-4 text-muted fw-normal">예금주</dt>
                                <dd class="col-8 mb-0"><?= esc($settings['bank_holder'] ?? '—') ?></dd>
                            </dl>
                            <div class="text-muted mt-2" style="font-size:.75rem">
                                주문 완료 후 안내되는 금액을 정확히 입금해 주세요.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- ─── 오른쪽: 주문 요약 ──────────────────────────────────────── -->
            <div class="col-lg-4">
                <div class="card sticky-top" style="top:1rem">
                    <div class="card-body">

                        <h6 class="fw-bold mb-3">결제 금액</h6>

                        <div class="d-flex justify-content-between small mb-2">
                            <span class="text-muted">상품 합계</span>
                            <span><?= number_format($totalProduct) ?>원</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-2">
                            <span class="text-muted">배송비</span>
                            <span><?= $shippingFee > 0 ? number_format($shippingFee) . '원' : '무료' ?></span>
                        </div>

                        <!-- 쿠폰 할인 (동적) -->
                        <div id="rowCouponDiscount" class="d-none d-flex justify-content-between small mb-2">
                            <span class="text-muted">쿠폰 할인</span>
                            <span class="text-danger fw-semibold">- <span id="displayCouponDiscount">0</span>원</span>
                        </div>
                        <!-- 포인트 사용 (동적) -->
                        <div id="rowPointUse" class="d-none d-flex justify-content-between small mb-2">
                            <span class="text-muted">포인트 사용</span>
                            <span class="text-danger fw-semibold">- <span id="displayPointUse">0</span>원</span>
                        </div>

                        <div class="d-flex justify-content-between fw-bold mb-4 border-top pt-3 mt-1">
                            <span>최종 결제 금액</span>
                            <span class="fs-5 text-primary" id="displayPayable"><?= number_format($totalAmount) ?>원</span>
                        </div>

                        <div class="text-muted small mb-3">
                            <i class="bi bi-shield-check me-1"></i>
                            주문 내용을 확인하였으며, 정보 제공 등에 동의합니다.
                        </div>

                        <button type="button" id="btnOrder"
                                class="btn btn-primary w-100 py-3 fw-bold fs-6">
                            <?= number_format($totalAmount) ?>원 결제하기
                        </button>

                    </div>
                </div>
            </div>

        </div>

        <!-- 결제용 hidden 필드 -->
        <input type="hidden" name="delivery_memo"   id="deliveryMemoFinal">
        <input type="hidden" name="user_coupon_id"  id="hiddenUserCouponId"  value="">
        <input type="hidden" name="coupon_code"     id="hiddenCouponCode"    value="">
        <input type="hidden" name="point_use"       id="hiddenPointUse"      value="0">

    </form>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script src="https://js.tosspayments.com/v1/payment"></script>

<script>
(function () {
    const CSRF_NAME   = '<?= csrf_token() ?>';
    let   csrfHash    = '<?= csrf_hash() ?>';

    const TOTAL_AMOUNT  = <?= $totalAmount ?>;
    const POINT_BALANCE = <?= $pointBalance ?>;
    const POINT_RATE    = <?= $pointEarnRate ?>;
    const MIN_PAYABLE   = <?= $minPayable ?>;

    let couponDiscount   = 0;
    let appliedCouponId  = 0;   // user_coupon_id (0 = none)
    let appliedCouponCode = '';  // code-based

    // ─── 쿠폰/포인트 요약 업데이트 ────────────────────────────────────────────
    function updateSummary() {
        const pointInput  = document.getElementById('pointUseInput');
        let   pointUse    = Math.max(0, Math.min(parseInt(pointInput?.value || 0) || 0, POINT_BALANCE));
        const payable     = Math.max(0, TOTAL_AMOUNT - couponDiscount - pointUse);

        // 쿠폰 할인 행
        const rowCoupon = document.getElementById('rowCouponDiscount');
        if (couponDiscount > 0) {
            rowCoupon?.classList.remove('d-none');
            const el = document.getElementById('displayCouponDiscount');
            if (el) el.textContent = couponDiscount.toLocaleString('ko-KR');
        } else {
            rowCoupon?.classList.add('d-none');
        }

        // 포인트 사용 행
        const rowPoint = document.getElementById('rowPointUse');
        if (pointUse > 0) {
            rowPoint?.classList.remove('d-none');
            const el = document.getElementById('displayPointUse');
            if (el) el.textContent = pointUse.toLocaleString('ko-KR');
        } else {
            rowPoint?.classList.add('d-none');
        }

        // 최종 금액 표시
        const displayPayable = document.getElementById('displayPayable');
        if (displayPayable) displayPayable.textContent = payable.toLocaleString('ko-KR') + '원';

        // 버튼 텍스트
        const btn = document.getElementById('btnOrder');
        if (btn && ! btn.disabled) {
            btn.textContent = payable.toLocaleString('ko-KR') + '원 결제하기';
        }

        // 포인트 적립 예상
        const earnEl = document.getElementById('earnEstimate');
        if (earnEl && POINT_RATE > 0) {
            earnEl.textContent = Math.floor(payable * POINT_RATE / 100).toLocaleString('ko-KR');
        }

        // hidden 필드 동기화
        document.getElementById('hiddenPointUse').value   = pointUse;
        document.getElementById('hiddenUserCouponId').value = appliedCouponId || '';
        document.getElementById('hiddenCouponCode').value   = appliedCouponCode || '';
    }

    // ─── 보유 쿠폰 선택 ───────────────────────────────────────────────────────
    document.getElementById('couponSelect')?.addEventListener('change', function () {
        if (! this.value) {
            resetCoupon();
            return;
        }
        const opt   = this.options[this.selectedIndex];
        const type  = opt.dataset.type;
        const val   = parseInt(opt.dataset.value) || 0;
        const max   = parseInt(opt.dataset.max)   || 0;
        const min   = parseInt(opt.dataset.min)   || 0;

        if (TOTAL_AMOUNT < min) {
            document.getElementById('couponMsg').innerHTML =
                '<span class="text-danger">최소 주문금액 ' + min.toLocaleString('ko-KR') + '원 이상에서 사용 가능합니다.</span>';
            this.value = '';
            return;
        }

        let discount = 0;
        const SHIPPING_FEE = <?= $shippingFee ?>;
        if (type === 'free_shipping') {
            discount = SHIPPING_FEE;
        } else if (type === 'fixed') {
            discount = Math.min(val, TOTAL_AMOUNT);
        } else {
            discount = Math.floor(TOTAL_AMOUNT * val / 100);
            if (max > 0) discount = Math.min(discount, max);
        }

        const discLabel = type === 'free_shipping'
            ? opt.dataset.name + ' (무료배송)'
            : opt.dataset.name + ' (' + discount.toLocaleString('ko-KR') + '원 할인)';
        applyCoupon(parseInt(this.value), '', discount, discLabel);
    });

    // ─── 쿠폰 코드 적용 (AJAX) ────────────────────────────────────────────────
    document.getElementById('btnApplyCoupon')?.addEventListener('click', async function () {
        const code = document.getElementById('couponCodeInput').value.trim().toUpperCase();
        if (! code) {
            showMsg('couponMsg', 'warning', '쿠폰 코드를 입력해주세요.');
            return;
        }

        const body = new FormData();
        body.append(CSRF_NAME, csrfHash);
        body.append('coupon_code',  code);
        body.append('order_amount', TOTAL_AMOUNT);

        try {
            const res  = await fetch('/coupon/check', { method: 'POST', body });
            const data = await res.json();
            if (res.headers.get('X-CSRF-TOKEN')) csrfHash = res.headers.get('X-CSRF-TOKEN');

            if (data.valid) {
                applyCoupon(0, code, data.discount, data.label);
            } else {
                showMsg('couponMsg', 'danger', data.message || '유효하지 않은 쿠폰입니다.');
            }
        } catch {
            showMsg('couponMsg', 'danger', '쿠폰 확인 중 오류가 발생했습니다.');
        }
    });

    function applyCoupon(userCouponId, code, discount, label) {
        couponDiscount    = discount;
        appliedCouponId   = userCouponId;
        appliedCouponCode = code;

        document.getElementById('btnApplyCoupon').classList.add('d-none');
        document.getElementById('btnRemoveCoupon').classList.remove('d-none');
        showMsg('couponMsg', 'success', '<i class="bi bi-check-circle me-1"></i>' + label + ' 적용됨');
        updateSummary();
    }

    function resetCoupon() {
        couponDiscount    = 0;
        appliedCouponId   = 0;
        appliedCouponCode = '';
        const select = document.getElementById('couponSelect');
        if (select) select.value = '';
        document.getElementById('couponCodeInput').value = '';
        document.getElementById('btnApplyCoupon').classList.remove('d-none');
        document.getElementById('btnRemoveCoupon').classList.add('d-none');
        document.getElementById('couponMsg').innerHTML = '';
        updateSummary();
    }

    document.getElementById('btnRemoveCoupon')?.addEventListener('click', resetCoupon);

    // ─── 포인트 입력 ──────────────────────────────────────────────────────────
    document.getElementById('pointUseInput')?.addEventListener('input', function () {
        let val = parseInt(this.value) || 0;
        if (val < 0)             val = 0;
        if (val > POINT_BALANCE) val = POINT_BALANCE;
        this.value = val;
        updateSummary();
        const msg = document.getElementById('pointMsg');
        if (msg) msg.textContent = val > 0 ? val.toLocaleString('ko-KR') + 'P 사용 예정' : (POINT_BALANCE > 0 ? '최대 ' + POINT_BALANCE + 'P 사용 가능' : '사용 가능한 포인트가 없습니다.');
    });

    document.getElementById('btnPointAll')?.addEventListener('click', function () {
        const el = document.getElementById('pointUseInput');
        if (el) { el.value = POINT_BALANCE; el.dispatchEvent(new Event('input')); }
    });

    function showMsg(elId, type, html) {
        const el = document.getElementById(elId);
        if (el) el.innerHTML = '<span class="text-' + type + '">' + html + '</span>';
    }

    // ─── 저장된 배송지 카드 선택 ─────────────────────────────────────────────
    function fillAddress(name, phone, zip, addr1, addr2) {
        document.querySelector('[name=receiver_name]').value  = name;
        document.querySelector('[name=receiver_phone]').value = phone;
        document.getElementById('zipcode').value   = zip;
        document.getElementById('address1').value  = addr1;
        document.getElementById('address2').value  = addr2;
    }

    document.querySelectorAll('.addr-card').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.addr-card').forEach(b => b.classList.remove('active', 'border-primary', 'text-primary'));
            this.classList.add('active', 'border-primary', 'text-primary');
            fillAddress(this.dataset.name, this.dataset.phone, this.dataset.zip, this.dataset.addr1, this.dataset.addr2);
        });
    });

    document.getElementById('btnNewAddr')?.addEventListener('click', function () {
        document.querySelectorAll('.addr-card').forEach(b => b.classList.remove('active', 'border-primary', 'text-primary'));
        fillAddress('', '', '', '', '');
        document.querySelector('[name=receiver_name]').focus();
    });

    <?php if (! empty($savedAddress)): ?>
    fillAddress(
        '<?= esc($savedAddress['receiver_name'],  'js') ?>',
        '<?= esc($savedAddress['receiver_phone'], 'js') ?>',
        '<?= esc($savedAddress['zipcode'],        'js') ?>',
        '<?= esc($savedAddress['address1'],       'js') ?>',
        '<?= esc($savedAddress['address2'] ?? '', 'js') ?>'
    );
    <?php endif; ?>

    // ─── 카카오 우편번호 검색 ──────────────────────────────────────────────────
    document.getElementById('btnPostcode')?.addEventListener('click', function () {
        new daum.Postcode({
            oncomplete: function (data) {
                document.getElementById('zipcode').value  = data.zonecode;
                document.getElementById('address1').value = data.roadAddress || data.jibunAddress;
                document.getElementById('address2').focus();
            }
        }).open();
    });

    // ─── 배송 메모 직접 입력 ───────────────────────────────────────────────────
    document.getElementById('deliveryMemoSelect')?.addEventListener('change', function () {
        const custom = document.getElementById('deliveryMemoCustom');
        custom.classList.toggle('d-none', this.value !== '직접 입력');
    });

    // ─── 무통장입금 계좌 안내 토글 ───────────────────────────────────────────────
    document.querySelectorAll('[name=pg_provider]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const info = document.getElementById('bankTransferInfo');
            if (info) info.classList.toggle('d-none', this.value !== 'bank_transfer');
        });
    });
    (function () {
        const checked = document.querySelector('[name=pg_provider]:checked');
        const info = document.getElementById('bankTransferInfo');
        if (info && checked?.value === 'bank_transfer') info.classList.remove('d-none');
    })();

    // ─── 폼 유효성 검사 ────────────────────────────────────────────────────────
    function validate() {
        const required = ['receiver_name', 'receiver_phone', 'zipcode', 'address1'];
        for (const name of required) {
            const el = document.querySelector('[name=' + name + ']');
            if (! el || ! el.value.trim()) {
                el?.focus();
                alert((el?.placeholder || name) + '을(를) 입력해주세요.');
                return false;
            }
        }
        if (! document.querySelector('[name=pg_provider]:checked')) {
            alert('결제 수단을 선택해주세요.');
            return false;
        }

        // 최소 결제 금액 검증
        const pointUse = parseInt(document.getElementById('hiddenPointUse').value) || 0;
        const payable  = Math.max(0, TOTAL_AMOUNT - couponDiscount - pointUse);
        if (MIN_PAYABLE > 0 && payable < MIN_PAYABLE) {
            alert('최소 결제 금액은 ' + MIN_PAYABLE.toLocaleString('ko-KR') + '원입니다.');
            return false;
        }
        return true;
    }

    // ─── 주문 생성 → PG 결제창 ────────────────────────────────────────────────
    document.getElementById('btnOrder')?.addEventListener('click', async function () {
        if (! validate()) return;

        const btn = this;
        btn.disabled    = true;
        btn.textContent = '처리 중...';

        const memoSel = document.getElementById('deliveryMemoSelect').value;
        const memoCus = document.getElementById('deliveryMemoCustom').value.trim();
        document.getElementById('deliveryMemoFinal').value =
            memoSel === '직접 입력' ? memoCus : (memoSel || '');

        const form = document.getElementById('checkoutForm');
        const body = new FormData(form);

        try {
            const res  = await fetch('/order/create', { method: 'POST', body });
            const data = await res.json();

            if (res.headers.get('X-CSRF-TOKEN')) csrfHash = res.headers.get('X-CSRF-TOKEN');

            if (! data.success) {
                alert(data.message || '주문 생성에 실패했습니다.');
                btn.disabled    = false;
                updateSummary(); // 버튼 텍스트 복원
                return;
            }

            await launchPG(data.pgParams);

        } catch (e) {
            alert('오류가 발생했습니다. 다시 시도해주세요.');
            btn.disabled = false;
            updateSummary();
        }
    });

    // ─── PG별 결제창 실행 ─────────────────────────────────────────────────────
    async function launchPG(p) {
        const pg = p.pg;

        if (pg === 'toss') {
            const toss = TossPayments(p.clientKey);
            await toss.requestPayment('카드', {
                amount:       p.amount,
                orderId:      p.orderId,
                orderName:    p.orderName,
                customerName: p.customerName,
                successUrl:   location.origin + '/payment/callback/toss?order_id=' + p.orderId,
                failUrl:      location.origin + '/order/fail/' + p.orderNumber,
            });
            return;
        }

        if (pg === 'kakaopay') {
            if (p.error) { alert('카카오페이 오류: ' + p.error); return; }
            location.href = p.redirectUrl;
            return;
        }

        if (pg === 'naverpay') {
            alert('네이버페이 결제창을 엽니다. (실제 SDK 연동 필요)');
            return;
        }

        if (pg === 'payco') {
            location.href = p.returnUrl || '/payment/callback/payco?order_id=' + p.orderId;
            return;
        }

        if (pg === 'inicis' || pg === 'nicepay') {
            const frm = document.createElement('form');
            frm.method = 'post';
            frm.action = pg === 'inicis'
                ? 'https://stdpay.inicis.com/stdjs/INIStdPay.js'
                : 'https://pay.nicepay.co.kr/v1/js/';
            Object.entries(p).forEach(function ([k, v]) {
                if (k === 'pg') return;
                const input = document.createElement('input');
                input.type  = 'hidden'; input.name = k; input.value = v;
                frm.appendChild(input);
            });
            document.body.appendChild(frm);
            frm.submit();
            return;
        }

        if (pg === 'bank_transfer') {
            location.href = p.redirectUrl;
            return;
        }

        alert('지원하지 않는 PG입니다: ' + pg);
    }

    // 초기 요약 렌더
    updateSummary();

})();
</script>
<?= $this->endSection() ?>
