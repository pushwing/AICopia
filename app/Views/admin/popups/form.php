<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $popup ? '팝업 수정' : '팝업 등록' ?>

<?= $this->section('content') ?>

<div class="card" style="max-width:780px">
    <div class="card-body">
        <form method="post"
              action="<?= $popup ? "/admin/popups/{$popup['id']}/edit" : '/admin/popups/create' ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- 제목 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">제목 <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= esc(old('title', $popup['title'] ?? '')) ?>" required>
            </div>

            <!-- 이미지 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">이미지</label>
                <?php if ($popup && $popup['image_path']): ?>
                <div class="mb-2">
                    <img src="/<?= esc($popup['image_path']) ?>" alt=""
                         style="max-width:300px;max-height:150px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px">
                </div>
                <div class="form-text mb-1">새 파일을 선택하면 교체됩니다.</div>
                <?php endif; ?>
                <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.gif">
                <div class="form-text">jpg, jpeg, png, gif / 최대 2MB (비워두면 이미지 없이 텍스트만 표시)</div>
            </div>

            <!-- 텍스트 본문 (TinyMCE) -->
            <div class="mb-3">
                <label class="form-label fw-semibold">텍스트 본문</label>
                <textarea name="content" id="popup-content-editor" class="form-control" rows="6"><?= old('content', $popup['content'] ?? '') ?></textarea>
                <div class="form-text">이미지와 함께 또는 단독으로 사용 가능합니다.</div>
            </div>

            <!-- 노출 범위 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">노출 범위</label>
                <select name="show_scope" id="show_scope" class="form-select">
                    <?php foreach ($scopes as $val => $label): ?>
                    <option value="<?= $val ?>" <?= old('show_scope', $popup['show_scope'] ?? 'all') === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 특정 페이지 선택 -->
            <div class="mb-3" id="specific-pages" style="display:none">
                <label class="form-label fw-semibold">노출 페이지 선택</label>
                <div class="border rounded p-3" style="max-height:200px;overflow-y:auto">
                    <?php if (empty($allMenus)): ?>
                    <span class="text-muted small">등록된 메뉴가 없습니다.</span>
                    <?php endif; ?>
                    <?php foreach ($allMenus as $menu): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="page_ids[]"
                               id="menu_<?= $menu['id'] ?>" value="<?= $menu['id'] ?>"
                               <?= in_array($menu['id'], $pageIds) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="menu_<?= $menu['id'] ?>">
                            <?= esc($menu['title']) ?>
                            <span class="text-muted">(<?= esc($menu['url']) ?>)</span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 표시 좌표 -->
            <div class="row g-3 mb-3">
                <div class="col-auto">
                    <label class="form-label fw-semibold">X 좌표 (left)</label>
                    <div class="input-group" style="width:140px">
                        <input type="number" name="pos_x" class="form-control"
                               value="<?= esc(old('pos_x', $popup['pos_x'] ?? 20)) ?>" min="0">
                        <span class="input-group-text">px</span>
                    </div>
                </div>
                <div class="col-auto">
                    <label class="form-label fw-semibold">Y 좌표 (top)</label>
                    <div class="input-group" style="width:140px">
                        <input type="number" name="pos_y" class="form-control"
                               value="<?= esc(old('pos_y', $popup['pos_y'] ?? 20)) ?>" min="0">
                        <span class="input-group-text">px</span>
                    </div>
                </div>
            </div>

            <!-- 우선순위 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">우선순위</label>
                <input type="number" name="priority" class="form-control" style="width:120px"
                       value="<?= esc(old('priority', $popup['priority'] ?? 0)) ?>" min="0">
                <div class="form-text">숫자가 낮을수록 먼저 표시됩니다.</div>
            </div>

            <!-- 운영 기간 -->
            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-semibold">시작일</label>
                    <?php
                    $startedVal = '';
                    if (!empty($popup['started_at'])) {
                        $startedVal = str_replace(' ', 'T', substr($popup['started_at'], 0, 16));
                    }
                    ?>
                    <input type="datetime-local" name="started_at" class="form-control"
                           value="<?= esc(old('started_at', $startedVal)) ?>">
                </div>
                <div class="col">
                    <label class="form-label fw-semibold">종료일</label>
                    <?php
                    $endedVal = '';
                    if (!empty($popup['ended_at'])) {
                        $endedVal = str_replace(' ', 'T', substr($popup['ended_at'], 0, 16));
                    }
                    ?>
                    <input type="datetime-local" name="ended_at" class="form-control"
                           value="<?= esc(old('ended_at', $endedVal)) ?>">
                    <div class="form-text">비워두면 기간 제한 없음.</div>
                </div>
            </div>

            <!-- 운영 상태 -->
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1"
                           <?= old('is_active', $popup['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">운영 중</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $popup ? '저장' : '등록' ?></button>
                <a href="/admin/popups" class="btn btn-outline-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="https://cdn.tiny.cloud/1/<?= config('Editor')->tinymceApiKey ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#popup-content-editor',
    language: 'ko_KR',
    height: 300,
    plugins: 'lists link image',
    toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright | bullist numlist | link | removeformat',
    menubar: false,
});

document.querySelector('form').addEventListener('submit', function () {
    tinymce.triggerSave();
});

// 노출 범위 변경 시 특정 페이지 선택 UI 표시/숨김
const scopeEl   = document.getElementById('show_scope');
const pagesEl   = document.getElementById('specific-pages');
function togglePages() {
    pagesEl.style.display = scopeEl.value === 'specific' ? '' : 'none';
}
scopeEl.addEventListener('change', togglePages);
togglePages();
</script>
<?= $this->endSection() ?>
