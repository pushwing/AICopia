<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="container py-4" style="max-width:680px">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/mypage/orders" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="fw-bold mb-0">배송지 관리</h5>
    </div>

    <!-- ─── 저장된 배송지 목록 ────────────────────────────────────────────── -->
    <?php if (empty($addresses)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-geo-alt fs-1 d-block mb-3"></i>
        <p>저장된 배송지가 없습니다.</p>
    </div>
    <?php else: ?>
    <div class="d-flex flex-column gap-3 mb-4">
        <?php foreach ($addresses as $addr): ?>
        <div class="card <?= $addr['is_default'] ? 'border-primary' : '' ?>">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="small">
                        <div class="fw-semibold mb-1">
                            <?php if ($addr['is_default']): ?>
                            <span class="badge bg-primary me-1">기본</span>
                            <?php endif; ?>
                            <?= esc($addr['receiver_name']) ?>
                            <span class="text-muted fw-normal ms-2"><?= esc($addr['receiver_phone']) ?></span>
                        </div>
                        <div class="text-muted">
                            (<?= esc($addr['zipcode']) ?>)
                            <?= esc($addr['address1']) ?>
                            <?= ! empty($addr['address2']) ? ' ' . esc($addr['address2']) : '' ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <?php if (! $addr['is_default']): ?>
                        <form method="post" action="/mypage/addresses/<?= (int) $addr['id'] ?>/default">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-primary">기본 설정</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="/mypage/addresses/<?= (int) $addr['id'] ?>/delete"
                              onsubmit="return confirm('이 배송지를 삭제하시겠습니까?')">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">삭제</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ─── 새 배송지 추가 ────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header fw-semibold bg-white">
            <i class="bi bi-plus-circle me-2 text-primary"></i>새 배송지 추가
        </div>
        <div class="card-body">
            <?php if (! empty(session()->getFlashdata('errors'))): ?>
            <div class="alert alert-danger small">
                <?php foreach (session()->getFlashdata('errors') as $e): ?>
                <div><?= esc($e) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="post" action="/mypage/addresses">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold">받는 분 <span class="text-danger">*</span></label>
                        <input type="text" name="receiver_name" class="form-control"
                               placeholder="이름" maxlength="100"
                               value="<?= esc(old('receiver_name')) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold">연락처 <span class="text-danger">*</span></label>
                        <input type="tel" name="receiver_phone" class="form-control"
                               placeholder="010-0000-0000" maxlength="20"
                               value="<?= esc(old('receiver_phone')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">주소 <span class="text-danger">*</span></label>
                        <div class="input-group mb-2">
                            <input type="text" name="zipcode" id="zipcode" class="form-control"
                                   placeholder="우편번호" maxlength="10"
                                   value="<?= esc(old('zipcode')) ?>" readonly required>
                            <button type="button" class="btn btn-outline-secondary" id="btnPostcode">
                                주소 검색
                            </button>
                        </div>
                        <input type="text" name="address1" id="address1" class="form-control mb-2"
                               placeholder="기본 주소"
                               value="<?= esc(old('address1')) ?>" readonly required>
                        <input type="text" name="address2" class="form-control"
                               placeholder="상세 주소 (동, 호수 등)"
                               value="<?= esc(old('address2')) ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">저장</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
document.getElementById('btnPostcode')?.addEventListener('click', function () {
    new daum.Postcode({
        oncomplete: function (data) {
            document.getElementById('zipcode').value  = data.zonecode;
            document.getElementById('address1').value = data.roadAddress || data.jibunAddress;
            document.querySelector('[name=address2]').focus();
        }
    }).open();
});
</script>
<?= $this->endSection() ?>
