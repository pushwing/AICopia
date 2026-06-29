<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$payment  = $order['payment'] ?? null;
$items    = $order['items']   ?? [];

$statusBadge = [
    'pending'           => ['secondary', '결제 대기'],
    'awaiting_payment'  => ['primary',   '입금 대기'],
    'paid'              => ['success',   '결제 완료'],
    'preparing'         => ['info',      '배송 준비'],
    'shipped'           => ['warning',   '배송 중'],
    'delivered'         => ['success',   '배송 완료'],
    'cancelled'         => ['danger',    '취소'],
    'expired'           => ['secondary', '만료'],
    'refund_requested'  => ['warning',   '환불 요청'],
    'refunded'          => ['dark',      '환불 완료'],
    'return_requested'   => ['warning',   '반품 요청'],
    'return_approved'    => ['info',      '반품 승인'],
    'exchange_requested' => ['warning',   '교환 요청'],
    'exchange_approved'  => ['info',      '교환 승인'],
    'exchange_completed' => ['success',   '교환 완료'],
];
$pgLabels = [
    'bank_transfer' => '무통장입금',
    'toss'          => '토스페이먼츠',
    'inicis'        => 'KG이니시스',
    'nicepay'       => '나이스페이',
    'kakaopay'      => '카카오페이',
    'naverpay'      => '네이버페이',
    'payco'         => 'PAYCO',
];
$methodLabels = [
    'card'            => '신용/체크카드',
    'virtual_account' => '가상계좌',
    'transfer'        => '계좌이체',
    'phone'           => '휴대폰',
    'kakaopay'        => '카카오페이',
    'naverpay'        => '네이버페이',
    'payco'           => 'PAYCO',
    '무통장입금'      => '무통장입금',
];

[$badgeColor, $badgeLabel] = $statusBadge[$order['status']] ?? ['secondary', $order['status']];
$canCancel          = in_array($order['status'], ['pending', 'awaiting_payment', 'paid'], true);
$canConfirmDelivery = $order['status'] === 'shipped';
$deliveredAt        = $order['delivered_at'] ?? null;
$returnDeadline     = $deliveredAt ? strtotime($deliveredAt) + 7 * 24 * 3600 : null;
$canReturn          = $order['status'] === 'delivered' && ($returnDeadline === null || time() <= $returnDeadline);
$canExchange        = $order['status'] === 'delivered' && ($returnDeadline === null || time() <= $returnDeadline);
$isBankTransfer     = ($payment['pg_provider'] ?? '') === 'bank_transfer';
?>

