<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4" style="max-width:720px">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/mypage/orders" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="fw-bold mb-0">포인트 내역</h5>
        <div class="ms-auto">
            <span class="fw-bold fs-5 text-primary"><?= number_format($pointBalance) ?>P</span>
            <span class="text-muted small ms-1">보유 포인트</span>
        </div>
    </div>

    <?php if (empty($items)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-star display-6 d-block mb-2"></i>
        포인트 내역이 없습니다.
    </div>
    <?php else:
        $typeMap   = ['use'=>'사용','earn'=>'적립','refund'=>'환불','cancel'=>'취소','admin'=>'지급/차감'];
        $typeColor = ['use'=>'warning','earn'=>'success','refund'=>'info','cancel'=>'secondary','admin'=>'dark'];
    ?>
    <div class="card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>일시</th>
                        <th>구분</th>
                        <th class="text-end">금액</th>
                        <th>내용</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $log): ?>
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
                            <?= $log['note'] ? esc($log['note']) : ($log['order_id'] ? '주문 #' . (int)$log['order_id'] : '—') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total > $perPage): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
