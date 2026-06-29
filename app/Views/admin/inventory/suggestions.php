<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '발주 제안' ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="/admin/inventory" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <h5 class="fw-bold mb-0">발주 제안</h5>
    </div>
    <form method="get" action="/admin/inventory/suggestions" class="d-flex align-items-end gap-2">
        <div>
            <label class="form-label small mb-0">판매 추세 기간(일)</label>
            <input type="number" name="window" min="7" value="<?= (int) $windowDays ?>" class="form-control form-control-sm" style="width:110px">
        </div>
        <div>
            <label class="form-label small mb-0">목표 커버(일)</label>
            <input type="number" name="cover" min="7" value="<?= (int) $coverDays ?>" class="form-control form-control-sm" style="width:110px">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-secondary">적용</button>
    </form>
</div>

<p class="text-muted small">
    최근 <strong><?= (int) $windowDays ?>일</strong> 판매 속도 기준, <strong><?= (int) $coverDays ?>일</strong> 분량 재고를 목표로 권장 발주 수량을 계산합니다.
    소진 임박 순으로 정렬됩니다.
</p>

<?php if (empty($suggestions)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-check2-circle display-6 d-block mb-2"></i>
        발주가 필요한 상품이 없습니다. (해당 기간 판매 이력 기준)
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
            <tr>
                <th>상품</th>
                <th class="text-end">현재고</th>
                <th class="text-end"><?= (int) $windowDays ?>일 판매</th>
                <th class="text-end">일평균</th>
                <th class="text-end">소진 예상</th>
                <th class="text-end">권장 발주</th>
                <th class="text-end">입고</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($suggestions as $s): ?>
            <tr id="sug-row-<?= (int) $s['id'] ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?php if (! empty($s['primary_image'])): ?>
                        <img src="/<?= esc(ltrim($s['primary_image'], '/')) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px">
                        <?php endif; ?>
                        <a href="/shop/<?= esc($s['slug']) ?>" target="_blank" class="text-decoration-none"><?= esc($s['name']) ?></a>
                    </div>
                </td>
                <td class="text-end <?= $s['stock'] === 0 ? 'text-danger fw-bold' : '' ?>" data-stock><?= number_format($s['stock']) ?></td>
                <td class="text-end"><?= number_format($s['sold']) ?></td>
                <td class="text-end"><?= esc((string) $s['daily_velocity']) ?></td>
                <td class="text-end">
                    <?php $dr = (float) $s['days_remaining']; ?>
                    <span class="badge <?= $dr <= 7 ? 'bg-danger' : ($dr <= 14 ? 'bg-warning text-dark' : 'bg-light text-muted border') ?>">
                        <?= $s['stock'] === 0 ? '품절' : esc((string) $dr) . '일' ?>
                    </span>
                </td>
                <td class="text-end fw-bold text-info"><?= number_format($s['suggested_qty']) ?></td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary btn-restock"
                            data-id="<?= (int) $s['id'] ?>" data-qty="<?= (int) $s['suggested_qty'] ?>"
                            data-name="<?= esc($s['name'], 'attr') ?>">
                        <?= number_format($s['suggested_qty']) ?>개 입고
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
(function () {
    var csrf = { name: '<?= csrf_token() ?>', hash: '<?= csrf_hash() ?>' };

    document.querySelectorAll('.btn-restock').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var qty = parseInt(btn.dataset.qty, 10);
            if (! confirm('"' + btn.dataset.name + '" ' + qty + '개를 입고 처리할까요?')) return;

            btn.disabled = true;
            var fd = new FormData();
            fd.append(csrf.name, csrf.hash);
            fd.append('type', 'in');
            fd.append('quantity', qty);
            fd.append('note', '발주 제안 입고');

            fetch('/admin/inventory/' + btn.dataset.id + '/adjust', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (! d.success) { alert(d.error || '입고 처리에 실패했습니다.'); btn.disabled = false; return; }
                    var row = document.getElementById('sug-row-' + btn.dataset.id);
                    row.querySelector('[data-stock]').textContent = Number(d.stock_after).toLocaleString();
                    btn.outerHTML = '<span class="badge bg-success">입고 완료</span>';
                })
                .catch(function () { alert('요청 중 오류가 발생했습니다.'); btn.disabled = false; });
        });
    });
}());
</script>

<?= $this->endSection() ?>