<div class="container py-4" style="max-width:680px">

    <!-- 헤더 -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/mypage/orders" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i>
        </a>
        <div>
            <h5 class="fw-bold mb-0">주문 상세</h5>
            <div class="text-muted small"><?= esc($order['order_number']) ?></div>
        </div>
        <span class="badge bg-<?= $badgeColor ?> ms-auto"><?= $badgeLabel ?></span>
    </div>

    <!-- 주문 상품 -->
    <div class="card mb-3">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-bag me-2 text-primary"></i>주문 상품
        </div>
        <div class="card-body p-0">
            <?php foreach ($items as $item): ?>
            <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                <a href="/shop/<?= esc($item['product_slug'] ?? $item['product_id']) ?>" class="flex-shrink-0">
                    <?php if (! empty($item['thumbnail'])): ?>
                    <img src="/<?= esc($item['thumbnail']) ?>" alt="<?= esc($item['product_name']) ?>"
                         style="width:64px;height:64px;object-fit:cover;border-radius:6px">
                    <?php else: ?>
                    <div class="bg-light d-flex align-items-center justify-content-center text-muted"
                         style="width:64px;height:64px;border-radius:6px;font-size:1.4rem">
                        <i class="bi bi-image"></i>
                    </div>
                    <?php endif; ?>
                </a>
                <div class="flex-grow-1 small">
                    <a href="/shop/<?= esc($item['product_slug'] ?? $item['product_id']) ?>" class="fw-semibold mb-1 text-dark text-decoration-none">
                        <?= esc($item['product_name']) ?>
                    </a>
                    <?php if (! empty($item['sku_option_label'])): ?>
                    <div class="text-muted" style="font-size:.75rem;margin-bottom:.1rem">
                        <i class="bi bi-tag me-1"></i><?= esc($item['sku_option_label']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="text-muted">
                        <?= number_format($item['product_price']) ?>원 × <?= (int) $item['qty'] ?>개
                    </div>
                </div>
                <div class="fw-bold"><?= number_format($item['subtotal']) ?>원</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 배송지 -->
    <div class="card mb-3">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-geo-alt me-2 text-primary"></i>배송지
        </div>
        <div class="card-body small">
            <div class="fw-semibold mb-1">
                <?= esc($order['receiver_name']) ?>
                <span class="text-muted fw-normal ms-2"><?= esc($order['receiver_phone']) ?></span>
            </div>
            <div class="text-muted">
                (<?= esc($order['zipcode']) ?>)
                <?= esc($order['address1']) ?>
                <?= ! empty($order['address2']) ? ' ' . esc($order['address2']) : '' ?>
            </div>
            <?php if (! empty($order['delivery_memo'])): ?>
            <div class="text-muted mt-1">
                <i class="bi bi-chat-left-text me-1"></i><?= esc($order['delivery_memo']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 배송 현황 -->
    <?php if (! empty($order['tracking_number'])): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-truck me-2 text-primary"></i>배송 현황
        </div>
        <div class="card-body small">
            <dl class="row mb-0">
                <dt class="col-4 fw-normal text-muted">택배사</dt>
                <dd class="col-8"><?= esc($order['tracking_company'] ?? '-') ?></dd>
                <dt class="col-4 fw-normal text-muted">운송장 번호</dt>
                <dd class="col-8">
                    <span class="me-2"><?= esc($order['tracking_number']) ?></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnCopyTracking"
                            data-tracking="<?= esc($order['tracking_number']) ?>">
                        <i class="bi bi-clipboard"></i> 복사
                    </button>
                </dd>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <!-- 무통장 입금 안내 -->
    <?php if ($isBankTransfer && $order['status'] === 'awaiting_payment'): ?>
    <div class="card mb-3 border-primary">
        <div class="card-header fw-semibold bg-primary text-white">
            <i class="bi bi-bank me-2"></i>무통장 입금 안내
        </div>
        <div class="card-body">
            <dl class="row mb-3 small">
                <dt class="col-5 fw-normal text-muted">입금 은행</dt>
                <dd class="col-7 fw-semibold"><?= esc($settings['bank_name'] ?? '—') ?></dd>

                <dt class="col-5 fw-normal text-muted">계좌번호</dt>
                <dd class="col-7">
                    <span id="bankAccount" class="fw-semibold me-2"><?= esc($settings['bank_account'] ?? '—') ?></span>
                    <?php if (! empty($settings['bank_account'])): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnCopyAccount"
                            data-account="<?= esc($settings['bank_account']) ?>">
                        <i class="bi bi-clipboard"></i> 복사
                    </button>
                    <?php endif; ?>
                </dd>

                <dt class="col-5 fw-normal text-muted">예금주</dt>
                <dd class="col-7 fw-semibold"><?= esc($settings['bank_holder'] ?? '—') ?></dd>
            </dl>
            <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                <span class="fw-semibold text-muted small">입금하실 금액</span>
                <span class="fs-5 fw-bold text-primary"><?= number_format($order['payable_amount'] ?? $order['total_amount']) ?>원</span>
            </div>
            <div class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>
                입금자명은 주문자 성함과 동일하게 입금해 주세요. 입금 확인 후 주문이 처리됩니다.
            </div>
        </div>
    </div>
    <?php elseif ($isBankTransfer && $order['status'] === 'paid'): ?>
    <div class="alert alert-success d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <span>입금이 확인되었습니다. 주문이 정상적으로 처리되었습니다.</span>
    </div>
    <?php endif; ?>

    <!-- 결제 정보 -->
    <div class="card mb-4">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-credit-card me-2 text-primary"></i>결제 정보
        </div>
        <div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-5 fw-normal text-muted">상품 합계</dt>
                <dd class="col-7"><?= number_format($order['total_product_price']) ?>원</dd>

                <dt class="col-5 fw-normal text-muted">배송비</dt>
                <dd class="col-7"><?= (int) $order['shipping_fee'] > 0 ? number_format($order['shipping_fee']) . '원' : '무료' ?></dd>

                <?php if ((int) ($order['coupon_discount_amount'] ?? 0) > 0): ?>
                <dt class="col-5 fw-normal text-muted">쿠폰 할인</dt>
                <dd class="col-7 text-danger">- <?= number_format($order['coupon_discount_amount']) ?>원</dd>
                <?php endif; ?>

                <?php if ((int) ($order['point_used_amount'] ?? 0) > 0): ?>
                <dt class="col-5 fw-normal text-muted">포인트 사용</dt>
                <dd class="col-7 text-danger">- <?= number_format($order['point_used_amount']) ?>원</dd>
                <?php endif; ?>

                <dt class="col-5 fw-bold text-dark border-top pt-2 mt-1">최종 결제 금액</dt>
                <dd class="col-7 fw-bold text-primary border-top pt-2 mt-1"><?= number_format($order['payable_amount'] ?? $order['total_amount']) ?>원</dd>

                <?php if ($payment): ?>
                <dt class="col-5 fw-normal text-muted mt-2">결제 수단</dt>
                <dd class="col-7 mt-2">
                    <?= esc($pgLabels[$payment['pg_provider']] ?? $payment['pg_provider']) ?>
                    <?php if ($payment['method']): ?>
                    · <?= esc($methodLabels[$payment['method']] ?? $payment['method']) ?>
                    <?php endif; ?>
                </dd>

                <dt class="col-5 fw-normal text-muted">결제 일시</dt>
                <dd class="col-7">
                    <?= $payment['paid_at'] ? date('Y년 n월 j일 G시 i분', strtotime($payment['paid_at'])) : '-' ?>
                </dd>
                <?php endif; ?>

                <dt class="col-5 fw-normal text-muted">주문 일시</dt>
                <dd class="col-7"><?= date('Y년 n월 j일 G시 i분', strtotime($order['created_at'])) ?></dd>
            </dl>
        </div>
    </div>

    <!-- 반품 사유 / 상태 안내 -->
    <?php if (in_array($order['status'], ['return_requested', 'return_approved', 'refunded'], true) && ! empty($order['return_reason'])): ?>
    <div class="alert alert-<?= $order['status'] === 'return_approved' ? 'info' : 'warning' ?> d-flex gap-2 mb-3">
        <i class="bi bi-arrow-return-left fs-5 flex-shrink-0"></i>
        <div class="w-100">
            <div class="fw-semibold small">
                <?= $order['status'] === 'return_approved' ? '반품 승인 — 환불 처리 중' : '반품 신청 사유' ?>
            </div>
            <div class="small mt-1"><?= esc($order['return_reason']) ?></div>
            <?php if (! empty($order['return_reason_note'])): ?>
            <div class="small text-muted mt-1"><?= esc($order['return_reason_note']) ?></div>
            <?php endif; ?>
            <?php if ($returnReasonPayer): ?>
            <div class="small mt-2 <?= $returnReasonPayer === 'seller' ? 'text-info' : 'text-muted' ?>">
                <i class="bi bi-truck me-1"></i>
                <?= $returnReasonPayer === 'seller' ? '수거 택배비: 판매자 부담' : '반품 택배비: 구매자 부담' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 교환 상태 안내 -->
    <?php if (in_array($order['status'], ['exchange_requested', 'exchange_approved', 'exchange_completed'], true) && ! empty($order['exchange_reason'])): ?>
    <?php $exchPayer = $exchangeReasonPayer ?? null; ?>
    <div class="alert alert-<?= $order['status'] === 'exchange_completed' ? 'success' : ($order['status'] === 'exchange_approved' ? 'info' : 'warning') ?> d-flex gap-2 mb-3">
        <i class="bi bi-arrow-left-right fs-5 flex-shrink-0"></i>
        <div class="w-100">
            <div class="fw-semibold small">
                <?php if ($order['status'] === 'exchange_requested'): ?>교환 신청 사유
                <?php elseif ($order['status'] === 'exchange_approved'): ?>교환 승인 — 대체품 발송 준비 중
                <?php else: ?>교환 완료
                <?php endif; ?>
            </div>
            <div class="small mt-1"><?= esc($order['exchange_reason']) ?></div>
            <?php if (! empty($order['exchange_request_note'])): ?>
            <div class="small text-muted mt-1">요청 내용: <?= esc($order['exchange_request_note']) ?></div>
            <?php endif; ?>
            <?php if ($exchPayer): ?>
            <div class="small mt-2 <?= $exchPayer === 'seller' ? 'text-info' : 'text-muted' ?>">
                <i class="bi bi-truck me-1"></i>
                <?= $exchPayer === 'seller' ? '교환 택배비: 판매자 부담' : '교환 택배비: 구매자 부담 (고객 직접 발송)' ?>
            </div>
            <?php endif; ?>
            <?php if ($order['status'] === 'exchange_completed' && ! empty($order['exchange_tracking_number'])): ?>
            <div class="small mt-2 text-success">
                <i class="bi bi-truck me-1"></i>교환품 발송:
                <?php if (! empty($order['exchange_tracking_company'])): ?>
                <?= esc($order['exchange_tracking_company']) ?> &nbsp;
                <?php endif; ?>
                <?= esc($order['exchange_tracking_number']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 반품 신청 기간 만료 안내 -->
    <?php if ($order['status'] === 'delivered' && $returnDeadline !== null && time() > $returnDeadline): ?>
    <div class="alert alert-secondary small mb-3">반품·교환 신청 기간(7일)이 지났습니다.</div>
    <?php endif; ?>

    <!-- 버튼 -->
    <div class="d-flex gap-2">
        <a href="/mypage/orders" class="btn btn-outline-secondary flex-fill">목록으로</a>
        <?php if ($canConfirmDelivery): ?>
        <button type="button" id="btnConfirmDelivery" class="btn btn-success flex-fill"
                data-order-id="<?= (int) $order['id'] ?>"
                data-csrf="<?= csrf_token() ?>"
                data-csrf-val="<?= csrf_hash() ?>">
            <i class="bi bi-check2-circle me-1"></i>배송 완료 확인
        </button>
        <?php elseif ($canCancel): ?>
        <button type="button" id="btnCancel" class="btn btn-danger flex-fill"
                data-order-id="<?= (int) $order['id'] ?>"
                data-csrf="<?= csrf_token() ?>"
                data-csrf-val="<?= csrf_hash() ?>">
            주문 취소
        </button>
        <?php elseif ($canReturn): ?>
        <button type="button" class="btn btn-warning flex-fill" data-bs-toggle="modal" data-bs-target="#returnModal">
            <i class="bi bi-arrow-return-left me-1"></i>반품 신청
        </button>
        <button type="button" class="btn btn-outline-warning flex-fill" data-bs-toggle="modal" data-bs-target="#exchangeModal">
            <i class="bi bi-arrow-left-right me-1"></i>교환 신청
        </button>
        <?php else: ?>
        <a href="/shop" class="btn btn-primary flex-fill">쇼핑 계속하기</a>
        <?php endif; ?>
    </div>

    <!-- 반품 신청 모달 -->
    <?php if ($canReturn): ?>
    <div class="modal fade" id="returnModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">반품 신청</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">반품 사유 <span class="text-danger">*</span></label>
                        <select id="returnReasonCode" class="form-select">
                            <option value="">사유를 선택해주세요</option>
                            <?php foreach ($returnReasonCodes as $code => $meta): ?>
                            <option value="<?= $code ?>" data-payer="<?= $meta['payer'] ?>"><?= esc($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 택배비 부담 안내 (사유 선택 시 표시) -->
                    <div id="returnShippingNotice" class="alert small py-2 mb-3 d-none">
                        <i class="bi bi-truck me-1"></i><span id="returnShippingText"></span>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-semibold">상세 사유 <span class="text-muted fw-normal">(선택)</span></label>
                        <textarea id="returnNote" class="form-control" rows="3"
                                  placeholder="추가로 전달할 내용이 있으면 입력해주세요."
                                  maxlength="500"></textarea>
                        <div class="text-end text-muted small mt-1"><span id="returnNoteLen">0</span>/500</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button" id="btnReturnSubmit" class="btn btn-warning"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        반품 신청하기
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 교환 신청 모달 -->
    <?php if ($canExchange): ?>
    <div class="modal fade" id="exchangeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold">교환 신청</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">교환 사유 <span class="text-danger">*</span></label>
                        <select id="exchangeReasonCode" class="form-select">
                            <option value="">사유를 선택해주세요</option>
                            <?php foreach ($exchangeReasonCodes ?? [] as $code => $meta): ?>
                            <option value="<?= $code ?>" data-payer="<?= $meta['payer'] ?>"><?= esc($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 배송비 부담 안내 -->
                    <div id="exchangeShippingNotice" class="alert small py-2 mb-3 d-none">
                        <i class="bi bi-truck me-1"></i><span id="exchangeShippingText"></span>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-semibold">요청 내용 <span class="text-muted fw-normal">(선택)</span></label>
                        <textarea id="exchangeNote" class="form-control" rows="3"
                                  placeholder="원하는 교환 내용을 입력해주세요 (예: L→M 사이즈, 파랑→검정 색상)"
                                  maxlength="1000"></textarea>
                        <div class="text-end text-muted small mt-1"><span id="exchangeNoteLen">0</span>/1000</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="button" id="btnExchangeSubmit" class="btn btn-warning"
                            data-order-id="<?= (int) $order['id'] ?>"
                            data-csrf="<?= csrf_token() ?>"
                            data-csrf-val="<?= csrf_hash() ?>">
                        교환 신청하기
                    </button>
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
    function makeCopyHandler(btnId, dataKey) {
        document.getElementById(btnId)?.addEventListener('click', function () {
            navigator.clipboard.writeText(this.dataset[dataKey]).then(() => {
                const btn = this;
                btn.innerHTML = '<i class="bi bi-check2"></i> 복사됨';
                btn.classList.replace('btn-outline-secondary', 'btn-success');
                setTimeout(() => {
                    btn.innerHTML = '<i class="bi bi-clipboard"></i> 복사';
                    btn.classList.replace('btn-success', 'btn-outline-secondary');
                }, 2000);
            });
        });
    }
    makeCopyHandler('btnCopyAccount',  'account');
    makeCopyHandler('btnCopyTracking', 'tracking');

    // 배송 완료 확인
    document.getElementById('btnConfirmDelivery')?.addEventListener('click', function () {
        if (! confirm('배송이 완료되었나요?\n확인 후에는 되돌릴 수 없습니다.')) return;

        const btn  = this;
        const body = new FormData();
        body.append(btn.dataset.csrf, btn.dataset.csrfVal);
        body.append('order_id', btn.dataset.orderId);

        btn.disabled    = true;
        btn.textContent = '처리 중...';

        fetch('/mypage/orders/confirm-delivery', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '처리에 실패했습니다.');
                    btn.disabled    = false;
                    btn.innerHTML   = '<i class="bi bi-check2-circle me-1"></i>배송 완료 확인';
                }
            })
            .catch(() => {
                alert('오류가 발생했습니다. 다시 시도해주세요.');
                btn.disabled    = false;
                btn.innerHTML   = '<i class="bi bi-check2-circle me-1"></i>배송 완료 확인';
            });
    });

    // 반품 신청 — 사유 선택 시 택배비 안내 토글
    document.getElementById('returnReasonCode')?.addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        const notice   = document.getElementById('returnShippingNotice');
        const text     = document.getElementById('returnShippingText');
        const payer    = selected.dataset.payer;

        if (! payer) {
            notice.classList.add('d-none');
            return;
        }
        if (payer === 'seller') {
            notice.className    = 'alert alert-info small py-2 mb-3';
            text.textContent    = '수거 택배비는 판매자가 부담합니다.';
        } else {
            notice.className    = 'alert alert-warning small py-2 mb-3';
            text.textContent    = '반품 택배비는 구매자가 부담합니다.';
        }
    });

    document.getElementById('returnNote')?.addEventListener('input', function () {
        document.getElementById('returnNoteLen').textContent = this.value.length;
    });

    document.getElementById('btnReturnSubmit')?.addEventListener('click', function () {
        const reasonCode = document.getElementById('returnReasonCode').value;
        if (! reasonCode) { alert('반품 사유를 선택해주세요.'); return; }

        const note = document.getElementById('returnNote').value.trim();
        const btn  = this;
        const body = new FormData();
        body.append(btn.dataset.csrf, btn.dataset.csrfVal);
        body.append('order_id', btn.dataset.orderId);
        body.append('reason_code', reasonCode);
        if (note) body.append('note', note);

        btn.disabled    = true;
        btn.textContent = '처리 중...';

        fetch('/mypage/orders/return-request', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '처리에 실패했습니다.');
                    btn.disabled    = false;
                    btn.textContent = '반품 신청하기';
                }
            })
            .catch(() => {
                alert('오류가 발생했습니다. 다시 시도해주세요.');
                btn.disabled    = false;
                btn.textContent = '반품 신청하기';
            });
    });

    // 교환 신청 — 사유 선택 시 배송비 안내 토글
    document.getElementById('exchangeReasonCode')?.addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        const notice   = document.getElementById('exchangeShippingNotice');
        const text     = document.getElementById('exchangeShippingText');
        const payer    = selected.dataset.payer;

        if (! payer) {
            notice.classList.add('d-none');
            return;
        }
        if (payer === 'seller') {
            notice.className = 'alert alert-info small py-2 mb-3';
            text.textContent = '교환 택배비는 판매자가 부담합니다.';
        } else {
            notice.className = 'alert alert-warning small py-2 mb-3';
            text.textContent = '교환 택배비는 구매자가 부담합니다. 상품을 먼저 발송해주세요.';
        }
    });

    document.getElementById('exchangeNote')?.addEventListener('input', function () {
        document.getElementById('exchangeNoteLen').textContent = this.value.length;
    });

    document.getElementById('btnExchangeSubmit')?.addEventListener('click', function () {
        const reasonCode = document.getElementById('exchangeReasonCode').value;
        if (! reasonCode) { alert('교환 사유를 선택해주세요.'); return; }

        const btn  = this;
        const body = new FormData();
        body.append(btn.dataset.csrf, btn.dataset.csrfVal);
        body.append('order_id', btn.dataset.orderId);
        body.append('reason_code', reasonCode);
        const note = document.getElementById('exchangeNote').value.trim();
        if (note) body.append('note', note);

        btn.disabled    = true;
        btn.textContent = '처리 중...';

        fetch('/mypage/orders/exchange-request', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '처리에 실패했습니다.');
                    btn.disabled    = false;
                    btn.textContent = '교환 신청하기';
                }
            })
            .catch(() => {
                alert('오류가 발생했습니다. 다시 시도해주세요.');
                btn.disabled    = false;
                btn.textContent = '교환 신청하기';
            });
    });

    document.getElementById('btnCancel')?.addEventListener('click', function () {
        if (! confirm('주문을 취소하시겠습니까?\n취소 후에는 되돌릴 수 없습니다.')) return;

        const btn     = this;
        const body    = new FormData();
        body.append(btn.dataset.csrf, btn.dataset.csrfVal);
        body.append('order_id', btn.dataset.orderId);

        btn.disabled    = true;
        btn.textContent = '처리 중...';

        fetch('/mypage/orders/cancel', { method: 'POST', body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.href = '/mypage/orders';
                } else {
                    alert(data.message || '취소에 실패했습니다.');
                    btn.disabled    = false;
                    btn.textContent = '주문 취소';
                }
            })
            .catch(function () {
                alert('오류가 발생했습니다. 다시 시도해주세요.');
                btn.disabled    = false;
                btn.textContent = '주문 취소';
            });
    });
})();
</script>
<?= $this->endSection() ?>
