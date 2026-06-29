<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $product ? '상품 수정' : '상품 등록' ?>

<?= $this->section('content') ?>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="post"
              action="<?= $product ? "/admin/products/{$product['id']}/edit" : '/admin/products/create' ?>"
              enctype="multipart/form-data"
              id="productForm">
            <?= csrf_field() ?>

            <!-- 기본 정보 -->
            <div class="card mb-3">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>기본 정보</span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnNaverSearch">
                        <i class="bi bi-search me-1"></i>네이버 상품 검색
                    </button>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">상품명 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= esc(old('name', $product['name'] ?? '')) ?>" required
                               oninput="autoSlug(this.value)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">슬러그 <span class="text-danger">*</span></label>
                        <input type="text" name="slug" id="slugInput" class="form-control"
                               value="<?= esc(old('slug', $product['slug'] ?? '')) ?>" required>
                        <div class="form-text">URL에 사용됩니다. 영문·숫자·한글·하이픈만 허용.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">카테고리</label>
                        <?php
                        $oldCatIds = old('category_ids', $categoryIds ?? []);
                        $oldCatIds = array_map('intval', (array) $oldCatIds);
                        ?>
                        <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                            <?php if (empty($tree)): ?>
                            <span class="text-muted small">등록된 카테고리가 없습니다.</span>
                            <?php endif; ?>
                            <?php foreach ($tree as $parent): ?>
                            <div class="mb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="category_ids[]"
                                           value="<?= $parent['id'] ?>"
                                           id="cat_<?= $parent['id'] ?>"
                                           <?= in_array((int)$parent['id'], $oldCatIds) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="cat_<?= $parent['id'] ?>">
                                        <?= esc($parent['name']) ?>
                                    </label>
                                </div>
                                <?php foreach ($parent['children'] as $child): ?>
                                <div class="form-check ms-3">
                                    <input class="form-check-input" type="checkbox"
                                           name="category_ids[]"
                                           value="<?= $child['id'] ?>"
                                           id="cat_<?= $child['id'] ?>"
                                           <?= in_array((int)$child['id'], $oldCatIds) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-muted" for="cat_<?= $child['id'] ?>">
                                        — <?= esc($child['name']) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <span class="form-text mb-0">여러 카테고리를 선택할 수 있습니다.</span>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="btnAiSuggest">
                                <i class="bi bi-stars me-1"></i>AI 추천
                            </button>
                        </div>
                        <div id="aiSuggestMsg" class="form-text text-primary d-none"></div>
                    </div>
                </div>
            </div>

            <!-- 가격 / 재고 -->
            <div class="card mb-3">
                <div class="card-header fw-semibold">가격 및 재고</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label">정가 (원) <span class="text-danger">*</span></label>
                            <input type="number" name="price" class="form-control" min="0"
                                   value="<?= esc(old('price', $product['price'] ?? 0)) ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">할인가 (원)</label>
                            <input type="number" name="discount_price" class="form-control" min="0"
                                   value="<?= esc(old('discount_price', $product['discount_price'] ?? '')) ?>">
                            <div class="form-text">비워두면 할인 없음</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">재고 수량 <span class="text-danger">*</span></label>
                            <input type="number" name="stock" class="form-control" min="0"
                                   value="<?= esc(old('stock', $product['stock'] ?? 0)) ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">판매 상태 <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                <?php foreach ($statuses as $val => $label): ?>
                                <option value="<?= $val ?>" <?= old('status', $product['status'] ?? 'on_sale') === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 매입처 / 원가 -->
            <div class="card mb-3">
                <div class="card-header fw-semibold">매입 정보</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label">매입처</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">선택 안함</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>"
                                        <?= old('supplier_id', $product['supplier_id'] ?? '') == $sup['id'] ? 'selected' : '' ?>>
                                    <?= esc($sup['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">매입단가 (원)</label>
                            <input type="number" name="cost_price" class="form-control" min="0" step="0.01"
                                   value="<?= esc(old('cost_price', $product['cost_price'] ?? 0)) ?>">
                            <div class="form-text">영업이익 계산에 사용됩니다.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 배송비 -->
            <div class="card mb-3">
                <div class="card-header fw-semibold">배송비 설정</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">배송비 유형</label>
                        <select name="shipping_type" class="form-select" id="shippingType"
                                onchange="toggleShippingFields()">
                            <?php foreach ($shippings as $val => $label): ?>
                            <option value="<?= $val ?>"
                                    <?= old('shipping_type', $product['shipping_type'] ?? 'free') === $val ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="shippingFeeField" class="mb-3" style="display:none">
                        <label class="form-label">배송비 (원)</label>
                        <input type="number" name="shipping_fee" class="form-control" min="0"
                               value="<?= esc(old('shipping_fee', $product['shipping_fee'] ?? 0)) ?>">
                    </div>
                    <div id="freeThresholdField" class="mb-3" style="display:none">
                        <label class="form-label">무료배송 기준금액 (원)</label>
                        <input type="number" name="free_threshold" class="form-control" min="0"
                               value="<?= esc(old('free_threshold', $product['free_threshold'] ?? 0)) ?>">
                        <div class="form-text">이 금액 이상 구매 시 무료배송 (미달 시 위 배송비 적용)</div>
                    </div>
                </div>
            </div>

            <!-- 상품 상세 -->
            <div class="card mb-3">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>상품 상세 내용</span>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btnAiGenerateDesc">
                        <i class="bi bi-stars me-1"></i>AI 설명 생성
                    </button>
                </div>
                <div class="card-body">
                    <?php
                        $desc = old('description', $product['description'] ?? '');
                        // DB에 저장된 상대경로(../../uploads/)를 절대경로로 변환
                        $desc = preg_replace('#(\.\.\/)+uploads/#', base_url('uploads/'), $desc);
                    ?>
                    <textarea name="description" id="editor" class="form-control" rows="12"><?= esc($desc, 'html') ?></textarea>
                </div>
            </div>

            <!-- 상품 옵션 / SKU -->
            <div class="card mb-3" id="optionCard">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>상품 옵션 (SKU)</span>
                    <span class="text-muted small fw-normal">옵션을 추가하면 조합별 재고·가격을 관리할 수 있습니다.</span>
                </div>
                <div class="card-body">
                    <input type="hidden" name="options_json" id="optionsJson">

                    <!-- 옵션 그룹 목록 -->
                    <div id="optionGroupList" class="mb-3"></div>

                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addOptionGroup()">
                        <i class="bi bi-plus-circle me-1"></i>옵션 그룹 추가
                    </button>

                    <!-- SKU 테이블 -->
                    <div id="skuSection" style="display:none">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-semibold small">SKU (옵션 조합별 재고·가격)</span>
                            <button type="button" class="btn btn-sm btn-primary" onclick="generateSkus()">
                                <i class="bi bi-arrow-repeat me-1"></i>조합 자동 생성
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>옵션 조합</th>
                                        <th style="width:130px">가격 차이 (±원)</th>
                                        <th style="width:100px">재고</th>
                                        <th style="width:120px">관리코드</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody id="skuTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary"><?= $product ? '저장' : '등록' ?></button>
                <a href="/admin/products" class="btn btn-outline-secondary">취소</a>
                <?php if ($product): ?>
                <form method="post" action="/admin/products/<?= $product['id'] ?>/copy" class="d-inline"
                      onsubmit="return confirm('이 상품을 복사하시겠습니까?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-info">
                        <i class="bi bi-copy"></i> 상품 복사
                    </button>
                </form>
                <?php endif ?>
            </div>
        </form>
    </div>

    <!-- 이미지 패널 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">상품 이미지</div>
            <div class="card-body">
                <?php if (! empty($images)): ?>
                <div class="row g-2 mb-3" id="imageGrid">
                    <?php foreach ($images as $img): ?>
                    <div class="col-6" id="imgWrap_<?= $img['media_id'] ?>">
                        <div class="position-relative">
                            <img src="<?= esc($img['media_url']) ?>" alt=""
                                 style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;border:2px solid <?= $img['is_primary'] ? '#0d6efd' : '#dee2e6' ?>"
                                 id="imgThumb_<?= $img['media_id'] ?>">
                            <?php if ($img['is_primary']): ?>
                            <span class="badge bg-primary position-absolute" style="top:4px;left:4px">대표</span>
                            <?php endif; ?>
                            <button type="button"
                                    class="btn btn-danger btn-sm position-absolute"
                                    style="top:4px;right:4px;padding:2px 6px"
                                    onclick="deleteImage(<?= $img['id'] ?>, <?= $img['media_id'] ?>)">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="radio" name="primary_media_id"
                                   value="<?= $img['media_id'] ?>" form="productForm"
                                   <?= $img['is_primary'] ? 'checked' : '' ?>>
                            <label class="form-check-label small">대표 이미지</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- 네이버 검색에서 임포트된 이미지 미리보기 -->
                <div id="naverImportedImages" class="row g-2 mb-2"></div>

                <div class="mb-2">
                    <label class="form-label small fw-semibold">이미지 추가</label>
                    <input type="file" name="images[]" class="form-control form-control-sm"
                           accept=".jpg,.jpeg,.png,.gif,.webp" multiple form="productForm">
                    <div class="form-text">jpg, jpeg, png, gif, webp / 최대 5MB / 여러 파일 선택 가능</div>
                    <div class="form-text">첫 번째 업로드된 이미지가 대표 이미지가 됩니다.</div>
                </div>

                <!-- AI 이미지 분석 (Vision) -->
                <div class="border-top pt-3 mt-3">
                    <label class="form-label small fw-semibold">
                        <i class="bi bi-stars text-info me-1"></i>이미지로 상품정보 채우기
                    </label>
                    <input type="file" id="visionFile" class="form-control form-control-sm mb-2"
                           accept=".jpg,.jpeg,.png,.gif,.webp">
                    <button type="button" class="btn btn-sm btn-outline-info w-100" id="btnVisionExtract">
                        <i class="bi bi-magic me-1"></i>AI로 상품명·설명 생성
                    </button>
                    <div id="visionMsg" class="form-text text-info d-none"></div>
                    <div class="form-text">이미지를 분석해 상품명·설명을 자동 입력합니다. (Claude API 키 필요)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 네이버 쇼핑 검색 모달 -->
<div class="modal fade" id="naverSearchModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-search me-2"></i>네이버 쇼핑 상품 검색</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" id="naverKeyword" class="form-control" placeholder="검색어를 입력하세요 (예: 남성 반팔 티셔츠)">
                    <button class="btn btn-success" id="btnNaverSearchExec">
                        <i class="bi bi-search me-1"></i>검색
                    </button>
                </div>
                <div id="naverSearchStatus" class="text-muted small mb-2 d-none"></div>
                <div id="naverSearchResults" class="row g-2"></div>
                <div class="d-flex justify-content-center mt-3 d-none" id="naverSearchMore">
                    <button class="btn btn-outline-secondary btn-sm" id="btnNaverSearchMore">더 보기</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AI 상품 설명 생성 모달 -->
<div class="modal fade" id="aiDescModal" tabindex="-1" aria-labelledby="aiDescModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiDescModalLabel">
                    <i class="bi bi-stars me-2 text-info"></i>AI 상품 설명 생성
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-muted fw-semibold">현재 설명</h6>
                            <span class="badge bg-secondary">이전</span>
                        </div>
                        <div id="aiDescOriginal" class="border rounded p-3 bg-light" style="min-height:320px; max-height:520px; overflow-y:auto;"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-info fw-semibold">AI 생성 설명</h6>
                            <span id="aiDescStatus" class="badge bg-secondary d-none">생성 중...</span>
                        </div>
                        <div id="aiDescResult" class="border rounded p-3" style="min-height:320px; max-height:520px; overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="btnUseOriginalDesc">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>이전 내용 사용
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" id="btnRegenerateDesc" disabled>
                        <i class="bi bi-arrow-repeat me-1"></i>다시 생성
                    </button>
                    <button type="button" class="btn btn-info text-white" id="btnApplyAiDesc" disabled>
                        <i class="bi bi-check-lg me-1"></i>AI 설명 적용
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">닫기</button>
                </div>
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
    height: 450,
    plugins: 'lists link image table code',
    toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | link image | table | code',
    images_upload_url: '/admin/media/upload',
    automatic_uploads: true,
    convert_urls: false,
    relative_urls: false,
});

