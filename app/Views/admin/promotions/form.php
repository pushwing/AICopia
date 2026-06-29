<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<?php $isEdit = $promotion !== null; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= $isEdit ? '기획전 수정' : '기획전 등록' ?></h4>
    <a href="/admin/promotions" class="btn btn-outline-secondary btn-sm">목록</a>
</div>

<form method="post" action="/admin/promotions/<?= $isEdit ? $promotion['id'].'/edit' : 'create' ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="products_json" id="productsJson" value="[]">

    <div class="row g-4">
        <!-- 좌측: 기본 정보 + 에디터 -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">기본 정보</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">제목 <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="titleInput" class="form-control"
                               value="<?= esc($promotion['title'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">슬러그 (URL) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text text-muted small">/promotion/</span>
                            <input type="text" name="slug" id="slugInput" class="form-control"
                                   value="<?= esc($promotion['slug'] ?? '') ?>" required
                                   pattern="[a-z0-9\-]+" title="영문 소문자·숫자·하이픈만 사용 가능">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnGenSlug">자동생성</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">배너 이미지</label>
                        <?php if (! empty($promotion['banner_image'])): ?>
                        <div class="mb-2">
                            <img src="<?= base_url(esc($promotion['banner_image'])) ?>" alt="배너 이미지"
                                 class="img-thumbnail" style="max-height:100px">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="banner_image_file" class="form-control form-control-sm"
                               accept="image/jpeg,image/png,image/gif">
                        <div class="form-text">jpg, png, gif / 최대 2MB<?= ! empty($promotion['banner_image']) ? ' (새 파일 선택 시 교체)' : '' ?></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">기획전 설명</div>
                <div class="card-body">
                    <textarea name="description" id="editor" class="form-control" rows="12"><?= $isEdit ? $promotion['description'] : '' ?></textarea>
                </div>
            </div>
        </div>

        <!-- 우측: 설정 -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">노출 설정</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">상태</label>
                        <select name="is_active" class="form-select form-select-sm">
                            <option value="1" <?= ($promotion['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>활성</option>
                            <option value="0" <?= ($promotion['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>비활성</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">접근 가능 등급</label>
                        <select name="grade_access" class="form-select form-select-sm">
                            <?php
                            $gradeOpts = ['all' => '전체 공개', 'bronze' => 'Bronze 이상', 'silver' => 'Silver 이상', 'gold' => 'Gold 이상', 'platinum' => 'Platinum 전용'];
                            foreach ($gradeOpts as $val => $label):
                                $sel = ($promotion['grade_access'] ?? 'all') === $val ? 'selected' : '';
                            ?>
                            <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">시작일</label>
                        <input type="date" name="start_date" class="form-control form-control-sm"
                               value="<?= esc($promotion['start_date'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">종료일</label>
                        <input type="date" name="end_date" class="form-control form-control-sm"
                               value="<?= esc($promotion['end_date'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">정렬 순서</label>
                        <input type="number" name="sort_order" class="form-control form-control-sm"
                               value="<?= (int) ($promotion['sort_order'] ?? 0) ?>">
                    </div>
                </div>
            </div>

            <!-- 상품 구성 -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>상품 구성</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#productPickerModal">
                        <i class="bi bi-plus-lg me-1"></i>상품 추가
                    </button>
                </div>
                <div class="card-body p-2">
                    <div id="selectedProducts" style="max-height:380px;overflow-y:auto">
                        <table class="table table-sm mb-0 small">
                            <tbody id="selectedBody">
                                <?php foreach ($products as $i => $prod): ?>
                                <tr data-id="<?= $prod['id'] ?>">
                                    <td class="text-truncate" style="max-width:120px"><?= esc($prod['name']) ?></td>
                                    <td style="width:50px">
                                        <input type="number" class="form-control form-control-sm sort-input"
                                               value="<?= (int) $prod['sort_order'] ?>" style="width:50px">
                                    </td>
                                    <td style="width:30px">
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove">✕</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="selectedEmpty" class="text-muted small text-center py-3" style="display:none">선택된 상품이 없습니다.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? '저장' : '등록' ?></button>
        <a href="/admin/promotions" class="btn btn-outline-secondary">취소</a>
    </div>
</form>

<!-- 상품 선택 팝업 -->
<div class="modal fade" id="productPickerModal" tabindex="-1" aria-labelledby="productPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold" id="productPickerModalLabel">상품 선택</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <!-- 검색 필터 -->
                <div class="row g-2 mb-3">
                    <div class="col">
                        <input type="text" id="modalSearchQ" class="form-control form-control-sm" placeholder="상품명 검색">
                    </div>
                    <div class="col-auto">
                        <select id="modalSearchCat" class="form-select form-select-sm" style="min-width:130px">
                            <option value="">전체 카테고리</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= esc($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-primary" id="btnModalSearch">검색</button>
                    </div>
                </div>

                <!-- 전체선택 + 결과 수 -->
                <div class="d-flex align-items-center gap-2 mb-2 small text-muted" id="modalResultMeta" style="display:none">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="chkSelectAll">
                        <label class="form-check-label" for="chkSelectAll">전체 선택</label>
                    </div>
                    <span id="modalResultCount"></span>
                </div>

                <!-- 검색 결과 테이블 -->
                <div style="min-height:300px">
                    <table class="table table-sm table-hover align-middle small mb-0" id="modalResultTable" style="display:none">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px"><input type="checkbox" id="chkSelectAllHead" class="form-check-input"></th>
                                <th style="width:50px"></th>
                                <th>상품명</th>
                                <th style="width:100px" class="text-end">가격</th>
                                <th style="width:70px" class="text-center">재고</th>
                                <th style="width:70px" class="text-center">상태</th>
                            </tr>
                        </thead>
                        <tbody id="modalResultBody"></tbody>
                    </table>
                    <div id="modalEmptyMsg" class="text-center text-muted py-5 small">검색어를 입력하고 검색 버튼을 누르세요.</div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <span class="me-auto small text-muted"><span id="checkedCount">0</span>개 선택됨</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">닫기</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnAddSelected">선택 추가</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.tiny.cloud/1/<?= config('Editor')->tinymceApiKey ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#editor',
    language: 'ko_KR',
    height: 400,
    plugins: 'lists link image table code',
    toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | link image | table | code',
    images_upload_url: '/admin/media/upload',
    automatic_uploads: true,
    file_picker_types: 'image',
    convert_urls: false,
    relative_urls: false,
    document_base_url: '<?= base_url('/') ?>',
});

// ── 슬러그 자동생성 ────────────────────────────────────────────────────────────
function koreanToRoman(str) {
    const cho  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    const jung = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    const jong = ['','k','k','k','n','n','n','t','l','k','m','p','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','t'];
    let result = '';
    for (let i = 0; i < str.length; i++) {
        const code = str.charCodeAt(i);
        if (code >= 0xAC00 && code <= 0xD7A3) {
            const offset  = code - 0xAC00;
            const choIdx  = Math.floor(offset / (21 * 28));
            const jungIdx = Math.floor((offset % (21 * 28)) / 28);
            const jongIdx = offset % 28;
            result += cho[choIdx] + jung[jungIdx] + jong[jongIdx];
        } else {
            result += str[i];
        }
    }
    return result;
}

document.getElementById('btnGenSlug').addEventListener('click', function () {
    const title = koreanToRoman(document.getElementById('titleInput').value.trim());
    const slug  = title
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 100);
    document.getElementById('slugInput').value = slug;
});

// ── 유틸 ───────────────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── 선택된 상품 관리 ────────────────────────────────────────────────────────────
const selectedMap = {};  // product_id(string) → {name, sort_order}

<?php foreach ($products as $i => $prod): ?>
selectedMap[<?= $prod['id'] ?>] = { name: <?= json_encode($prod['name']) ?>, sort_order: <?= (int) $prod['sort_order'] ?> };
<?php endforeach; ?>

function renderSelected() {
    const tbody = document.getElementById('selectedBody');
    const empty = document.getElementById('selectedEmpty');
    const keys  = Object.keys(selectedMap);
    tbody.innerHTML = '';
    if (keys.length === 0) {
        empty.style.display = '';
        syncJson();
        return;
    }
    empty.style.display = 'none';
    keys.forEach(id => {
        const item = selectedMap[id];
        const tr   = document.createElement('tr');
        tr.dataset.id = id;
        tr.innerHTML = `
            <td class="text-truncate" style="max-width:130px" title="${escHtml(item.name)}">${escHtml(item.name)}</td>
            <td style="width:55px"><input type="number" class="form-control form-control-sm sort-input" value="${item.sort_order}" style="width:55px" min="0"></td>
            <td style="width:30px"><button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove">✕</button></td>`;
        tbody.appendChild(tr);
    });
    syncJson();

    tbody.querySelectorAll('.sort-input').forEach(input => {
        input.addEventListener('change', function () {
            const id = this.closest('tr').dataset.id;
            if (selectedMap[id]) selectedMap[id].sort_order = parseInt(this.value) || 0;
            syncJson();
        });
    });
    tbody.querySelectorAll('.btn-remove').forEach(btn => {
        btn.addEventListener('click', function () {
            delete selectedMap[this.closest('tr').dataset.id];
            renderSelected();
        });
    });
}

function syncJson() {
    const arr = Object.entries(selectedMap).map(([id, item]) => ({
        product_id: parseInt(id), sort_order: item.sort_order,
    }));
    document.getElementById('productsJson').value = JSON.stringify(arr);
}

renderSelected();

// ── 상품 선택 모달 ──────────────────────────────────────────────────────────────
(function () {
    const modalEl    = document.getElementById('productPickerModal');
    const searchQ    = document.getElementById('modalSearchQ');
    const searchCat  = document.getElementById('modalSearchCat');
    const btnSearch  = document.getElementById('btnModalSearch');
    const resultTbl  = document.getElementById('modalResultTable');
    const resultBody = document.getElementById('modalResultBody');
    const emptyMsg   = document.getElementById('modalEmptyMsg');
    const chkAllHead = document.getElementById('chkSelectAllHead');
    const metaBar    = document.getElementById('modalResultMeta');
    const countSpan  = document.getElementById('checkedCount');
    const btnAdd     = document.getElementById('btnAddSelected');

    let lastResults  = [];

    function updateCheckedCount() {
        const n = resultBody.querySelectorAll('input[type=checkbox]:checked').length;
        countSpan.textContent = n;
        chkAllHead.checked = lastResults.length > 0 && n === lastResults.length;
        chkAllHead.indeterminate = n > 0 && n < lastResults.length;
    }

    function renderResults(products) {
        lastResults = products;
        resultBody.innerHTML = '';
        chkAllHead.checked = false;
        chkAllHead.indeterminate = false;
        countSpan.textContent = '0';

        if (products.length === 0) {
            resultTbl.style.display  = 'none';
            metaBar.style.display    = 'none';
            emptyMsg.style.display   = '';
            emptyMsg.textContent     = '검색 결과가 없습니다.';
            return;
        }

        emptyMsg.style.display  = 'none';
        metaBar.style.display   = '';
        resultTbl.style.display = '';

        products.forEach(p => {
            const alreadyIn = !!selectedMap[p.id];
            const thumb = p.primary_image
                ? `<img src="${escHtml(p.primary_image)}" style="width:40px;height:40px;object-fit:cover;border-radius:4px">`
                : `<span style="display:inline-block;width:40px;height:40px;background:#f0f0f0;border-radius:4px;line-height:40px;text-align:center;color:#aaa;font-size:18px">·</span>`;
            const statusBadge = p.status === 'active'
                ? '<span class="badge bg-success-subtle text-success">판매중</span>'
                : '<span class="badge bg-secondary-subtle text-secondary">비활성</span>';
            const tr = document.createElement('tr');
            if (alreadyIn) tr.classList.add('table-warning');
            tr.innerHTML = `
                <td><input type="checkbox" class="form-check-input prod-chk" data-id="${p.id}" data-name="${escHtml(p.name)}" ${alreadyIn ? 'checked disabled' : ''}></td>
                <td>${thumb}</td>
                <td class="text-truncate" style="max-width:240px" title="${escHtml(p.name)}">${escHtml(p.name)}</td>
                <td class="text-end">${Number(p.price).toLocaleString()}원</td>
                <td class="text-center">${Number(p.stock).toLocaleString()}</td>
                <td class="text-center">${statusBadge}</td>`;
            resultBody.appendChild(tr);
        });

        resultBody.querySelectorAll('.prod-chk:not([disabled])').forEach(chk => {
            chk.addEventListener('change', updateCheckedCount);
        });
        updateCheckedCount();
    }

    function doSearch() {
        const q   = searchQ.value.trim();
        const cat = searchCat.value;
        emptyMsg.textContent  = '검색 중...';
        emptyMsg.style.display = '';
        resultTbl.style.display = 'none';
        fetch('/admin/promotions/product-search?q=' + encodeURIComponent(q) + '&category_id=' + encodeURIComponent(cat))
            .then(r => r.json())
            .then(data => renderResults(data.products || []));
    }

    btnSearch.addEventListener('click', doSearch);
    searchQ.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

    chkAllHead.addEventListener('change', function () {
        resultBody.querySelectorAll('.prod-chk:not([disabled])').forEach(chk => {
            chk.checked = this.checked;
        });
        updateCheckedCount();
    });

    btnAdd.addEventListener('click', function () {
        resultBody.querySelectorAll('.prod-chk:not([disabled]):checked').forEach(chk => {
            const id   = chk.dataset.id;
            const name = chk.dataset.name;
            if (!selectedMap[id]) {
                selectedMap[id] = { name, sort_order: Object.keys(selectedMap).length };
            }
        });
        renderSelected();
        bootstrap.Modal.getInstance(modalEl).hide();
    });

    // 모달 열릴 때 검색창 초기화 후 포커스
    modalEl.addEventListener('shown.bs.modal', function () {
        searchQ.value = '';
        searchCat.value = '';
        renderResults([]);
        emptyMsg.style.display = '';
        emptyMsg.textContent = '검색어를 입력하고 검색 버튼을 누르세요.';
        searchQ.focus();
    });
}());
</script>
<?= $this->endSection() ?>
