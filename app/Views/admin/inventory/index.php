<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '재고 관리' ?>

<?= $this->section('content') ?>

<!-- 요약 카드 -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div class="fs-2 fw-bold"><?= number_format($summary['total']) ?></div>
            <div class="text-muted small">전체 상품</div>
        </div>
    </div>
    <div class="col-sm-4">
        <a href="?filter=sold_out<?= $keyword ? '&keyword='.urlencode($keyword) : '' ?>" class="text-decoration-none">
            <div class="card text-center py-3 <?= $filter === 'sold_out' ? 'border-danger' : '' ?>">
                <div class="fs-2 fw-bold text-danger"><?= number_format($summary['sold_out']) ?></div>
                <div class="text-muted small">품절 상품</div>
            </div>
        </a>
    </div>
    <div class="col-sm-4">
        <a href="?filter=low<?= $keyword ? '&keyword='.urlencode($keyword) : '' ?>" class="text-decoration-none">
            <div class="card text-center py-3 <?= $filter === 'low' ? 'border-warning' : '' ?>">
                <div class="fs-2 fw-bold text-warning"><?= number_format($summary['low']) ?></div>
                <div class="text-muted small">재고 부족 (≤10)</div>
            </div>
        </a>
    </div>
</div>

<!-- 검색/필터 -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <form method="get" action="/admin/inventory" class="d-flex gap-2 flex-wrap">
        <input type="hidden" name="filter" value="<?= esc($filter) ?>">
        <input type="text" name="keyword" class="form-control form-control-sm" style="width:180px"
               placeholder="상품명 검색" value="<?= esc($keyword) ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">검색</button>
        <?php if ($keyword || $filter): ?>
        <a href="/admin/inventory" class="btn btn-sm btn-outline-secondary">초기화</a>
        <?php endif; ?>
    </form>
    <div class="d-flex gap-2">
        <a href="/admin/inventory<?= $keyword ? '?keyword='.urlencode($keyword) : '' ?>"
           class="btn btn-sm <?= ! $filter ? 'btn-secondary' : 'btn-outline-secondary' ?>">전체</a>
        <a href="/admin/inventory?filter=sold_out<?= $keyword ? '&keyword='.urlencode($keyword) : '' ?>"
           class="btn btn-sm <?= $filter === 'sold_out' ? 'btn-danger' : 'btn-outline-danger' ?>">품절</a>
        <a href="/admin/inventory?filter=low<?= $keyword ? '&keyword='.urlencode($keyword) : '' ?>"
           class="btn btn-sm <?= $filter === 'low' ? 'btn-warning' : 'btn-outline-warning' ?>">부족</a>
        <a href="/admin/inventory/suggestions" class="btn btn-sm btn-outline-info">
            <i class="bi bi-graph-up-arrow me-1"></i>발주 제안
        </a>
    </div>
</div>

<!-- 테이블 -->
<div class="card overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:56px">이미지</th>
                    <th>상품명</th>
                    <th>카테고리</th>
                    <th class="text-end" style="width:100px">현재 재고</th>
                    <th style="width:90px">상태</th>
                    <th class="text-end" style="width:130px"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">조건에 맞는 상품이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $p): ?>
                <tr id="row-<?= $p['id'] ?>">
                    <td>
                        <?php if (! empty($p['primary_image'])): ?>
                        <img src="<?= esc($p['primary_image']) ?>" alt=""
                             style="width:44px;height:44px;object-fit:cover;border-radius:4px">
                        <?php else: ?>
                        <div style="width:44px;height:44px;background:#f1f3f5;border-radius:4px;display:flex;align-items:center;justify-content:center">
                            <i class="bi bi-image text-muted"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= esc($p['name']) ?></div>
                        <div class="text-muted small"><?= number_format($p['price']) ?>원</div>
                    </td>
                    <td class="small text-muted"><?= esc($p['category_name'] ?? '—') ?></td>
                    <td class="text-end">
                        <?php
                            $stockClass = 'fw-semibold';
                            if ($p['stock'] == 0) $stockClass .= ' text-danger';
                            elseif ($p['stock'] <= 10) $stockClass .= ' text-warning';
                        ?>
                        <span class="<?= $stockClass ?>" id="stock-<?= $p['id'] ?>">
                            <?= number_format($p['stock']) ?>
                        </span>
                    </td>
                    <td>
                        <?php
                            $st = $p['status'];
                            $badgeColor = ['on_sale' => 'success', 'sold_out' => 'danger', 'hidden' => 'secondary'][$st] ?? 'secondary';
                            $statusLabel = ['on_sale' => '판매중', 'sold_out' => '품절', 'hidden' => '숨김'][$st] ?? $st;
                        ?>
                        <span class="badge bg-<?= $badgeColor ?>" id="status-badge-<?= $p['id'] ?>">
                            <?= $statusLabel ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-adjust"
                                data-id="<?= $p['id'] ?>"
                                data-name="<?= esc($p['name']) ?>"
                                data-stock="<?= $p['stock'] ?>">
                            재고 조정
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-logs ms-1"
                                data-id="<?= $p['id'] ?>">
                            <i class="bi bi-clock-history"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
        <li class="page-item <?= $pg === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $pg ?><?= $keyword ? '&keyword='.urlencode($keyword) : '' ?><?= $filter ? '&filter='.urlencode($filter) : '' ?>"><?= $pg ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<div class="text-muted small mt-2">총 <?= number_format($total) ?>개 상품</div>


