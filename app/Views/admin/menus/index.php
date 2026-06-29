<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '메뉴 관리' ?>
<?= $this->section('content') ?>

<div class="row g-4">
    <!-- 메뉴 목록 -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>현재 메뉴</strong></div>
            <table class="table table-hover mb-0 small">
                <thead class="table-light">
                    <tr><th>순서</th><th>제목</th><th>URL</th><th>상태</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($menus as $m): ?>
                    <tr data-id="<?= $m['id'] ?>">
                        <td class="text-center" style="width:80px">
                            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-move"
                                    data-id="<?= $m['id'] ?>" data-dir="up" title="위로">▲</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm p-0 px-1 btn-move"
                                    data-id="<?= $m['id'] ?>" data-dir="down" title="아래로">▼</button>
                        </td>
                        <td><?= $m['parent_id'] ? '&nbsp;&nbsp;└ ' : '' ?><?= esc($m['title']) ?></td>
                        <td>
                            <?php if ($m['url'] === '#categories'): ?>
                            <span class="badge bg-info text-dark">카테고리 드롭다운</span>
                            <?php else: ?>
                            <code><?= esc($m['url']) ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?= $m['is_active'] ? '<span class="badge bg-success">활성</span>' : '<span class="badge bg-secondary">비활성</span>' ?></td>
                        <td style="white-space:nowrap">
                            <button class="btn btn-xs btn-outline-secondary btn-sm"
                                    onclick="fillEditForm(<?= htmlspecialchars(json_encode($m)) ?>)">수정</button>
                            <form method="post" action="/admin/menus/<?= $m['id'] ?>/delete" class="d-inline" onsubmit="return confirm('삭제?')">
                                <?= csrf_field() ?>
                                <button class="btn btn-xs btn-outline-danger btn-sm">삭제</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 추가/수정 폼 -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong id="formTitle">메뉴 추가</strong>
                <button type="button" class="btn btn-sm btn-outline-info" id="btnAddCategoryMenu"
                        title="카테고리 드롭다운 메뉴 항목을 빠르게 추가합니다">
                    <i class="bi bi-tag me-1"></i>카테고리 메뉴
                </button>
            </div>
            <div class="card-body">
                <form method="post" id="menuForm" action="/admin/menus">
                    <?= csrf_field() ?>
                    <input type="hidden" id="menuId" name="_menu_id" value="">
                    <div class="mb-2">
                        <label class="form-label small">제목 *</label>
                        <input type="text" name="title" id="mTitle" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">URL *</label>
                        <input type="text" name="url" id="mUrl" class="form-control form-control-sm" required placeholder="/about">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">상위 메뉴 ID (없으면 비워두세요)</label>
                        <input type="number" name="parent_id" id="mParent" class="form-control form-control-sm" placeholder="없음">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col">
                            <label class="form-label small">순서</label>
                            <input type="number" name="sort_order" id="mSort" class="form-control form-control-sm" value="0">
                        </div>
                        <div class="col">
                            <label class="form-label small">링크 타겟</label>
                            <select name="target" id="mTarget" class="form-select form-select-sm">
                                <option value="_self">같은 창</option>
                                <option value="_blank">새 창</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" value="1" id="mActive" class="form-check-input" checked>
                        <label for="mActive" class="form-check-label small">활성화</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">저장</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetForm()">초기화</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
function fillEditForm(m) {
    document.getElementById('formTitle').textContent = '메뉴 수정';
    document.getElementById('menuForm').action = '/admin/menus/' + m.id + '/edit';
    document.getElementById('mTitle').value   = m.title;
    document.getElementById('mUrl').value     = m.url;
    document.getElementById('mParent').value  = m.parent_id || '';
    document.getElementById('mSort').value    = m.sort_order;
    document.getElementById('mTarget').value  = m.target;
    document.getElementById('mActive').checked = m.is_active == 1;
}
function resetForm() {
    document.getElementById('formTitle').textContent = '메뉴 추가';
    document.getElementById('menuForm').action = '/admin/menus';
    document.getElementById('menuForm').reset();
}

// 카테고리 메뉴 빠른 추가
document.getElementById('btnAddCategoryMenu').addEventListener('click', function () {
    resetForm();
    document.getElementById('mTitle').value = '카테고리';
    document.getElementById('mUrl').value   = '#categories';
    document.getElementById('mSort').value  = 0;
    document.getElementById('mTitle').focus();
});

// ▲/▼ 순서 이동
const csrfName  = '<?= csrf_token() ?>';
let   csrfToken = '<?= csrf_hash() ?>';

document.querySelectorAll('.btn-move').forEach(btn => {
    btn.addEventListener('click', function () {
        const id  = this.dataset.id;
        const dir = this.dataset.dir;
        fetch('/admin/menus/' + id + '/move', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: csrfName + '=' + encodeURIComponent(csrfToken) + '&direction=' + dir,
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            }
        });
    });
});
</script>
<?= $this->endSection() ?>