function toggleShippingFields() {
    const type = document.getElementById('shippingType').value;
    document.getElementById('shippingFeeField').style.display    = (type === 'fixed' || type === 'conditional') ? '' : 'none';
    document.getElementById('freeThresholdField').style.display  = type === 'conditional' ? '' : 'none';
}

function autoSlug(name) {
    const slug = name.toLowerCase()
        .replace(/[^\w가-힣\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-');
    document.getElementById('slugInput').value = slug;
}

function deleteImage(imageId, mediaId) {
    if (! confirm('이미지를 삭제하시겠습니까?')) return;
    fetch(`/admin/products/image/${imageId}/delete`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: '<?= csrf_token() ?>=<?= csrf_hash() ?>',
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('imgWrap_' + mediaId)?.remove();
        } else {
            alert(data.error ?? '삭제 실패');
        }
    });
}

toggleShippingFields();

// ── 옵션 / SKU 관리 ──────────────────────────────────────────────────────────
// 기존 데이터 (수정 시 서버에서 전달)
let optionGroups = <?= json_encode($optionsAndSkus['options'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
let skuList      = <?= json_encode($optionsAndSkus['skus']    ?? [], JSON_UNESCAPED_UNICODE) ?>;

// 임시 ID 카운터 (클라이언트 전용)
let tmpIdCounter = 1000;

function nextTmpId() { return 't' + (tmpIdCounter++); }

// 초기 렌더링
(function () {
    // 기존 options에 tmp_id 부여
    optionGroups.forEach(function (g) {
        g.tmp_id = nextTmpId();
        g.values.forEach(function (v) { v.tmp_id = nextTmpId(); });
    });
    // 기존 SKU의 value_tmp_ids를 option_value_ids 기반으로 매핑
    const dbIdToTmpId = {};
    optionGroups.forEach(function (g) {
        g.values.forEach(function (v) { dbIdToTmpId[v.id] = v.tmp_id; });
    });
    skuList.forEach(function (sku) {
        sku.value_tmp_ids = (sku.option_value_ids || []).map(function (id) { return dbIdToTmpId[id] || id; });
    });

    renderOptionGroups();
    renderSkuSection();
})();

function renderOptionGroups() {
    const el = document.getElementById('optionGroupList');
    if (optionGroups.length === 0) {
        el.innerHTML = '<p class="text-muted small mb-0">옵션 없음 (옵션 없는 상품은 위의 재고 수량을 사용합니다.)</p>';
        return;
    }
    el.innerHTML = optionGroups.map(function (g, gi) {
        return `
        <div class="border rounded p-2 mb-2 bg-light">
            <div class="d-flex align-items-center gap-2 mb-2">
                <input type="text" class="form-control form-control-sm" placeholder="그룹명 (예: 색상)"
                       style="max-width:180px" value="${esc(g.name)}"
                       oninput="optionGroups[${gi}].name = this.value">
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="removeOptionGroup(${gi})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center" id="valueList_${gi}">
                ${g.values.map(function (v, vi) { return renderValueChip(gi, vi, v); }).join('')}
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="addOptionValue(${gi})">+ 값 추가</button>
            </div>
        </div>`;
    }).join('');
}

function renderValueChip(gi, vi, v) {
    return `<div class="input-group input-group-sm" style="width:auto">
        <input type="text" class="form-control form-control-sm" placeholder="값 (예: 빨강)"
               style="width:100px" value="${esc(v.value)}"
               oninput="optionGroups[${gi}].values[${vi}].value = this.value">
        <button class="btn btn-outline-danger" type="button"
                onclick="removeOptionValue(${gi}, ${vi})"><i class="bi bi-x"></i></button>
    </div>`;
}

function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function addOptionGroup() {
    optionGroups.push({ tmp_id: nextTmpId(), name: '', values: [] });
    renderOptionGroups();
    renderSkuSection();
}

function removeOptionGroup(gi) {
    optionGroups.splice(gi, 1);
    renderOptionGroups();
    generateSkus();
}

function addOptionValue(gi) {
    optionGroups[gi].values.push({ tmp_id: nextTmpId(), value: '' });
    renderOptionGroups();
}

function removeOptionValue(gi, vi) {
    optionGroups[gi].values.splice(vi, 1);
    renderOptionGroups();
    generateSkus();
}

function renderSkuSection() {
    const section = document.getElementById('skuSection');
    const hasGroups = optionGroups.length > 0 && optionGroups.some(function (g) { return g.values.length > 0; });
    section.style.display = hasGroups ? '' : 'none';
    if (! hasGroups) return;

    const tbody = document.getElementById('skuTableBody');
    tbody.innerHTML = skuList.map(function (sku, si) {
        const label = buildSkuLabel(sku.value_tmp_ids);
        return `<tr>
            <td class="small">${esc(label)}</td>
            <td><input type="number" class="form-control form-control-sm" value="${sku.price_diff || 0}"
                       oninput="skuList[${si}].price_diff = parseInt(this.value)||0"></td>
            <td><input type="number" class="form-control form-control-sm" value="${sku.stock || 0}" min="0"
                       oninput="skuList[${si}].stock = parseInt(this.value)||0"></td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(sku.sku_code||'')}"
                       oninput="skuList[${si}].sku_code = this.value"></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger"
                onclick="skuList.splice(${si},1); renderSkuSection()"><i class="bi bi-x"></i></button></td>
        </tr>`;
    }).join('');
}

function buildSkuLabel(valueTmpIds) {
    if (! valueTmpIds || valueTmpIds.length === 0) return '-';
    const parts = [];
    optionGroups.forEach(function (g) {
        g.values.forEach(function (v) {
            if (valueTmpIds.includes(v.tmp_id)) {
                parts.push(g.name + ':' + v.value);
            }
        });
    });
    return parts.join('/');
}

function generateSkus() {
    // Cartesian product of all option values
    let combos = [[]];
    optionGroups.forEach(function (g) {
        if (g.values.length === 0) return;
        const newCombos = [];
        combos.forEach(function (combo) {
            g.values.forEach(function (v) {
                newCombos.push(combo.concat([v.tmp_id]));
            });
        });
        combos = newCombos;
    });

    // 기존 재고·가격 차이 보존 (라벨 기반 매칭)
    const prevMap = {};
    skuList.forEach(function (sku) {
        const key = (sku.value_tmp_ids || []).slice().sort().join(',');
        prevMap[key] = sku;
    });

    skuList = combos.map(function (ids) {
        const key  = ids.slice().sort().join(',');
        const prev = prevMap[key];
        return {
            value_tmp_ids: ids,
            price_diff:    prev ? prev.price_diff : 0,
            stock:         prev ? prev.stock      : 0,
            sku_code:      prev ? prev.sku_code   : '',
        };
    });

    renderSkuSection();
}

// 폼 제출 전 options_json 직렬화
document.getElementById('productForm').addEventListener('submit', function () {
    const data = {
        options: optionGroups.map(function (g) {
            return {
                name:   g.name,
                values: g.values.map(function (v) {
                    return { tmp_id: v.tmp_id, value: v.value };
                }),
            };
        }),
        skus: skuList.map(function (s) {
            return {
                value_tmp_ids: s.value_tmp_ids,
                price_diff:    s.price_diff || 0,
                stock:         s.stock      || 0,
                sku_code:      s.sku_code   || null,
            };
        }),
    };
    document.getElementById('optionsJson').value = optionGroups.length > 0 ? JSON.stringify(data) : '';

    // 옵션이 있으면 products.stock을 SKU 합계로 동기화 (서버 검증 통과 + DB 일관성)
    if (optionGroups.length > 0) {
        const totalStock = skuList.reduce(function (sum, s) { return sum + (parseInt(s.stock) || 0); }, 0);
        document.querySelector('input[name="stock"]').value = totalStock;
    }
});

// AI 카테고리 추천
document.getElementById('btnAiSuggest').addEventListener('click', async function () {
    const name = document.querySelector('input[name="name"]').value.trim();
    const desc = tinymce.get('description') ? tinymce.get('description').getContent({format:'text'}) : (document.querySelector('textarea[name="description"]')?.value ?? '');
    const msg  = document.getElementById('aiSuggestMsg');

    if (!name) {
        msg.textContent = '상품명을 먼저 입력해주세요.';
        msg.className   = 'form-text text-danger';
        msg.classList.remove('d-none');
        return;
    }

    this.disabled   = true;
    this.innerHTML  = '<span class="spinner-border spinner-border-sm me-1"></span>추천 중…';
    msg.classList.add('d-none');

    try {
        const res  = await fetch('/admin/products/suggest-category', {
            method : 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body   : new URLSearchParams({
                name       : name,
                description: desc,
                '<?= csrf_token() ?>': '<?= csrf_hash() ?>',
            }),
        });
        const data = await res.json();

        if (data.error) {
            const link = data.setup_url ? ` <a href="${data.setup_url}">설정 바로가기 →</a>` : '';
            msg.innerHTML = data.error + link;
            msg.className = 'form-text text-danger';
            msg.classList.remove('d-none');
            return;
        }

        // 기존 체크 해제 후 추천 카테고리 선택
        document.querySelectorAll('input[name="category_ids[]"]').forEach(cb => cb.checked = false);
        let matched = 0;
        (data.category_ids ?? []).forEach(id => {
            const cb = document.getElementById('cat_' + id);
            if (cb) { cb.checked = true; matched++; }
        });

        msg.textContent = matched > 0
            ? `AI가 ${matched}개 카테고리를 추천했습니다.`
            : '적합한 카테고리를 찾지 못했습니다.';
        msg.className = 'form-text ' + (matched > 0 ? 'text-primary' : 'text-warning');
        msg.classList.remove('d-none');
    } catch (e) {
        msg.textContent = '네트워크 오류가 발생했습니다.';
        msg.className   = 'form-text text-danger';
        msg.classList.remove('d-none');
    } finally {
        this.disabled  = false;
        this.innerHTML = '<i class="bi bi-stars me-1"></i>AI 추천';
    }
});

// ── AI 상품 설명 생성 ──────────────────────────────────────────────────────────
let aiDescOriginalContent = '';
let aiDescLastGenerated   = '';
const aiDescModalEl       = document.getElementById('aiDescModal');
const aiDescModal         = new bootstrap.Modal(aiDescModalEl);

document.getElementById('btnAiGenerateDesc').addEventListener('click', function () {
    const editor = tinymce.get('editor');
    aiDescOriginalContent = editor ? editor.getContent() : (document.getElementById('editor')?.value ?? '');

    document.getElementById('aiDescOriginal').innerHTML = aiDescOriginalContent || '<em class="text-muted">내용 없음</em>';
    document.getElementById('aiDescResult').innerHTML   = '<div class="d-flex justify-content-center align-items-center" style="min-height:200px"><div class="spinner-border text-info" role="status"><span class="visually-hidden">생성 중...</span></div></div>';
    document.getElementById('btnApplyAiDesc').disabled    = true;
    document.getElementById('btnRegenerateDesc').disabled = true;

    aiDescModal.show();
    callGenerateDescription(aiDescOriginalContent);
});

async function callGenerateDescription(baseDescription) {
    const name   = document.querySelector('input[name="name"]').value.trim();
    const status = document.getElementById('aiDescStatus');

    status.textContent = '생성 중...';
    status.className   = 'badge bg-warning text-dark';
    status.classList.remove('d-none');
    document.getElementById('btnRegenerateDesc').disabled = true;
    document.getElementById('btnApplyAiDesc').disabled    = true;

    try {
        const res  = await fetch('/admin/products/generate-description', {
            method : 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body   : new URLSearchParams({
                name       : name,
                description: baseDescription,
                '<?= csrf_token() ?>': '<?= csrf_hash() ?>',
            }),
        });
        const data = await res.json();

        if (data.error) {
            const link = data.setup_url ? ` <a href="${data.setup_url}">설정 바로가기 →</a>` : '';
            document.getElementById('aiDescResult').innerHTML = `<div class="alert alert-danger mb-0">${data.error}${link}</div>`;
            status.textContent = '오류';
            status.className   = 'badge bg-danger';
            return;
        }

        aiDescLastGenerated = data.description ?? '';
        document.getElementById('aiDescResult').innerHTML     = aiDescLastGenerated || '<em class="text-muted">내용 없음</em>';
        document.getElementById('btnApplyAiDesc').disabled    = false;
        document.getElementById('btnRegenerateDesc').disabled = false;
        status.textContent = '생성 완료';
        status.className   = 'badge bg-success';

    } catch (e) {
        document.getElementById('aiDescResult').innerHTML = '<div class="alert alert-danger mb-0">네트워크 오류가 발생했습니다.</div>';
        status.textContent = '오류';
        status.className   = 'badge bg-danger';
    }
}

document.getElementById('btnRegenerateDesc').addEventListener('click', function () {
    document.getElementById('aiDescResult').innerHTML = '<div class="d-flex justify-content-center align-items-center" style="min-height:200px"><div class="spinner-border text-info" role="status"></div></div>';
    callGenerateDescription(aiDescOriginalContent);
});

document.getElementById('btnApplyAiDesc').addEventListener('click', function () {
    const editor = tinymce.get('editor');
    if (editor) {
        editor.setContent(aiDescLastGenerated);
    } else {
        document.getElementById('editor').value = aiDescLastGenerated;
    }
    aiDescModal.hide();
});

document.getElementById('btnUseOriginalDesc').addEventListener('click', function () {
    const editor = tinymce.get('editor');
    if (editor) {
        editor.setContent(aiDescOriginalContent);
    } else {
        document.getElementById('editor').value = aiDescOriginalContent;
    }
    aiDescModal.hide();
});

// ── 네이버 쇼핑 상품 검색 ─────────────────────────────────────────────────────
const naverModal    = new bootstrap.Modal(document.getElementById('naverSearchModal'));
let   naverPage     = 1;
let   naverKeyword  = '';

document.getElementById('btnNaverSearch').addEventListener('click', function () {
    document.getElementById('naverKeyword').value = document.querySelector('input[name="name"]').value.trim();
    document.getElementById('naverSearchResults').innerHTML = '';
    document.getElementById('naverSearchMore').classList.add('d-none');
    document.getElementById('naverSearchStatus').classList.add('d-none');
    naverModal.show();
});

document.getElementById('btnNaverSearchExec').addEventListener('click', function () {
    naverPage    = 1;
    naverKeyword = document.getElementById('naverKeyword').value.trim();
    document.getElementById('naverSearchResults').innerHTML = '';
    document.getElementById('naverSearchMore').classList.add('d-none');
    execNaverSearch(false);
});

document.getElementById('naverKeyword').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btnNaverSearchExec').click(); }
});

