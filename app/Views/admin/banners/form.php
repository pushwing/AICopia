<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $banner ? '배너 수정' : '배너 등록' ?>

<?= $this->section('content') ?>

<div class="card" style="max-width:640px">
    <div class="card-body">
        <form method="post"
              action="<?= $banner ? "/admin/banners/{$banner['id']}/edit" : '/admin/banners/create' ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- 이미지 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">배너 이미지 <?= $banner ? '' : '<span class="text-danger">*</span>' ?></label>
                <?php if ($banner): ?>
                <div class="mb-2">
                    <img src="/<?= esc($banner['image_path']) ?>" alt="" style="max-width:300px;max-height:120px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px">
                </div>
                <div class="form-text mb-1">새 파일을 선택하면 교체됩니다.</div>
                <?php endif; ?>
                <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.gif"
                       <?= $banner ? '' : 'required' ?>>
                <div class="form-text">jpg, jpeg, png, gif / 최대 2MB</div>
            </div>

            <!-- 위치 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">위치 <span class="text-danger">*</span></label>
                <select name="position" class="form-select" required>
                    <?php foreach ($positions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= old('position', $banner['position'] ?? '') === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 링크 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">링크 URL</label>
                <input type="url" name="link_url" class="form-control"
                       placeholder="https://example.com"
                       value="<?= esc(old('link_url', $banner['link_url'] ?? '')) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">링크 열기</label>
                <select name="link_target" class="form-select">
                    <option value="_self"  <?= old('link_target', $banner['link_target'] ?? '_self') === '_self'  ? 'selected' : '' ?>>현재 창</option>
                    <option value="_blank" <?= old('link_target', $banner['link_target'] ?? '_self') === '_blank' ? 'selected' : '' ?>>새 창</option>
                </select>
            </div>

            <!-- 우선순위 -->
            <div class="mb-3">
                <label class="form-label fw-semibold">우선순위</label>
                <input type="number" name="priority" class="form-control" style="width:120px"
                       value="<?= esc(old('priority', $banner['priority'] ?? 0)) ?>" min="0">
                <div class="form-text">숫자가 낮을수록 먼저 표시됩니다.</div>
            </div>

            <!-- 운영 기간 -->
            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-semibold">시작일</label>
                    <?php
                    $startedVal = '';
                    if (!empty($banner['started_at'])) {
                        $startedVal = str_replace(' ', 'T', substr($banner['started_at'], 0, 16));
                    }
                    ?>
                    <input type="datetime-local" name="started_at" class="form-control"
                           value="<?= esc(old('started_at', $startedVal)) ?>">
                </div>
                <div class="col">
                    <label class="form-label fw-semibold">종료일</label>
                    <?php
                    $endedVal = '';
                    if (!empty($banner['ended_at'])) {
                        $endedVal = str_replace(' ', 'T', substr($banner['ended_at'], 0, 16));
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
                           <?= old('is_active', $banner['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">운영 중</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $banner ? '저장' : '등록' ?></button>
                <a href="/admin/banners" class="btn btn-outline-secondary">취소</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
