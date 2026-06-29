<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '주문 상세' ?>

<?= $this->section('content') ?>

<?php
$payment  = $order['payment'] ?? null;
$items    = $order['items']   ?? [];

$statusBadge = [
    'pending'           => 'secondary',
    'awaiting_payment'  => 'primary',
    'paid'              => 'success',
    'preparing'         => 'info',
    'shipped'           => 'warning',
    'delivered'         => 'success',
    'cancelled'         => 'danger',
    'expired'           => 'secondary',
    'refund_requested'  => 'warning',
    'refunded'          => 'dark',
    'return_requested'   => 'warning',
    'return_approved'    => 'info',
    'exchange_requested' => 'warning',
    'exchange_approved'  => 'info',
    'exchange_completed' => 'success',
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

$currentStatus      = $order['status'];
$next               = $nextStatus[$currentStatus] ?? null;
$canCancel              = in_array($currentStatus, ['pending', 'awaiting_payment', 'paid', 'preparing'], true);
$canRefund              = $currentStatus === 'refund_requested';
$canApproveReturn       = $currentStatus === 'return_requested';
$canConfirmReturnRefund = $currentStatus === 'return_approved';
$canApproveExchange     = $currentStatus === 'exchange_requested';
$canCompleteExchange    = $currentStatus === 'exchange_approved';
$isBankTransfer     = ($payment['pg_provider'] ?? '') === 'bank_transfer';
$canConfirmBank     = $isBankTransfer && $currentStatus === 'awaiting_payment';
?>

<!-- 헤더 -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a href="/admin/orders" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i> 목록
    </a>
    <div>
        <h5 class="fw-bold mb-0">주문 상세</h5>
        <div class="text-muted small"><?= esc($order['order_number']) ?></div>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
        <?php if ($payment): ?>
        <span class="badge bg-light text-dark border fs-6">
            <?= esc($pgLabels[$payment['pg_provider']] ?? $payment['pg_provider']) ?>
            <?php if ($payment['method']): ?>
            <span class="fw-normal text-muted">· <?= esc($payment['method']) ?></span>
            <?php endif; ?>
        </span>
        <?php endif; ?>
        <span class="badge bg-<?= $statusBadge[$currentStatus] ?? 'secondary' ?> fs-6">
            <?= $statusLabels[$currentStatus] ?? $currentStatus ?>
        </span>
    </div>
</div>


<div class="row g-4">

    <!-- 좌측: 상품 · 배송지 · 결제 -->
    <div class="col-lg-8">

        <!-- 주문 상품 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">주문 상품</div>
            <div class="card-body p-0">
                <?php foreach ($items as $item): ?>
                <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                    <div class="flex-grow-1 small">
                        <div class="fw-semibold mb-1"><?= esc($item['product_name']) ?></div>
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
            <div class="card-header fw-semibold bg-white">배송지</div>
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
                <div class="text-muted mt-1">배송 메모: <?= esc($order['delivery_memo']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 결제 정보 -->
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">결제 정보</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 fw-normal text-muted">회원</dt>
                    <dd class="col-8">
                        <?php if (!empty($order['user_id'])): ?>
                        <a href="/admin/users/<?= (int) $order['user_id'] ?>/edit" class="text-decoration-none">
                            <?= esc($order['user_nickname'] ?? '-') ?>
                        </a>
                        <?php if ($order['user_email']): ?>
                        <span class="text-muted ms-1">(<?= esc($order['user_email']) ?>)</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <?= esc($order['user_nickname'] ?? '-') ?>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-4 fw-normal text-muted">상품 합계</dt>
                    <dd class="col-8"><?= number_format($order['total_product_price']) ?>원</dd>

                    <dt class="col-4 fw-normal text-muted">배송비</dt>
                    <dd class="col-8">
                    <?php if ((int) $order['shipping_fee'] > 0): ?>
                        <?= number_format($order['shipping_fee']) ?>원
                    <?php else:
                        $totalProduct = (int) $order['total_product_price'];
                        $freeReason   = '무료';
                        $hasAllFree   = true;
                        $metThreshold = null;
                        foreach ($order['items'] as $_item) {
                            $type = $_item['shipping_type'] ?? '';
                            if ($type !== 'free') $hasAllFree = false;
                            if ($type === 'conditional' && (int) $_item['free_threshold'] > 0
                                && $totalProduct >= (int) $_item['free_threshold']) {
                                $metThreshold = (int) $_item['free_threshold'];
                            }
                        }
                        if ($metThreshold !== null) {
                            $freeReason = '무료 <span class="text-muted small">(조건부 무료, ' . number_format($metThreshold) . '원 이상 구매)</span>';
                        } elseif ($hasAllFree) {
                            $freeReason = '무료 <span class="text-muted small">(무료배송 상품)</span>';
                        }
                    ?>
                        <?= $freeReason ?>
                    <?php endif; ?>
                    </dd>

                    <?php if ((int) ($order['coupon_discount_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">쿠폰 할인</dt>
                    <dd class="col-8 text-danger">- <?= number_format($order['coupon_discount_amount']) ?>원</dd>
                    <?php endif; ?>

                    <?php if ((int) ($order['point_used_amount'] ?? 0) > 0): ?>
                    <dt class="col-4 fw-normal text-muted">포인트 사용</dt>
                    <dd class="col-8 text-danger">- <?= number_format($order['point_used_amount']) ?>원</dd>
                    <?php endif; ?>

                    <dt class="col-4 fw-bold text-dark border-top pt-2 mt-1">최종 결제금액</dt>
                    <dd class="col-8 fw-bold text-primary border-top pt-2 mt-1"><?= number_format($order['payable_amount'] ?? $order['total_amount']) ?>원</dd>

                    <?php if ($payment): ?>
                    <dt class="col-4 fw-normal text-muted mt-2">결제 수단</dt>
                    <dd class="col-8 mt-2 fw-semibold">
                        <?= esc($pgLabels[$payment['pg_provider']] ?? $payment['pg_provider']) ?>
                        <?php if ($payment['method']): ?>
                        <span class="fw-normal text-muted ms-1">· <?= esc($payment['method']) ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($payment['pg_provider'] !== 'bank_transfer'): ?>
                    <dt class="col-4 fw-normal text-muted">PG TID</dt>
                    <dd class="col-8 font-monospace small text-muted"><?= esc($payment['pg_tid'] ?: '-') ?></dd>
                    <?php endif; ?>
                    <dt class="col-4 fw-normal text-muted">결제 일시</dt>
                    <dd class="col-8">
                        <?= $payment['paid_at'] ? date('Y년 n월 j일 G시 i분', strtotime($payment['paid_at'])) : '<span class="text-warning">미확인</span>' ?>
                    </dd>
                    <?php endif; ?>

                    <dt class="col-4 fw-normal text-muted">주문 일시</dt>
                    <dd class="col-8"><?= date('Y년 n월 j일 G시 i분', strtotime($order['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

    </div>

    <!-- 우측: 관리 패널 -->
    <div class="col-lg-4">

        <!-- 무통장 입금 확인 -->
        <?php if ($canConfirmBank): ?>
        <div class="card mb-3 border-primary">
            <div class="card-header fw-semibold bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-bank me-1"></i>무통장 입금 확인
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    입금 금액: <strong class="text-dark"><?= number_format((int) ($order['payable_amount'] ?? $order['total_amount'])) ?>원</strong><br>
                    입금자명: <strong class="text-dark"><?= esc($order['receiver_name']) ?></strong>
                </p>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/bank_confirm"
                      onsubmit="return confirm('입금을 확인하셨습니까?\n확인 시 재고가 차감되고 결제 완료 처리됩니다.')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-circle me-1"></i>입금 확인 처리
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 상태 변경 -->
        <?php if ($next): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">상태 변경</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    현재: <strong><?= $statusLabels[$currentStatus] ?></strong>
                    → 다음: <strong><?= $statusLabels[$next] ?></strong>
                </p>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/status">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="<?= esc($next) ?>">
                    <button type="submit" class="btn btn-primary w-100">
                        <?= $statusLabels[$next] ?>으로 변경
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 송장번호 입력 -->
        <?php if (in_array($currentStatus, ['preparing', 'shipped', 'delivered'], true)): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">송장번호</div>
            <div class="card-body">
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/tracking">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <?php
                            $savedCarrier  = $order['tracking_company'] ?? '';
                            $carrierInList = in_array($savedCarrier, $carriers ?? [], true);
                        ?>
                        <?php if (! empty($carriers)): ?>
                        <select name="tracking_company" class="form-select form-select-sm">
                            <option value="">배송업체 선택</option>
                            <?php foreach ($carriers as $c): ?>
                            <option value="<?= esc($c) ?>" <?= $savedCarrier === $c ? 'selected' : '' ?>><?= esc($c) ?></option>
                            <?php endforeach; ?>
                            <?php if ($savedCarrier !== '' && ! $carrierInList): ?>
                            <option value="<?= esc($savedCarrier) ?>" selected><?= esc($savedCarrier) ?></option>
                            <?php endif; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="tracking_company" class="form-control form-control-sm"
                               placeholder="택배사 (예: CJ대한통운)"
                               value="<?= esc($savedCarrier) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="tracking_number" class="form-control form-control-sm"
                               placeholder="운송장 번호"
                               value="<?= esc($order['tracking_number'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">저장</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 환불 처리 (B안: PG 콘솔에서 취소 후 상태만 변경) -->
        <?php if ($canRefund): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header fw-semibold bg-warning bg-opacity-10">환불 처리</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    PG 콘솔에서 취소를 완료한 후 아래 버튼을 눌러 환불 완료 처리하세요.
                    <?php if ($payment): ?>
                    <br>PG TID: <code><?= esc($payment['pg_tid'] ?? '-') ?></code>
                    <br>금액: <strong><?= number_format($payment['amount'] ?? 0) ?>원</strong>
                    <?php endif; ?>
                </p>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/refund"
                      onsubmit="return confirm('PG 콘솔에서 환불을 완료하셨습니까?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-warning w-100">환불 완료 처리</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 반품 요청 처리 -->
        <?php if ($canApproveReturn): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header fw-semibold bg-warning bg-opacity-10">
                <i class="bi bi-arrow-return-left me-1"></i>반품 요청 처리
            </div>
            <div class="card-body">
                <?php if (! empty($order['return_reason'])): ?>
                <p class="text-muted small mb-2">
                    <strong>반품 사유:</strong> <?= esc($order['return_reason']) ?>
                    <?php if ($returnReasonPayer): ?>
                    <span class="badge <?= $returnReasonPayer === 'seller' ? 'bg-info' : 'bg-secondary' ?> ms-1">
                        <?= $returnReasonPayer === 'seller' ? '판매자 택배비 부담' : '구매자 택배비 부담' ?>
                    </span>
                    <?php endif; ?>
                </p>
                <?php if (! empty($order['return_reason_note'])): ?>
                <p class="text-muted small mb-2">
                    <strong>상세 사유:</strong><br><?= esc($order['return_reason_note']) ?>
                </p>
                <?php endif; ?>
                <?php endif; ?>
                <p class="text-muted small mb-3">
                    승인 시 재고·쿠폰·포인트가 복구됩니다.<br>
                    실제 PG 환불은 승인 후 별도 단계에서 처리합니다.
                </p>
                <div class="d-flex gap-2">
                    <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/return-approve"
                          onsubmit="return confirm('반품을 승인하시겠습니까?\n재고·쿠폰·포인트가 복구됩니다.')"
                          class="flex-fill">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-check-circle me-1"></i>반품 승인
                        </button>
                    </form>
                    <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/return-reject"
                          onsubmit="return confirm('반품을 거부하시겠습니까?\n주문이 배송 완료 상태로 복원됩니다.')"
                          class="flex-fill">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-1"></i>반품 거부
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 반품 승인 → 환불 완료 -->
        <?php if ($canConfirmReturnRefund): ?>
        <div class="card mb-3 border-info">
            <div class="card-header fw-semibold bg-info bg-opacity-10">
                <i class="bi bi-cash-coin me-1"></i>반품 환불 완료 처리
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    PG 콘솔에서 환불을 완료한 후 아래 버튼을 눌러주세요.
                    <?php if ($payment): ?>
                    <br>PG TID: <code><?= esc($payment['pg_tid'] ?? '-') ?></code>
                    <br>금액: <strong><?= number_format($payment['amount'] ?? 0) ?>원</strong>
                    <?php endif; ?>
                </p>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/return-refund"
                      onsubmit="return confirm('PG 콘솔에서 환불을 완료하셨습니까?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-info w-100">
                        <i class="bi bi-check2-all me-1"></i>환불 완료 처리
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 교환 요청 처리 -->
        <?php if ($canApproveExchange): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header fw-semibold bg-warning bg-opacity-10">
                <i class="bi bi-arrow-left-right me-1"></i>교환 요청 처리
            </div>
            <div class="card-body">
                <?php if (! empty($order['exchange_reason'])): ?>
                <p class="text-muted small mb-2">
                    <strong>교환 사유:</strong> <?= esc($order['exchange_reason']) ?>
                    <?php if ($exchangeReasonPayer): ?>
                    <span class="badge <?= $exchangeReasonPayer === 'seller' ? 'bg-info' : 'bg-secondary' ?> ms-1">
                        <?= $exchangeReasonPayer === 'seller' ? '판매자 배송비 부담' : '구매자 배송비 부담' ?>
                    </span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if (! empty($order['exchange_request_note'])): ?>
                <p class="text-muted small mb-3">
                    <strong>요청 내용:</strong><br><?= esc($order['exchange_request_note']) ?>
                </p>
                <?php endif; ?>
                <p class="text-muted small mb-3">
                    승인 시 기존 상품 재고가 복구됩니다.<br>
                    대체품은 승인 후 직접 발송하세요.
                </p>
                <div class="d-flex gap-2">
                    <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/exchange-approve"
                          onsubmit="return confirm('교환을 승인하시겠습니까?\n기존 상품 재고가 복구됩니다.')"
                          class="flex-fill">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-check-circle me-1"></i>교환 승인
                        </button>
                    </form>
                    <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/exchange-reject"
                          onsubmit="return confirm('교환을 거부하시겠습니까?\n주문이 배송 완료 상태로 복원됩니다.')"
                          class="flex-fill">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-1"></i>교환 거부
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 교환 승인 → 완료 (대체품 지정) -->
        <?php if ($canCompleteExchange): ?>
        <?php
            $originalTotal = array_sum(array_column($items, 'subtotal'));
        ?>
        <div class="card mb-3 border-info" id="exchangeCompleteCard">
            <div class="card-header fw-semibold bg-info bg-opacity-10">
                <i class="bi bi-box-seam me-1"></i>교환 완료 — 대체품 지정
            </div>
            <div class="card-body">
                <?php if (! empty($order['exchange_request_note'])): ?>
                <p class="text-muted small mb-2">
                    <strong>교환 요청 내용:</strong><br><?= esc($order['exchange_request_note']) ?>
                </p>
                <?php endif; ?>

                <!-- 원주문 금액 -->
                <div class="alert alert-secondary py-2 small mb-3">
                    원주문 금액: <strong id="originalTotal"><?= number_format($originalTotal) ?>원</strong>
                    &nbsp;|&nbsp; 대체품 합계: <strong id="exchangeTotalDisplay">0원</strong>
                    <span id="priceMatchBadge" class="badge bg-danger ms-1">금액 불일치</span>
                </div>

                <!-- 상품 검색 -->
                <div class="input-group input-group-sm mb-2">
                    <input type="text" id="exchSearchQ" class="form-control" placeholder="대체품 상품명 검색">
                    <button type="button" class="btn btn-outline-secondary" id="btnExchSearch">검색</button>
                </div>
                <div id="exchSearchResults" style="display:none;max-height:200px;overflow-y:auto" class="border rounded mb-3">
                    <table class="table table-sm table-hover mb-0 small align-middle" id="exchSearchTable">
                        <tbody id="exchSearchBody"></tbody>
                    </table>
                </div>

                <!-- 선택된 대체품 목록 -->
                <div class="small fw-semibold text-muted mb-1">선택된 대체품</div>
                <div class="border rounded mb-3" style="min-height:48px">
                    <table class="table table-sm mb-0 small" id="exchSelectedTable">
                        <thead class="table-light">
                            <tr><th>상품</th><th style="width:60px">단가</th><th style="width:55px">수량</th><th style="width:70px">소계</th><th style="width:28px"></th></tr>
                        </thead>
                        <tbody id="exchSelectedBody">
                            <tr id="exchEmptyRow"><td colspan="5" class="text-center text-muted py-2">대체품을 검색해서 추가하세요</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 송장 입력 -->
                <?php if ($exchangeReasonPayer === 'seller'): ?>
                <div class="mb-3 p-2 bg-info bg-opacity-10 rounded">
                    <div class="small fw-semibold text-info mb-2">
                        <i class="bi bi-truck me-1"></i>수거 송장 (판매자 귀책 — 수거 택배 정보)
                    </div>
                    <div class="input-group input-group-sm mb-1">
                        <input type="text" id="exchReturnCompany" class="form-control" placeholder="택배사">
                        <input type="text" id="exchReturnNumber" class="form-control" placeholder="수거 운송장 번호">
                    </div>
                    <div class="small fw-semibold text-muted mt-2 mb-1">
                        <i class="bi bi-cash me-1"></i>회사 부담 배송비 <span class="fw-normal">(정산용)</span>
                    </div>
                    <input type="number" id="exchSellerFee" class="form-control form-control-sm" placeholder="금액 (원)" min="0">
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <div class="small fw-semibold mb-1">
                        <i class="bi bi-box-seam me-1"></i>교환품 발송 송장 <span class="text-danger">*</span>
                    </div>
                    <div class="input-group input-group-sm">
                        <input type="text" id="exchTrackingCompany" class="form-control" placeholder="택배사 (예: CJ대한통운)">
                        <input type="text" id="exchTrackingNumber" class="form-control" placeholder="운송장 번호">
                    </div>
                </div>

                <!-- 제출 -->
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/exchange-complete" id="exchCompleteForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="exchange_items_json" id="exchItemsJson" value="[]">
                    <input type="hidden" name="exchange_tracking_company" id="exchTrackingCompanyHidden">
                    <input type="hidden" name="exchange_tracking_number" id="exchTrackingNumberHidden">
                    <?php if ($exchangeReasonPayer === 'seller'): ?>
                    <input type="hidden" name="exchange_return_tracking_company" id="exchReturnCompanyHidden">
                    <input type="hidden" name="exchange_return_tracking_number" id="exchReturnNumberHidden">
                    <input type="hidden" name="exchange_seller_shipping_fee" id="exchSellerFeeHidden">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-info w-100" id="btnExchComplete" disabled>
                        <i class="bi bi-check2-all me-1"></i>교환 완료 처리
                    </button>
                </form>
            </div>
        </div>

        <script>
        (function () {
            const ORIGINAL_TOTAL = <?= (int) $originalTotal ?>;
            const selectedMap    = {}; // key: "productId_skuId"

            // ── 검색 ──────────────────────────────────────────────────────
            document.getElementById('btnExchSearch').addEventListener('click', doSearch);
            document.getElementById('exchSearchQ').addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
            });

            function doSearch() {
                const q = document.getElementById('exchSearchQ').value.trim();
                fetch('/admin/orders/exchange-product-search?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => renderSearchResults(data.products || []));
            }

            function renderSearchResults(products) {
                const tbody = document.getElementById('exchSearchBody');
                tbody.innerHTML = '';
                if (! products.length) {
                    tbody.innerHTML = '<tr><td class="text-center text-muted py-2">검색 결과 없음</td></tr>';
                    document.getElementById('exchSearchResults').style.display = '';
                    return;
                }
                products.forEach(p => {
                    if (p.skus && p.skus.length > 0) {
                        p.skus.forEach(s => {
                            const tr = makeSearchRow(p, s);
                            tbody.appendChild(tr);
                        });
                    } else {
                        const tr = makeSearchRow(p, null);
                        tbody.appendChild(tr);
                    }
                });
                document.getElementById('exchSearchResults').style.display = '';
            }

            function makeSearchRow(p, sku) {
                const tr     = document.createElement('tr');
                const price  = sku ? sku.sell_price : p.sell_price;
                const stock  = sku ? sku.sku_stock  : p.stock;
                const label  = sku ? esc(p.name) + ' <span class="text-muted">/ ' + esc(sku.option_label) + '</span>' : esc(p.name);
                tr.innerHTML = `<td>${label}</td>
                    <td class="text-end">${num(price)}원</td>
                    <td class="text-muted">재고 ${stock}</td>
                    <td><button type="button" class="btn btn-sm btn-link p-0 text-primary"
                        data-pid="${p.id}" data-sid="${sku ? sku.sku_id : ''}"
                        data-name="${esc(p.name)}" data-label="${sku ? esc(sku.option_label) : ''}"
                        data-price="${price}" data-stock="${stock}">추가</button></td>`;
                tr.querySelector('button').addEventListener('click', function () {
                    addItem(this.dataset);
                });
                return tr;
            }

            // ── 추가 ──────────────────────────────────────────────────────
            function addItem(d) {
                const key = d.pid + '_' + (d.sid || '0');
                if (selectedMap[key]) {
                    selectedMap[key].qty += 1;
                } else {
                    selectedMap[key] = {
                        product_id: d.pid, sku_id: d.sid || null,
                        product_name: d.name, sku_option_label: d.label || null,
                        product_price: parseInt(d.price), qty: 1, stock: parseInt(d.stock),
                    };
                }
                renderSelected();
            }

            // ── 렌더 ──────────────────────────────────────────────────────
            function renderSelected() {
                const tbody = document.getElementById('exchSelectedBody');
                tbody.innerHTML = '';
                let total = 0;

                Object.entries(selectedMap).forEach(([key, item]) => {
                    const subtotal = item.product_price * item.qty;
                    total += subtotal;
                    const tr = document.createElement('tr');
                    const nameHtml = item.sku_option_label
                        ? esc(item.product_name) + ' <span class="text-muted">/ ' + esc(item.sku_option_label) + '</span>'
                        : esc(item.product_name);
                    tr.innerHTML = `
                        <td>${nameHtml}</td>
                        <td class="text-end">${num(item.product_price)}</td>
                        <td><input type="number" class="form-control form-control-sm qty-input" value="${item.qty}" min="1" max="${item.stock}" style="width:50px" data-key="${key}"></td>
                        <td class="text-end subtotal-cell">${num(subtotal)}</td>
                        <td><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove" data-key="${key}">✕</button></td>`;
                    tbody.appendChild(tr);
                });

                if (Object.keys(selectedMap).length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-2">대체품을 검색해서 추가하세요</td></tr>';
                }

                tbody.querySelectorAll('.qty-input').forEach(inp => {
                    inp.addEventListener('change', function () {
                        const k = this.dataset.key;
                        if (selectedMap[k]) {
                            selectedMap[k].qty = Math.max(1, Math.min(parseInt(this.value) || 1, selectedMap[k].stock));
                        }
                        renderSelected();
                    });
                });
                tbody.querySelectorAll('.btn-remove').forEach(btn => {
                    btn.addEventListener('click', function () {
                        delete selectedMap[this.dataset.key];
                        renderSelected();
                    });
                });

                // 합계·버튼 상태 갱신
                document.getElementById('exchangeTotalDisplay').textContent = num(total) + '원';
                const match = total === ORIGINAL_TOTAL;
                const badge = document.getElementById('priceMatchBadge');
                badge.textContent  = match ? '금액 일치' : '금액 불일치';
                badge.className    = 'badge ms-1 ' + (match ? 'bg-success' : 'bg-danger');
                document.getElementById('btnExchComplete').disabled = ! match || Object.keys(selectedMap).length === 0;

                // JSON 직렬화
                const arr = Object.values(selectedMap).map(i => ({
                    product_id: i.product_id, sku_id: i.sku_id,
                    product_name: i.product_name, sku_option_label: i.sku_option_label,
                    product_price: i.product_price, qty: i.qty,
                }));
                document.getElementById('exchItemsJson').value = JSON.stringify(arr);
            }

            // ── 제출 확인 ─────────────────────────────────────────────────
            document.getElementById('exchCompleteForm').addEventListener('submit', function (e) {
                const trackingNumber = document.getElementById('exchTrackingNumber')?.value.trim() ?? '';
                if (! trackingNumber) {
                    e.preventDefault();
                    alert('교환품 발송 송장번호를 입력해주세요.');
                    return;
                }

                // hidden 필드 동기화
                document.getElementById('exchTrackingCompanyHidden').value = document.getElementById('exchTrackingCompany')?.value.trim() ?? '';
                document.getElementById('exchTrackingNumberHidden').value  = trackingNumber;

                const retComp = document.getElementById('exchReturnCompanyHidden');
                if (retComp) {
                    retComp.value = document.getElementById('exchReturnCompany')?.value.trim() ?? '';
                    document.getElementById('exchReturnNumberHidden').value  = document.getElementById('exchReturnNumber')?.value.trim()  ?? '';
                    document.getElementById('exchSellerFeeHidden').value     = document.getElementById('exchSellerFee')?.value           ?? '0';
                }

                if (! confirm('대체품 발송 내역을 저장하고 교환 완료 처리하시겠습니까?')) e.preventDefault();
            });

            function num(v) { return parseInt(v).toLocaleString('ko-KR'); }
            function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        })();
        </script>
        <?php endif; ?>

        <!-- 강제 취소 -->
        <?php if ($canCancel): ?>
        <div class="card mb-3 border-danger">
            <div class="card-header fw-semibold bg-danger bg-opacity-10 text-danger">강제 취소</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    결제 완료 상태인 경우 재고가 자동 복구됩니다.<br>
                    PG 취소는 직접 처리하세요.
                </p>
                <form method="post" action="/admin/orders/<?= (int) $order['id'] ?>/cancel"
                      onsubmit="return confirm('주문을 강제 취소하시겠습니까?\n이 작업은 되돌릴 수 없습니다.')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger w-100">강제 취소</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 대체품 발송 내역 (교환 완료 후) -->
        <?php if (! empty($order['exchange_items'])): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-arrow-left-right me-1"></i>대체품 발송 내역
            </div>
            <?php if (! empty($order['exchange_tracking_number']) || ! empty($order['exchange_return_tracking_number']) || ! empty($order['exchange_seller_shipping_fee'])): ?>
            <div class="card-body pb-0 small text-muted">
                <?php if (! empty($order['exchange_tracking_number'])): ?>
                <div class="mb-1">
                    <strong>교환품 발송:</strong>
                    <?= esc($order['exchange_tracking_company'] ?? '') ?>
                    <?= esc($order['exchange_tracking_number']) ?>
                </div>
                <?php endif; ?>
                <?php if (! empty($order['exchange_return_tracking_number'])): ?>
                <div class="mb-1">
                    <strong>수거 송장:</strong>
                    <?= esc($order['exchange_return_tracking_company'] ?? '') ?>
                    <?= esc($order['exchange_return_tracking_number']) ?>
                </div>
                <?php endif; ?>
                <?php if (! empty($order['exchange_seller_shipping_fee'])): ?>
                <div class="mb-1">
                    <strong>회사 부담 배송비:</strong>
                    <?= number_format((int) $order['exchange_seller_shipping_fee']) ?>원
                    <span class="badge bg-warning text-dark ms-1">정산 대상</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light">
                        <tr><th>상품</th><th class="text-end">단가</th><th class="text-end">수량</th><th class="text-end">소계</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['exchange_items'] as $ei): ?>
                        <tr>
                            <td>
                                <?= esc($ei['product_name']) ?>
                                <?php if ($ei['sku_option_label']): ?>
                                    <span class="text-muted">/ <?= esc($ei['sku_option_label']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format($ei['product_price']) ?>원</td>
                            <td class="text-end"><?= (int) $ei['qty'] ?></td>
                            <td class="text-end"><?= number_format($ei['subtotal']) ?>원</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">합계</td>
                            <td class="text-end fw-semibold"><?= number_format(array_sum(array_column($order['exchange_items'], 'subtotal'))) ?>원</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- 내부 메모 -->
        <div class="card mb-3" id="memoCard">
            <div class="card-header fw-semibold bg-white d-flex align-items-center justify-content-between">
                <span><i class="bi bi-sticky me-1 text-warning"></i>내부 메모</span>
                <span class="badge bg-secondary" id="memoCount"><?= count($memos ?? []) ?></span>
            </div>
            <div class="card-body p-0">
                <ul class="list-unstyled mb-0" id="memoList" style="font-size:.82rem;max-height:280px;overflow-y:auto">
                    <?php foreach ($memos ?? [] as $memo): ?>
                    <li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-start gap-2"
                        data-memo-id="<?= (int) $memo['id'] ?>">
                        <div class="flex-grow-1">
                            <div style="white-space:pre-wrap"><?= esc($memo['content']) ?></div>
                            <div class="text-muted mt-1" style="font-size:.72rem">
                                <?= esc($memo['admin_name'] ?? '관리자') ?> ·
                                <?= date('Y년 n월 j일 G시 i분', strtotime($memo['created_at'])) ?>
                            </div>
                        </div>
                        <button class="btn-memo-delete btn btn-sm btn-link text-danger p-0 flex-shrink-0"
                                data-id="<?= (int) $memo['id'] ?>" title="삭제">
                            <i class="bi bi-trash"></i>
                        </button>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($memos)): ?>
                    <li class="px-3 py-3 text-muted text-center small" id="memoEmpty">메모가 없습니다.</li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="card-footer bg-white p-2">
                <div class="d-flex gap-2">
                    <textarea id="memoContent" class="form-control form-control-sm" rows="2"
                              placeholder="관리자 전용 메모 (고객 비노출)" style="resize:none"></textarea>
                    <button id="btnMemoAdd" class="btn btn-sm btn-warning flex-shrink-0" style="min-width:52px">
                        저장
                    </button>
                </div>
            </div>
        </div>

        <!-- 상태 변경 이력 -->
        <?php if (!empty($order['statusLogs'])): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold bg-white">
                <i class="bi bi-clock-history me-1"></i>상태 변경 이력
            </div>
            <div class="card-body p-0">
                <?php
                $statusLabelsAll = [
                    'pending'           => '결제 대기',
                    'awaiting_payment'  => '입금 대기',
                    'paid'              => '결제 완료',
                    'preparing'         => '상품 준비 중',
                    'shipped'           => '배송 중',
                    'delivered'         => '배송 완료',
                    'cancelled'         => '취소',
                    'expired'           => '만료',
                    'refund_requested'  => '환불 요청',
                    'refunded'          => '환불 완료',
                    'return_requested'   => '반품 요청',
                    'return_approved'    => '반품 승인',
                    'exchange_requested' => '교환 요청',
                    'exchange_approved'  => '교환 승인',
                    'exchange_completed' => '교환 완료',
                ];
                $actorBadge = [
                    'admin'  => ['bg-primary',   '관리자'],
                    'member' => ['bg-secondary',  '회원'],
                    'system' => ['bg-dark',       '시스템'],
                ];
                ?>
                <ul class="list-unstyled mb-0" style="font-size:.8rem">
                    <?php foreach ($order['statusLogs'] as $idx => $log):
                        [$badgeCls, $badgeLabel] = $actorBadge[$log['actor_type']] ?? ['bg-secondary', $log['actor_type']];
                        $fromLabel  = $statusLabelsAll[$log['from_status']] ?? $log['from_status'];
                        $toLabel    = $statusLabelsAll[$log['to_status']]   ?? $log['to_status'];
                        $sameStatus = $log['from_status'] === $log['to_status'];
                        $displayName = match($log['actor_type']) {
                            'admin'  => ($log['actor_name'] && $log['actor_name'] !== 'system')
                                            ? '관리자 · ' . $log['actor_name'] : '관리자',
                            'member' => $log['actor_name'] ?: '회원',
                            default  => '시스템',
                        };
                    ?>
                    <li class="px-3 py-2 <?= $idx < count($order['statusLogs']) - 1 ? 'border-bottom' : '' ?>">
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-shrink-0 pt-1" style="min-width:0">
                                <span class="badge <?= $badgeCls ?> rounded-pill" style="font-size:.65rem">
                                    <?= esc($displayName) ?>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <?php if ($sameStatus): ?>
                                <div class="fw-semibold text-dark"><?= esc($toLabel) ?></div>
                                <?php else: ?>
                                <div class="fw-semibold text-dark">
                                    <span class="text-muted"><?= esc($fromLabel) ?></span>
                                    <i class="bi bi-arrow-right mx-1" style="font-size:.7rem"></i>
                                    <?= esc($toLabel) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($log['note']): ?>
                                <div class="text-muted"><?= esc($log['note']) ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:.72rem">
                                    <?= date('Y년 n월 j일 G시 i분', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var orderId  = <?= (int) $order['id'] ?>;
    var csrfName = '<?= csrf_token() ?>';
    var csrfVal  = '<?= csrf_hash() ?>';

    function post(url, data) {
        data[csrfName] = csrfVal;
        var body = Object.entries(data).map(function (kv) {
            return encodeURIComponent(kv[0]) + '=' + encodeURIComponent(kv[1]);
        }).join('&');
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    function updateCount(delta) {
        var badge = document.getElementById('memoCount');
        badge.textContent = parseInt(badge.textContent, 10) + delta;
    }

    // 메모 추가
    document.getElementById('btnMemoAdd').addEventListener('click', function () {
        var content = document.getElementById('memoContent').value.trim();
        if (! content) return;

        post('/admin/orders/' + orderId + '/memos', { content: content })
        .then(function (data) {
            if (! data.success) { alert(data.message || '저장 실패'); return; }

            var memo = data.memo;
            var empty = document.getElementById('memoEmpty');
            if (empty) empty.remove();

            var li = document.createElement('li');
            li.className = 'px-3 py-2 border-bottom d-flex justify-content-between align-items-start gap-2';
            li.dataset.memoId = memo.id;
            li.innerHTML =
                '<div class="flex-grow-1">'
                + '<div style="white-space:pre-wrap">' + memo.content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>'
                + '<div class="text-muted mt-1" style="font-size:.72rem">'
                + (memo.admin_name || '관리자') + ' · 방금 전'
                + '</div></div>'
                + '<button class="btn-memo-delete btn btn-sm btn-link text-danger p-0 flex-shrink-0" data-id="' + memo.id + '" title="삭제"><i class="bi bi-trash"></i></button>';

            document.getElementById('memoList').appendChild(li);
            document.getElementById('memoContent').value = '';
            updateCount(1);
            bindDelete(li.querySelector('.btn-memo-delete'));
        });
    });

    // Ctrl+Enter로도 저장
    document.getElementById('memoContent').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            document.getElementById('btnMemoAdd').click();
        }
    });

    // 메모 삭제
    function bindDelete(btn) {
        btn.addEventListener('click', function () {
            if (! confirm('메모를 삭제하시겠습니까?')) return;
            var memoId = btn.dataset.id;
            post('/admin/orders/' + orderId + '/memos/' + memoId + '/delete', {})
            .then(function (data) {
                if (! data.success) { alert(data.message || '삭제 실패'); return; }
                var li = btn.closest('li');
                li.remove();
                updateCount(-1);
                if (document.querySelectorAll('#memoList li').length === 0) {
                    var empty = document.createElement('li');
                    empty.id = 'memoEmpty';
                    empty.className = 'px-3 py-3 text-muted text-center small';
                    empty.textContent = '메모가 없습니다.';
                    document.getElementById('memoList').appendChild(empty);
                }
            });
        });
    }

    document.querySelectorAll('.btn-memo-delete').forEach(bindDelete);
}());
</script>
<?= $this->endSection() ?>