document.getElementById('btnNaverSearchMore').addEventListener('click', function () {
    naverPage++;
    execNaverSearch(true);
});

async function execNaverSearch(append) {
    const status = document.getElementById('naverSearchStatus');
    const btn    = document.getElementById('btnNaverSearchExec');

    if (!naverKeyword) {
        status.textContent = '검색어를 입력해주세요.';
        status.className   = 'text-danger small mb-2';
        status.classList.remove('d-none');
        return;
    }

    btn.disabled    = true;
    status.textContent = '검색 중…';
    status.className   = 'text-muted small mb-2';
    status.classList.remove('d-none');

    try {
        const res  = await fetch('/admin/products/naver-search?' + new URLSearchParams({q: naverKeyword, page: naverPage}), {
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        });
        const data = await res.json();

        if (data.error) {
            const alertClass = data.setup_msg ? 'alert-warning' : 'alert-danger';
            const icon       = data.setup_msg ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill';
            const extra      = data.setup_msg
                ? `<a href="${data.setup_url}" target="_blank" class="alert-link ms-1">설정 바로가기 →</a>`
                : '';
            document.getElementById('naverSearchResults').innerHTML =
                `<div class="col-12"><div class="alert ${alertClass} mb-0">
                    <i class="bi ${icon} me-2"></i>${data.setup_msg || data.error}${extra}
                </div></div>`;
            status.textContent = data.error;
            status.className   = 'text-danger small mb-2';
            return;
        }

        const container = document.getElementById('naverSearchResults');
        if (!append) container.innerHTML = '';

        if (!data.items || data.items.length === 0) {
            status.textContent = '검색 결과가 없습니다.';
            status.className   = 'text-muted small mb-2';
            return;
        }

        data.items.forEach(function (item) {
            const price = parseInt(item.lprice, 10);
            const col   = document.createElement('div');
            col.className = 'col-6 col-md-4 col-lg-3';
            col.innerHTML = `
                <div class="card h-100 border naver-item" style="cursor:pointer"
                     data-title="${item.title.replace(/"/g,'&quot;')}"
                     data-price="${price}"
                     data-image="${item.image.replace(/"/g,'&quot;')}">
                    <img src="${item.image}" class="card-img-top" style="height:140px;object-fit:cover"
                         onerror="this.src='/favicon.ico'">
                    <div class="card-body p-2">
                        <div class="small fw-semibold lh-sm mb-1" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                            ${item.title}
                        </div>
                        <div class="text-danger fw-bold small">${price ? price.toLocaleString() + '원' : '가격 미정'}</div>
                        <div class="text-muted" style="font-size:0.7rem">${item.mallName || item.brand}</div>
                    </div>
                    <div class="card-footer p-1 text-center">
                        <button type="button" class="btn btn-sm btn-success w-100 btn-naver-apply">적용</button>
                    </div>
                </div>`;
            container.appendChild(col);
        });

        status.textContent = `총 ${data.total.toLocaleString()}건 중 ${naverPage * 10}건 표시`;
        status.className   = 'text-muted small mb-2';

        const moreBtn = document.getElementById('naverSearchMore');
        if (data.total > naverPage * 10) {
            moreBtn.classList.remove('d-none');
        } else {
            moreBtn.classList.add('d-none');
        }

    } catch (e) {
        status.textContent = '네트워크 오류가 발생했습니다.';
        status.className   = 'text-danger small mb-2';
    } finally {
        btn.disabled = false;
    }
}

