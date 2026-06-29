<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $coupon ? '쿠폰 수정' : '쿠폰 등록' ?>

<?= $this->section('content') ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/admin/coupons" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i>
    </a>
    <h4 class="fw-bold mb-0"><?= $coupon ? '쿠폰 수정' : '쿠폰 등록' ?></h4>
</div>


<form method="post" action="/admin/coupons/<?= $coupon ? (int)$coupon['id'] . '/edit' : 'create' ?>">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-body">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">쿠폰명 <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= esc(old('name', $coupon['name'] ?? '')) ?>"
                           maxlength="100" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">쿠폰 코드 <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control font-monospace"
                           value="<?= esc(old('code', $coupon['code'] ?? '')) ?>"
                           maxlength="50" style="text-transform:uppercase"
                           <?= $coupon ? '' : 'placeholder="영문+숫자, 대문자 권장"' ?>
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">할인 종류 <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" id="couponType" required>
                        <?php foreach ($types as $k => $label): ?>
                        <option value="<?= $k ?>" <?= old('type', $coupon['type'] ?? '') === $k ? 'selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold d-block">대상 등급 <small class="text-muted">(미선택=전체)</small></label>
                    <?php
                        use App\Libraries\GradeService;
                        $gradeLabels  = GradeService::LABELS;
                        $gradeBadges  = GradeService::BADGE_CLASSES;
                        $rawGrade     = old('target_grade', $coupon['target_grade'] ?? '');
                        $selGrades    = $rawGrade ? array_map('trim', explode(',', $rawGrade)) : [];
                    ?>
                    <div class="d-flex flex-wrap gap-2 pt-1">
                        <?php foreach ($gradeLabels as $gk => $gl): ?>
                        <div class="form-check form-check-inline m-0">
                            <input class="form-check-input" type="checkbox"
                                   name="target_grade[]" value="<?= $gk ?>"
                                   id="grade_<?= $gk ?>"
                                   <?= in_array($gk, $selGrades, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="grade_<?= $gk ?>">
                                <span class="badge <?= $gradeBadges[$gk] ?>"><?= esc($gl) ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">체크 없으면 전체 등급에 적용됩니다.</div>
                </div>

                <div class="col-md-4" id="discountValueWrap">
                    <label class="form-label small fw-semibold">할인값 <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="discount_value" class="form-control"
                               value="<?= (int) old('discount_value', $coupon['discount_value'] ?? 0) ?>"
                               min="1">
                        <span class="input-group-text" id="discountUnit">원</span>
                    </div>
                </div>

                <div class="col-md-4" id="maxDiscountWrap">
                    <label class="form-label small fw-semibold">최대 할인금액 (0=무제한)</label>
                    <input type="number" name="max_discount_amount" class="form-control"
                           value="<?= (int) old('max_discount_amount', $coupon['max_discount_amount'] ?? 0) ?>"
                           min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">최소 주문금액</label>
                    <input type="number" name="min_order_amount" class="form-control"
                           value="<?= (int) old('min_order_amount', $coupon['min_order_amount'] ?? 0) ?>"
                           min="0">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">총 발급 수량 (미입력=무제한)</label>
                    <input type="number" name="total_qty" class="form-control"
                           value="<?= old('total_qty', $coupon['total_qty'] ?? '') ?>"
                           min="1" placeholder="무제한">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-semibold">1인당 사용 제한 <span class="text-danger">*</span></label>
                    <input type="number" name="per_user_limit" class="form-control"
                           value="<?= (int) old('per_user_limit', $coupon['per_user_limit'] ?? 1) ?>"
                           min="1" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">시작일</label>
                    <input type="datetime-local" name="starts_at" class="form-control"
                           value="<?= esc(old('starts_at', $coupon ? (str_replace(' ', 'T', substr($coupon['starts_at'] ?? '', 0, 16))) : '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label small fw-semibold">만료일</label>
                    <input type="datetime-local" name="expires_at" class="form-control"
                           value="<?= esc(old('expires_at', $coupon ? (str_replace(' ', 'T', substr($coupon['expires_at'] ?? '', 0, 16))) : '')) ?>">
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="isActive"
                               <?= old('is_active', $coupon['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">활성화</label>
                    </div>
                </div>

            </div>
        </div>
        <div class="card-footer bg-white d-flex gap-2">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="/admin/coupons" class="btn btn-outline-secondary">취소</a>
        </div>
    </div>

</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const typeSelect   = document.getElementById('couponType');
    const unitEl       = document.getElementById('discountUnit');
    const maxWrap      = document.getElementById('maxDiscountWrap');
    const discountWrap = document.getElementById('discountValueWrap');

    function toggleType() {
        const v         = typeSelect.value;
        const isFree    = v === 'free_shipping';
        const isPercent = v === 'percent';
        unitEl.textContent        = isPercent ? '%' : '원';
        maxWrap.style.display     = isPercent ? '' : 'none';
        discountWrap.style.display = isFree ? 'none' : '';
    }

    typeSelect.addEventListener('change', toggleType);
    toggleType();
})();
</script>
<?= $this->endSection() ?>