<!-- 재고 조정 모달 -->
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">재고 조정</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small" id="modal-product-name"></div>
                <div class="mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small">현재 재고</span>
                        <span class="fw-bold fs-5" id="modal-current-stock"></span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">조정 유형</label>
                    <select class="form-select form-select-sm" id="adj-type">
                        <option value="adjust">직접 설정 (재고를 지정값으로)</option>
                        <option value="in">입고 (+수량 추가)</option>
                        <option value="out">출고 (−수량 차감)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" id="adj-qty-label">재고 수량</label>
                    <input type="number" class="form-control form-control-sm" id="adj-quantity" min="0" value="0">
                    <div class="form-text" id="adj-preview"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">사유 <span class="text-muted fw-normal">(선택)</span></label>
                    <input type="text" class="form-control form-control-sm" id="adj-note" placeholder="예: 창고 실사, 불량 반품 등">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-save-adjust">저장</button>
            </div>
        </div>
    </div>
</div>

<!-- 이력 모달 -->
<div class="modal fade" id="logsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">재고 변동 이력</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="logs-loading" class="text-center py-4 text-muted small">불러오는 중…</div>
                <div id="logs-content" class="d-none">
                    <div class="px-3 py-2 border-bottom d-flex align-items-center gap-3">
                        <span class="fw-semibold" id="logs-product-name"></span>
                        <span class="text-muted small">현재 재고: <strong id="logs-current-stock"></strong></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>일시</th>
                                    <th>유형</th>
                                    <th class="text-end">변동</th>
                                    <th class="text-end">이전</th>
                                    <th class="text-end">이후</th>
                                    <th>사유</th>
                                    <th>담당</th>
                                </tr>
                            </thead>
                            <tbody id="logs-tbody"></tbody>
                        </table>
                    </div>
                    <div id="logs-empty" class="d-none text-center text-muted small py-4">이력이 없습니다.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    let currentProductId = null;
    let currentStock = 0;

    const adjustModal = new bootstrap.Modal(document.getElementById('adjustModal'));
    const logsModal   = new bootstrap.Modal(document.getElementById('logsModal'));

    // 재고 조정 버튼
    document.querySelectorAll('.btn-adjust').forEach(btn => {
        btn.addEventListener('click', function () {
            currentProductId = this.dataset.id;
            currentStock     = parseInt(this.dataset.stock, 10);
            document.getElementById('modal-product-name').textContent = this.dataset.name;
            document.getElementById('modal-current-stock').textContent = currentStock.toLocaleString();
            document.getElementById('adj-quantity').value = currentStock;
            document.getElementById('adj-note').value = '';
            updatePreview();
            adjustModal.show();
        });
    });

    // 유형/수량 변경 시 미리보기
    ['adj-type', 'adj-quantity'].forEach(id => {
        document.getElementById(id).addEventListener('input', updatePreview);
    });

    function updatePreview() {
        const type = document.getElementById('adj-type').value;
        const qty  = parseInt(document.getElementById('adj-quantity').value, 10) || 0;
        let after, label;

        if (type === 'in') {
            after = currentStock + Math.abs(qty);
            label = '재고 수량 (+추가)';
        } else if (type === 'out') {
            after = Math.max(0, currentStock - Math.abs(qty));
            label = '재고 수량 (−차감)';
        } else {
            after = Math.max(0, qty);
            label = '재고 수량 (설정값)';
        }

        document.getElementById('adj-qty-label').textContent = label;
        document.getElementById('adj-preview').textContent = '조정 후: ' + after.toLocaleString() + '개';
    }

    // 저장
    document.getElementById('btn-save-adjust').addEventListener('click', function () {
        const type = document.getElementById('adj-type').value;
        const qty  = parseInt(document.getElementById('adj-quantity').value, 10);
        const note = document.getElementById('adj-note').value;

        if (isNaN(qty) || qty < 0) {
            alert('올바른 수량을 입력해 주세요.'); return;
        }

        this.disabled = true;

        fetch('/admin/inventory/' + currentProductId + '/adjust', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams({
                '<?= csrf_token() ?>': '<?= csrf_hash() ?>',
                type, quantity: qty, note,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const stockEl  = document.getElementById('stock-' + currentProductId);
                const badgeEl  = document.getElementById('status-badge-' + currentProductId);
                const adjustBtn = document.querySelector('#row-' + currentProductId + ' .btn-adjust');

                const newStock = data.stock_after;
                stockEl.textContent = newStock.toLocaleString();
                stockEl.className = 'fw-semibold' + (newStock === 0 ? ' text-danger' : newStock <= 10 ? ' text-warning' : '');

                const statusMap = {on_sale: ['판매중', 'success'], sold_out: ['품절', 'danger'], hidden: ['숨김', 'secondary']};
                const [label, color] = statusMap[data.status] || ['—', 'secondary'];
                badgeEl.textContent = label;
                badgeEl.className = 'badge bg-' + color;

                adjustBtn.dataset.stock = newStock;
                currentStock = newStock;

                adjustModal.hide();
            } else {
                alert(data.error || '저장 실패');
            }
        })
        .catch(() => alert('요청 중 오류가 발생했습니다.'))
        .finally(() => { this.disabled = false; });
    });

    // 이력 모달
    document.querySelectorAll('.btn-logs').forEach(btn => {
        btn.addEventListener('click', function () {
            const pid = this.dataset.id;
            document.getElementById('logs-loading').classList.remove('d-none');
            document.getElementById('logs-content').classList.add('d-none');
            logsModal.show();

            fetch('/admin/inventory/' + pid + '/logs', {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            })
            .then(r => r.json())
            .then(data => {
                if (! data.success) return;

                document.getElementById('logs-product-name').textContent = data.product_name;
                document.getElementById('logs-current-stock').textContent = data.stock.toLocaleString();

                const tbody = document.getElementById('logs-tbody');
                tbody.innerHTML = '';

                if (data.logs.length === 0) {
                    document.getElementById('logs-empty').classList.remove('d-none');
                } else {
                    document.getElementById('logs-empty').classList.add('d-none');
                    data.logs.forEach(log => {
                        const sign = log.quantity >= 0 ? '+' : '';
                        const diff = log.stock_after - log.stock_before;
                        const color = diff > 0 ? 'text-success' : diff < 0 ? 'text-danger' : '';
                        tbody.insertAdjacentHTML('beforeend', `
                            <tr>
                                <td class="small text-muted">${log.created_at.substring(0, 16)}</td>
                                <td class="small">${data.types[log.type] || log.type}</td>
                                <td class="text-end small ${color}">${sign}${parseInt(log.quantity).toLocaleString()}</td>
                                <td class="text-end small">${parseInt(log.stock_before).toLocaleString()}</td>
                                <td class="text-end small fw-semibold">${parseInt(log.stock_after).toLocaleString()}</td>
                                <td class="small text-muted">${log.note || '—'}</td>
                                <td class="small text-muted">${log.admin_name || '시스템'}</td>
                            </tr>
                        `);
                    });
                }

                document.getElementById('logs-loading').classList.add('d-none');
                document.getElementById('logs-content').classList.remove('d-none');
            });
        });
    });
})();
</script>
<?= $this->endSection() ?>
