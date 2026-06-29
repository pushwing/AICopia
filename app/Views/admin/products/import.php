<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">상품 엑셀 일괄 등록</h4>
    <a href="/admin/products" class="btn btn-sm btn-outline-secondary">← 목록</a>
</div>

<!-- 가이드 + 업로드 카드 -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>사용 방법</strong></div>
            <div class="card-body small">
                <ol class="ps-3 mb-0">
                    <li class="mb-1">아래 <strong>템플릿 다운로드</strong> 버튼으로 양식을 받습니다.</li>
                    <li class="mb-1">2행부터 상품 정보를 입력합니다. (1행 헤더 수정 금지)</li>
                    <li class="mb-1">파일을 업로드하면 미리보기가 표시됩니다.</li>
                    <li class="mb-1">오류 행은 제외되고 유효 행만 <strong>등록 확정</strong>됩니다.</li>
                </ol>
                <hr class="my-3">
                <table class="table table-sm table-borderless mb-0">
                    <thead class="table-light"><tr><th>컬럼</th><th>설명</th><th>필수</th></tr></thead>
                    <tbody>
                        <tr><td>상품명</td><td>상품 이름</td><td>✔</td></tr>
                        <tr><td>판매가</td><td>숫자 (원)</td><td>✔</td></tr>
                        <tr><td>재고</td><td>0 이상 정수</td><td>✔</td></tr>
                        <tr><td>상태</td><td>on_sale / sold_out / hidden</td><td></td></tr>
                        <tr><td>배송유형</td><td>free / fixed / conditional</td><td></td></tr>
                        <tr><td>배송비</td><td>고정·조건부일 때 금액</td><td></td></tr>
                        <tr><td>무료배송기준금액</td><td>conditional 일 때 기준금액</td><td></td></tr>
                        <tr><td>할인가</td><td>숫자 (원, 생략 가능)</td><td></td></tr>
                        <tr><td>카테고리</td><td>카테고리 이름 (정확히 일치)</td><td></td></tr>
                        <tr><td>상품설명</td><td>텍스트 (생략 가능)</td><td></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>파일 업로드</strong></div>
            <div class="card-body">
                <a href="/admin/products/import-template"
                   class="btn btn-outline-secondary btn-sm mb-3">
                    <i class="bi bi-download me-1"></i>템플릿 다운로드
                </a>

                <?php if (session()->getFlashdata('error')): ?>
                <div class="alert alert-danger py-2"><?= esc(session()->getFlashdata('error')) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" action="/admin/products/import">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Excel 파일 선택 <span class="text-muted small">(.xlsx, .xls)</span></label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>미리보기
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (! empty($importErrors)): ?>
<!-- 오류 행 -->
<div class="card border-danger border-0 shadow-sm mb-4">
    <div class="card-header bg-danger text-white">
        <strong>오류 행 (<?= count($importErrors) ?>건) — 등록에서 제외됩니다</strong>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:60px">행</th>
                    <th>상품명</th>
                    <th>판매가</th>
                    <th>재고</th>
                    <th>오류 내용</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($importErrors as $err): ?>
            <tr class="table-danger">
                <td><?= $err['row'] ?></td>
                <td><?= esc($err['data'][0] ?? '') ?></td>
                <td><?= esc($err['data'][1] ?? '') ?></td>
                <td><?= esc($err['data'][2] ?? '') ?></td>
                <td class="small text-danger"><?= esc(implode(' / ', $err['messages'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (! empty($preview)): ?>
<!-- 유효 행 미리보기 + 확정 버튼 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>등록 예정 상품 (<?= count($preview) ?>건)</strong>
        <form method="post" action="/admin/products/import/confirm"
              onsubmit="return confirm('<?= count($preview) ?>개 상품을 등록하시겠습니까?')">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-check2-circle me-1"></i>등록 확정
            </button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>상품명</th>
                    <th class="text-end">판매가</th>
                    <th class="text-end">재고</th>
                    <th>상태</th>
                    <th>배송유형</th>
                    <th class="text-end">배송비</th>
                    <th class="text-end">할인가</th>
                    <th>카테고리ID</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview as $i => $row): ?>
            <tr>
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td class="small"><?= esc($row['name']) ?></td>
                <td class="text-end small"><?= number_format($row['price']) ?>원</td>
                <td class="text-end small"><?= number_format($row['stock']) ?></td>
                <td><span class="badge bg-secondary small"><?= esc($row['status']) ?></span></td>
                <td class="small"><?= esc($row['shipping_type']) ?></td>
                <td class="text-end small"><?= $row['shipping_fee'] ? number_format($row['shipping_fee']) . '원' : '-' ?></td>
                <td class="text-end small"><?= $row['discount_price'] ? number_format($row['discount_price']) . '원' : '-' ?></td>
                <td class="small text-muted"><?= $row['category_id'] ?: '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>
