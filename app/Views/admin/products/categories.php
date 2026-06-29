<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '카테고리 관리' ?>

<?= $this->section('content') ?>

<div class="row g-4">
    <!-- 카테고리 목록 -->
    <div class="col-lg-8">
        <div class="card overflow-hidden">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>카테고리 목록</span>
                <form method="post" action="/admin/products/categories/publish" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-cloud-upload me-1"></i>쇼핑몰에 적용
                    </button>
                </form>
            </div>
            <div class="alert alert-warning alert-sm mb-0 rounded-0 border-0 py-2 px-3 small">
                <i class="bi bi-info-circle me-1"></i>
                추가·수정·삭제 후 <strong>쇼핑몰에 적용</strong> 버튼을 눌러야 쇼핑몰에 반영됩니다.
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:80px">순서</th>
                            <th>이름</th>
                            <th>구분</th>
                            <th>상태</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tree)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">등록된 카테고리가 없습니다.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($tree as $parent): ?>
                        <tr class="table-light">
                            <td class="text-center">
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-cat-move"
                                        data-id="<?= $parent['id'] ?>" data-dir="up">▲</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-cat-move"
                                        data-id="<?= $parent['id'] ?>" data-dir="down">▼</button>
                            </td>
                            <td class="fw-semibold"><?= esc($parent['name']) ?></td>
                            <td><span class="badge bg-secondary">대분류</span></td>
                            <td><?= $parent['is_active'] ? '<span class="badge bg-success">활성</span>' : '<span class="badge bg-secondary">비활성</span>' ?></td>
                            <td class="text-end" style="white-space:nowrap">
                                <button class="btn btn-sm btn-outline-secondary edit-btn"
                                        data-id="<?= $parent['id'] ?>"
                                        data-parent-id=""
                                        data-name="<?= esc($parent['name']) ?>"
                                        data-sort="<?= (int) $parent['sort_order'] ?>"
                                        data-active="<?= (int) $parent['is_active'] ?>">수정</button>
                                <form method="post" action="/admin/products/categories/<?= $parent['id'] ?>/delete" class="d-inline"
                                      onsubmit="return confirm('삭제하시겠습니까?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">삭제</button>
                                </form>
                            </td>
                        </tr>
                        <?php foreach ($parent['children'] as $child): ?>
                        <tr>
                            <td class="text-center">
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-cat-move"
                                        data-id="<?= $child['id'] ?>" data-dir="up">▲</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-cat-move"
                                        data-id="<?= $child['id'] ?>" data-dir="down">▼</button>
                            </td>
                            <td class="ps-4">— <?= esc($child['name']) ?></td>
                            <td><span class="badge bg-light text-secondary border">소분류</span></td>
                            <td><?= $child['is_active'] ? '<span class="badge bg-success">활성</span>' : '<span class="badge bg-secondary">비활성</span>' ?></td>
                            <td class="text-end" style="white-space:nowrap">
                                <button class="btn btn-sm btn-outline-secondary edit-btn"
                                        data-id="<?= $child['id'] ?>"
                                        data-parent-id="<?= $parent['id'] ?>"
                                        data-name="<?= esc($child['name']) ?>"
                                        data-sort="<?= (int) $child['sort_order'] ?>"
                                        data-active="<?= (int) $child['is_active'] ?>">수정</button>
                                <form method="post" action="/admin/products/categories/<?= $child['id'] ?>/delete" class="d-inline"
                                      onsubmit="return confirm('삭제하시겠습니까?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">삭제</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 카테고리 추가 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">카테고리 추가</div>
            <div class="card-body">
                <form method="post" action="/admin/products/categories">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">상위 카테고리</label>
                        <select name="parent_id" class="form-select">
                            <option value="">없음 (대분류)</option>
                            <?php foreach ($tree as $parent): ?>
                            <option value="<?= $parent['id'] ?>"><?= esc($parent['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">이름 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= esc(old('name')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">정렬 순서</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked>
                            <label class="form-check-label" for="isActive">활성</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">추가</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 수정 모달 -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="editForm">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">카테고리 수정</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="parent_id" id="editParentId">
                    <div class="mb-3">
                        <label class="form-label">이름 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">정렬 순서</label>
                        <input type="number" name="sort_order" id="editSortOrder" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" value="1">
                            <label class="form-check-label" for="editIsActive">활성</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                    <button type="submit" class="btn btn-primary">저장</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// ▲/▼ 순서 이동
const csrfName  = '<?= csrf_token() ?>';
let   csrfToken = '<?= csrf_hash() ?>';

document.querySelectorAll('.btn-cat-move').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const id  = this.dataset.id;
        const dir = this.dataset.dir;
        fetch('/admin/products/categories/' + id + '/move', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: csrfName + '=' + encodeURIComponent(csrfToken) + '&direction=' + dir,
        })
        .then(r => r.json())
        .then(data => { if (data.ok) location.reload(); });
    });
});

document.querySelectorAll('.edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const id       = this.dataset.id;
        const parentId = this.dataset.parentId;
        const name     = this.dataset.name;
        const sort     = this.dataset.sort;
        const active   = this.dataset.active;

        document.getElementById('editForm').action     = `/admin/products/categories/${id}/edit`;
        document.getElementById('editParentId').value  = parentId;
        document.getElementById('editName').value      = name;
        document.getElementById('editSortOrder').value = sort;
        document.getElementById('editIsActive').checked = active === '1';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    });
});
</script>
<?= $this->endSection() ?>