document.getElementById('naverSearchResults').addEventListener('click', async function (e) {
    const applyBtn = e.target.closest('.btn-naver-apply');
    if (!applyBtn) return;

    const card     = applyBtn.closest('.naver-item');
    const title    = card.dataset.title;
    const price    = parseInt(card.dataset.price, 10);
    const imageUrl = card.dataset.image;

    // 상품명·가격 채우기
    document.querySelector('input[name="name"]').value = title;
    if (price > 0) document.querySelector('input[name="price"]').value = price;
    document.querySelector('input[name="name"]').dispatchEvent(new Event('input'));

    naverModal.hide();

    // 이미지 임포트
    if (imageUrl) {
        const preview   = document.getElementById('naverImportedImages');
        const tempId    = 'naverImg_' + Date.now();
        const col       = document.createElement('div');
        col.className   = 'col-6';
        col.id          = tempId;
        col.innerHTML   = `<div class="position-relative">
            <div class="d-flex align-items-center justify-content-center bg-light border rounded"
                 style="width:100%;aspect-ratio:1">
                <div class="spinner-border spinner-border-sm text-secondary"></div>
            </div>
            <div class="form-text text-center small mt-1">이미지 가져오는 중…</div>
        </div>`;
        preview.appendChild(col);

        try {
            const res  = await fetch('/admin/products/import-image', {
                method : 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                body   : new URLSearchParams({'<?= csrf_token() ?>': '<?= csrf_hash() ?>', url: imageUrl}),
            });
            const data = await res.json();

            if (data.success) {
                col.innerHTML = `<div class="position-relative">
                    <img src="${data.url}" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px;border:2px solid #198754">
                    <span class="badge bg-success position-absolute" style="top:4px;left:4px">네이버</span>
                    <button type="button" class="btn btn-danger btn-sm position-absolute"
                            style="top:4px;right:4px;padding:2px 6px"
                            onclick="this.closest('.col-6').remove()">
                        <i class="bi bi-x"></i>
                    </button>
                    <input type="hidden" name="imported_media_ids[]" value="${data.media_id}" form="productForm">
                </div>
                <div class="form-text text-center small mt-1 text-success">이미지 저장 완료</div>`;
            } else {
                col.innerHTML = `<div class="alert alert-warning p-2 small mb-0">이미지 가져오기 실패<br><button type="button" class="btn btn-sm btn-link p-0" onclick="this.closest('.col-6').remove()">닫기</button></div>`;
            }
        } catch (e) {
            col.remove();
        }
    }
});

