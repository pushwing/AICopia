<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $supplier ? '매입처 수정' : '매입처 등록' ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center mb-4">
    <a href="/admin/suppliers" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0"><?= $pageTitle ?></h4>
</div>

<?php if (session()->has('errors')): ?>
<div class="alert alert-danger mb-3">
    <ul class="mb-0">
        <?php foreach (session('errors') as $e): ?>
        <li><?= esc($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <form method="post"
                      enctype="multipart/form-data"
                      action="<?= $supplier ? "/admin/suppliers/{$supplier['id']}/edit" : '/admin/suppliers/create' ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">매입처명 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= esc(old('name', $supplier['name'] ?? '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">사업자등록번호 <span class="text-danger">*</span></label>
                        <input type="text" name="business_no" class="form-control" required
                               placeholder="000-00-00000" maxlength="20"
                               value="<?= esc(old('business_no', $supplier['business_no'] ?? '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">사업자등록증</label>
                        <?php if (! empty($supplier['business_license_path'])): ?>
                        <div class="mb-2">
                            <a href="<?= esc($supplier['business_license_path']) ?>" target="_blank" class="small text-primary">
                                <i class="bi bi-file-earmark me-1"></i>현재 파일 보기
                            </a>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="business_license" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text">PDF 또는 이미지 (JPG, PNG), 최대 5MB</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">담당자 <span class="text-danger">*</span></label>
                        <input type="text" name="contact_person" class="form-control" required
                               value="<?= esc(old('contact_person', $supplier['contact_person'] ?? '')) ?>">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label">전화번호 <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" required
                                   placeholder="02-0000-0000"
                                   value="<?= esc(old('phone', $supplier['phone'] ?? '')) ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">이메일 <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= esc(old('email', $supplier['email'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">메모</label>
                        <textarea name="memo" class="form-control" rows="3"><?= esc(old('memo', $supplier['memo'] ?? '')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">저장</button>
                        <a href="/admin/suppliers" class="btn btn-outline-secondary">취소</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
