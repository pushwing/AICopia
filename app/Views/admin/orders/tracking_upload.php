<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '송장 일괄 등록' ?>

<?= $this->section('content') ?>

<div class="row justify-content-center">
<div class="col-lg-8">

<?php if (isset($uploadError)): ?>
<div class="alert alert-danger"><?= esc($uploadError) ?></div>
<?php endif; ?>

<?php if (isset($results)): ?>
<!-- 처리 결과 -->
<div class="card mb-4">
    <div class="card-header fw-semibold">처리 결과</div>
    <div class="card-body">
        <div class="d-flex gap-4 mb-3">
            <div class="text-center">
                <div class="fs-4 fw-bold text-success"><?= $results['success'] ?></div>
                <div class="small text-muted">성공</div>
            </div>
            <div class="text-center">
                <div class="fs-4 fw-bold text-secondary"><?= $results['skipped'] ?></div>
                <div class="small text-muted">건너뜀</div>
            </div>
            <div class="text-center">
                <div class="fs-4 fw-bold text-danger"><?= count($results['errors']) ?></div>
                <div class="small text-muted">오류</div>
            </div>
        </div>

        <?php if (! empty($results['errors'])): ?>
        <h6 class="text-danger mb-2">오류 행 목록</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px">행</th>
                        <th>내용</th>
                        <th>사유</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['errors'] as $err): ?>
                    <tr>
                        <td class="text-center"><?= (int) $err['line'] ?></td>
                        <td><code class="small"><?= esc($err['raw']) ?></code></td>
                        <td class="text-danger small"><?= esc($err['reason']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 업로드 폼 -->
<div class="card">
    <div class="card-header fw-semibold">CSV 파일 업로드</div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            주문번호, 배송업체, 송장번호가 담긴 CSV 파일을 업로드하면 일괄 등록됩니다.<br>
            첫 번째 행이 <code>주문번호</code>로 시작하면 헤더로 인식하여 건너뜁니다.
        </p>

        <!-- 주문 목록 다운로드 -->
        <div class="mb-4 p-3 bg-light rounded border">
            <div class="fw-semibold small mb-2"><i class="bi bi-1-circle me-1"></i>송장 입력이 필요한 주문 다운로드</div>
            <p class="text-muted small mb-2">배송 준비 중인 주문 목록을 CSV로 내려받아 배송업체·송장번호를 채우세요.</p>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/admin/orders/tracking-export" class="btn btn-primary btn-sm">
                    <i class="bi bi-download me-1"></i>전체 다운로드 <span class="badge bg-white text-primary ms-1">결제완료·준비중·배송중</span>
                </a>
                <a href="/admin/orders/tracking-export?status=paid" class="btn btn-outline-secondary btn-sm">결제완료만</a>
                <a href="/admin/orders/tracking-export?status=preparing" class="btn btn-outline-secondary btn-sm">배송준비중만</a>
                <a href="/admin/orders/tracking-export?status=shipped" class="btn btn-outline-secondary btn-sm">배송중만</a>
            </div>
        </div>

        <!-- CSV 업로드 안내 -->
        <div class="fw-semibold small mb-2"><i class="bi bi-2-circle me-1"></i>CSV 파일 업로드</div>
        <div class="mb-3 p-3 bg-light rounded border">
            <div class="small fw-semibold mb-1">CSV 형식 예시</div>
            <pre class="mb-0 small">주문번호,배송업체,송장번호
ORD-20240101-0001,CJ대한통운,123456789012
ORD-20240101-0002,한진택배,987654321098</pre>
        </div>

        <div class="mb-3">
            <a href="/admin/orders/tracking-template" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download me-1"></i>빈 양식 다운로드
            </a>
        </div>

        <form method="post" action="/admin/orders/tracking-upload" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">CSV 파일 선택</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text">UTF-8 또는 Excel(UTF-8 BOM) CSV 형식 지원</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload me-1"></i>업로드 및 등록
                </button>
                <a href="/admin/orders" class="btn btn-outline-secondary">목록으로</a>
            </div>
        </form>
    </div>
</div>

</div>
</div>

<?= $this->endSection() ?>