// ── AI 이미지 분석 (Vision) ───────────────────────────────────────────────────
document.getElementById('btnVisionExtract').addEventListener('click', async function () {
    const fileInput = document.getElementById('visionFile');
    const msg       = document.getElementById('visionMsg');
    const file      = fileInput.files[0];

    if (! file) { alert('분석할 이미지를 선택해주세요.'); return; }
    if (file.size > 5 * 1024 * 1024) { alert('이미지 용량은 5MB 이하만 가능합니다.'); return; }

    this.disabled = true;
    const original = this.innerHTML;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>분석 중…';
    msg.classList.add('d-none');

    try {
        const fd = new FormData();
        fd.append('image', file);
        fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

        const res  = await fetch('/admin/products/extract-from-image', { method: 'POST', body: fd });
        const data = await res.json();

        if (! res.ok || data.error) {
            if (data.setup_url && confirm((data.error || '오류') + '\n설정 화면으로 이동할까요?')) {
                location.href = data.setup_url;
            } else {
                alert(data.error || 'AI 이미지 분석에 실패했습니다.');
            }
            return;
        }

        const nameInput = document.querySelector('input[name="name"]');
        if (data.name) {
            nameInput.value = data.name;
            nameInput.dispatchEvent(new Event('input'));
        }
        if (data.description) {
            const editor = tinymce.get('editor');
            if (editor) {
                editor.setContent(data.description);
            } else {
                document.querySelector('textarea[name="description"]').value = data.description;
            }
        }
        msg.textContent = '상품명·설명을 채웠습니다. 내용을 확인 후 저장하세요.';
        msg.classList.remove('d-none');
    } catch (e) {
        alert('요청 중 오류가 발생했습니다.');
    } finally {
        this.disabled = false;
        this.innerHTML = original;
    }
});
</script>
<?= $this->endSection() ?>
