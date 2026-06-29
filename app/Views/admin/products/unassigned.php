<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '카테고리 일괄 배정' ?>

<?= $this->section('content') ?>

<?php if (session()->getFlashdata('success')): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= esc(session()->getFlashdata('success')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= esc(session()->getFlashdata('error')) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="post" action="/admin/products/assign-category" id="assignForm">
    <?= csrf_field() ?>
    <input type="hidden" name="_only_unassigned" value="<?= $onlyUnassigned ? '1' : '0' ?>">
    <input type="hidden" name="_keyword" value="<?= esc($keyword) ?>">

<div class="row g-4">
    <!-- 상품 목록 -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>
                        전체 상품
                        <span class="badge bg-secondary ms-1"><?= number_format($total) ?>개</span>
                        <?php if ($unassignedCount > 0): ?>
                        <span class="badge bg-warning text-dark ms-1">미분류 <?= number_format($unassignedCount) ?>개</span>
                        <?php endif; ?>
                    </span>
                    <a href="/admin/products" class="btn btn-sm btn-outline-secondary">← 상품 목록</a>
                </div>

                <!-- 검색/필터 -->
                <div class="d-flex gap-2 mt-2 flex-wrap align-items-center" id="filterBar">
                    <input type="text" id="keywordInput" class="form-control form-control-sm" style="width:200px"
                           placeholder="상품명 검색" value="<?= esc($keyword) ?>">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="toggleUnassigned"
                               <?= $onlyUnassigned ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="toggleUnassigned">미분류만 보기</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnFilter">검색</button>
                    <?php if ($keyword !== '' || $onlyUnassigned): ?>
                    <a href="/admin/products/unassigned" class="btn btn-sm btn-outline-secondary">초기화</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($items)): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                조건에 맞는 상품이 없습니다.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">
                                <input type="checkbox" id="checkAll" class="form-check-input">
                            </th>
                            <th style="width:60px"></th>
                            <th>상품명</th>
                            <th>현재 카테고리</th>
                            <th>가격</th>
                            <th>재고</th>
                            <th>상태</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $p): ?>
                        <?php $hasCategory = !empty($p['category_names']); ?>
                        <tr class="<?= $hasCategory ? '' : 'table-warning' ?>">
                            <td>
                                <input type="checkbox" name="product_ids[]"
                                       value="<?= $p['id'] ?>" class="form-check-input chk-product">
                            </td>
                            <td>
                                <?php if (!empty($p['primary_image'])): ?>
                                <img src="<?= base_url($p['primary_image']) ?>" width="40" height="40"
                                     class="rounded" style="object-fit:cover">
                                <?php else: ?>
                                <div class="bg-light rounded d-flex align-items-center justify-content-center"
                                     style="width:40px;height:40px">
                                    <i class="bi bi-image text-muted"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/products/<?= $p['id'] ?>/edit" target="_blank"
                                   class="text-decoration-none text-dark">
                                    <?= esc($p['name']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($hasCategory): ?>
                                <span class="text-muted small"><?= esc($p['category_names']) ?></span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">미분류</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= number_format((int)$p['price']) ?>원</td>
                            <td><?= number_format((int)$p['stock']) ?></td>
                            <td>
                                <?php
                                $badgeMap = ['on_sale'=>'success','sold_out'=>'secondary','hidden'=>'light text-dark border'];
                                $badge    = $badgeMap[$p['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $badge ?>"><?= $statuses[$p['status']] ?? $p['status'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php
                        $qs = http_build_query(array_filter([
                            'keyword'        => $keyword,
                            'only_unassigned'=> $onlyUnassigned ? '1' : '',
                        ]));
                        $qs = $qs ? '&' . $qs : '';
                        ?>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?><?= $qs ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 카테고리 선택 + 적용 -->
    <div class="col-lg-4">
        <div class="card sticky-top" style="top:80px">
            <div class="card-header fw-semibold">배정할 카테고리 선택</div>
            <div class="card-body">
                <div class="alert alert-info small py-2 mb-3">
                    기존 카테고리를 유지하면서 선택한 카테고리를 <strong>추가</strong>합니다.
                </div>

                <div class="border rounded p-2 mb-3" style="max-height:300px;overflow-y:auto">
                    <?php if (empty($tree)): ?>
                    <span class="text-muted small">등록된 카테고리가 없습니다.</span>
                    <?php endif; ?>
                    <?php foreach ($tree as $parent): ?>
                    <div class="mb-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                   name="category_ids[]"
                                   value="<?= $parent['id'] ?>"
                                   id="acat_<?= $parent['id'] ?>">
                            <label class="form-check-label fw-semibold" for="acat_<?= $parent['id'] ?>">
                                <?= esc($parent['name']) ?>
                            </label>
                        </div>
                        <?php foreach ($parent['children'] as $child): ?>
                        <div class="form-check ms-3">
                            <input class="form-check-input" type="checkbox"
                                   name="category_ids[]"
                                   value="<?= $child['id'] ?>"
                                   id="acat_<?= $child['id'] ?>">
                            <label class="form-check-label text-muted" for="acat_<?= $child['id'] ?>">
                                — <?= esc($child['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-muted small mb-3">
                    선택된 상품 <strong id="selectedCount">0</strong>개
                </div>

                <button type="submit" class="btn btn-primary w-100" id="btnApply" disabled>
                    <i class="bi bi-tag me-1"></i>선택 상품에 카테고리 적용
                </button>
            </div>
        </div>
    </div>
</div>

</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const checkAll  = document.getElementById('checkAll');
const chkList   = document.querySelectorAll('.chk-product');
const countEl   = document.getElementById('selectedCount');
const btnApply  = document.getElementById('btnApply');

function updateState() {
    const n = document.querySelectorAll('.chk-product:checked').length;
    countEl.textContent        = n;
    btnApply.disabled          = n === 0;
    checkAll.indeterminate     = n > 0 && n < chkList.length;
    checkAll.checked           = chkList.length > 0 && n === chkList.length;
}

checkAll.addEventListener('change', function () {
    chkList.forEach(c => c.checked = this.checked);
    updateState();
});
chkList.forEach(c => c.addEventListener('change', updateState));

document.getElementById('assignForm').addEventListener('submit', function (e) {
    const cats = document.querySelectorAll('input[name="category_ids[]"]:checked').length;
    if (cats === 0) {
        e.preventDefault();
        alert('배정할 카테고리를 하나 이상 선택해주세요.');
    }
});

// 검색/필터
document.getElementById('btnFilter').addEventListener('click', applyFilter);
document.getElementById('keywordInput').addEventListener('keydown', e => { if (e.key === 'Enter') applyFilter(); });

function applyFilter() {
    const kw  = document.getElementById('keywordInput').value.trim();
    const only = document.getElementById('toggleUnassigned').checked ? '1' : '';
    const params = new URLSearchParams();
    if (kw)   params.set('keyword', kw);
    if (only) params.set('only_unassigned', '1');
    location.href = '/admin/products/unassigned' + (params.toString() ? '?' + params.toString() : '');
}
</script>
<?= $this->endSection() ?>
