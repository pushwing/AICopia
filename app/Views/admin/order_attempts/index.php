<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '주문 시도 로그' ?>

<?= $this->section('content') ?>

<?php
$statusBadge = [
    'pending'   => 'secondary',
    'converted' => 'success',
    'failed'    => 'danger',
    'expired'   => 'dark',
];
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold mb-0">주문 시도 로그</h4>
        <div class="text-muted small">결제 확정 전 이탈한 주문 시도 + 레거시 미확정 주문(주문 목록에는 노출되지 않음)</div>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="card-header bg-white">
        <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm" style="max-width:220px"
                   placeholder="주문번호 / 이메일 / 닉네임" value="<?= esc($keyword) ?>">
            <select name="status" class="form-select form-select-sm" style="max-width:150px">
                <option value="">전체 상태</option>
                <?php foreach ($attemptStatusLabels as $val => $label): ?>
                <option value="<?= esc($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($from) ?>">
            <span class="text-muted small">~</span>
            <input type="date" name="to" class="form-control form-control-sm" style="max-width:150px" value="<?= esc($to) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($keyword || $status || $from || $to): ?>
            <a href="/admin/order-attempts" class="btn btn-sm btn-outline-secondary">초기화</a>
            <?php endif; ?>
            <span class="text-muted small ms-auto">총 <?= number_format($total) ?>건</span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>구분</th>
                    <th>주문번호</th>
                    <th>회원</th>
                    <th>상태</th>
                    <th>결제수단</th>
                    <th class="text-end">실결제액</th>
                    <th>실패 사유</th>
                    <th>생성 시각</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">주문 시도가 없습니다.</td></tr>
                <?php else: foreach ($items as $row):
                    $isAttempt = $row['source'] === 'attempt';
                    $label     = $isAttempt
                        ? ($attemptStatusLabels[$row['status']] ?? $row['status'])
                        : ($legacyStatusLabels[$row['status']] ?? $row['status']);
                ?>
                <tr>
                    <td>
                        <?php if ($isAttempt): ?>
                        <span class="badge bg-primary bg-opacity-75">주문 시도</span>
                        <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-75">레거시 주문</span>
                        <?php endif; ?>
                    </td>
                    <td class="small fw-semibold"><?= esc($row['order_number']) ?></td>
                    <td class="small">
                        <?= esc($row['user_nickname'] ?? '-') ?>
                        <?php if (! empty($row['user_email'])): ?>
                        <div class="text-muted" style="font-size:.75rem"><?= esc($row['user_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $statusBadge[$row['status']] ?? 'secondary' ?> bg-opacity-75"><?= esc($label) ?></span>
                    </td>
                    <td class="small"><?= esc($pgLabels[$row['pg_provider']] ?? ($row['pg_provider'] ?? '-')) ?></td>
                    <td class="text-end small"><?= number_format((int) $row['payable_amount']) ?>원</td>
                    <td class="small text-muted"><?= esc($row['fail_reason'] ?? '-') ?></td>
                    <td class="small text-muted"><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                    <td class="text-end">
                        <a href="/admin/order-attempts/<?= $isAttempt ? 'attempt' : 'legacy' ?>/<?= (int) $row['id'] ?>"
                           class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:.15rem .45rem">상세</a>
                        <?php if ($isAttempt && $row['status'] === 'converted' && ! empty($row['order_id'])): ?>
                        <a href="/admin/orders/<?= (int) $row['order_id'] ?>"
                           class="btn btn-xs btn-outline-primary" style="font-size:.72rem;padding:.15rem .45rem">주문 이동</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($keyword) ?>&status=<?= urlencode($status) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
