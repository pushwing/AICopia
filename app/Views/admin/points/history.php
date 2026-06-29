<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '포인트 이력' ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/admin/points" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i>
    </a>
    <div>
        <h4 class="fw-bold mb-0">포인트 이력</h4>
        <div class="text-muted small"><?= esc($user['nickname']) ?> (<?= esc($user['email']) ?>)</div>
    </div>
    <div class="ms-auto">
        <span class="fw-bold fs-5"><?= number_format($user['point_balance']) ?>P</span>
        <span class="text-muted small ms-1">현재 잔액</span>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card overflow-hidden">
            <div class="card-header fw-semibold bg-white d-flex justify-content-between">
                <span>포인트 이력</span>
                <span class="text-muted small">총 <?= number_format($total) ?>건</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>일시</th>
                            <th>구분</th>
                            <th class="text-end">금액</th>
                            <th>주문</th>
                            <th>메모</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">이력이 없습니다.</td></tr>
                        <?php else:
                            $typeMap   = ['use'=>'사용','earn'=>'적립','refund'=>'환불','cancel'=>'취소','admin'=>'관리자'];
                            $typeColor = ['use'=>'warning','earn'=>'success','refund'=>'info','cancel'=>'secondary','admin'=>'dark'];
                            foreach ($items as $log): ?>
                        <tr>
                            <td class="small text-muted"><?= date('Y년 n월 j일 G시 i분', strtotime($log['created_at'])) ?></td>
                            <td>
                                <span class="badge bg-<?= $typeColor[$log['type']] ?? 'secondary' ?> bg-opacity-75">
                                    <?= $typeMap[$log['type']] ?? $log['type'] ?>
                                </span>
                            </td>
                            <td class="text-end fw-semibold <?= $log['amount'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= ($log['amount'] > 0 ? '+' : '') . number_format($log['amount']) ?>P
                            </td>
                            <td class="small text-muted">
                                <?= $log['order_id']
                                    ? '<a href="/admin/orders/' . (int)$log['order_id'] . '" class="text-decoration-none">#' . (int)$log['order_id'] . '</a>'
                                    : '—' ?>
                            </td>
                            <td class="small text-muted"><?= esc($log['note'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total > $perPage): ?>
            <div class="card-footer bg-white">
                <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                </ul></nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header fw-semibold bg-white">포인트 수동 조정</div>
            <div class="card-body">
                <form method="post" action="/admin/points/adjust">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">조정 금액 <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control"
                               placeholder="양수=지급, 음수=차감" required>
                        <div class="form-text">예) 지급: 1000 / 차감: -500</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">사유</label>
                        <input type="text" name="note" class="form-control"
                               placeholder="이벤트 지급, 오류 수정 등" maxlength="255">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">적용</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
