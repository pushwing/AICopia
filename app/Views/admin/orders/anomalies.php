<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '이상 주문 탐지' ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="/admin/orders" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <h5 class="fw-bold mb-0">이상 주문 탐지</h5>
        <span class="badge bg-danger"><?= count($flagged) ?>건</span>
    </div>
    <form method="get" action="/admin/orders/anomalies" class="d-flex align-items-end gap-2">
        <div>
            <label class="form-label small mb-0">최근 기간(일)</label>
            <input type="number" name="days" min="1" value="<?= (int) $days ?>" class="form-control form-control-sm" style="width:110px">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-secondary">적용</button>
    </form>
</div>

<p class="text-muted small">
    최근 <strong><?= (int) $days ?>일</strong> 주문 중 휴리스틱 규칙(고액 · 단시간 다건 · 동일 연락처 다계정)에 걸린 주문입니다.
    의심 신호일 뿐이며 실제 부정 여부는 확인이 필요합니다.
</p>

<?php if (empty($flagged)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-shield-check display-6 d-block mb-2 text-success"></i>
        탐지된 이상 주문이 없습니다.
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
            <tr>
                <th>위험도</th>
                <th>주문번호</th>
                <th>수취인</th>
                <th>연락처</th>
                <th class="text-end">결제금액</th>
                <th>상태</th>
                <th>주문일시</th>
                <th>의심 사유</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($flagged as $f): ?>
            <tr>
                <td>
                    <span class="badge <?= $f['risk'] >= 2 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                        <?php for ($i = 0; $i < $f['risk']; $i++): ?><i class="bi bi-exclamation-triangle-fill"></i><?php endfor; ?>
                    </span>
                </td>
                <td class="fw-semibold"><?= esc($f['order_number']) ?></td>
                <td><?= esc($f['receiver_name']) ?></td>
                <td><?= esc($f['receiver_phone']) ?></td>
                <td class="text-end"><?= number_format($f['amount']) ?>원</td>
                <td><span class="badge bg-light text-dark border"><?= esc($statusLabels[$f['status']] ?? $f['status']) ?></span></td>
                <td class="text-muted"><?= esc($f['created_at']) ?></td>
                <td>
                    <?php foreach ($f['reasons'] as $reason): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle mb-1"><?= esc($reason) ?></span>
                    <?php endforeach; ?>
                </td>
                <td class="text-end">
                    <a href="/admin/orders/<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-secondary">상세</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?= $this->endSection() ?>
