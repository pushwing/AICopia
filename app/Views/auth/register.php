<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container">
<div class="row justify-content-center my-5">
    <div class="col-sm-6 col-md-5">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="mb-4">회원가입</h5>

                <?php if (session()->has('errors')): ?>
                <div class="alert alert-danger">
                    <?php foreach (session('errors') as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="post" action="/auth/register">
                    <?= csrf_field() ?>

                    <!-- 기본 정보 -->
                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="이메일 *"
                               value="<?= old('email') ?>" required>
                    </div>
                    <div class="mb-3">
                        <input type="text" name="nickname" class="form-control" placeholder="닉네임 *"
                               value="<?= old('nickname') ?>" required minlength="2" maxlength="20">
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control"
                               placeholder="비밀번호 (8자 이상) *" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <input type="tel" name="phone" class="form-control" placeholder="휴대폰번호 * (예: 01012345678)"
                               value="<?= old('phone') ?>" required maxlength="20">
                    </div>

                    <!-- 선택 정보 -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <select name="gender" class="form-select">
                                <option value="">성별 (선택)</option>
                                <option value="M" <?= old('gender') === 'M' ? 'selected' : '' ?>>남성</option>
                                <option value="F" <?= old('gender') === 'F' ? 'selected' : '' ?>>여성</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <input type="date" name="birthday" class="form-control"
                                   value="<?= old('birthday') ?>" placeholder="생년월일 (선택)"
                                   max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <!-- 주소 (선택) -->
                    <div class="mb-3">
                        <div class="d-flex gap-2 mb-2">
                            <input type="text" id="zipcode" name="zipcode" class="form-control"
                                   placeholder="우편번호 (선택)" value="<?= old('zipcode') ?>" readonly>
                            <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap"
                                    onclick="openKakaoPost()">주소 검색</button>
                        </div>
                        <input type="text" id="address1" name="address1" class="form-control mb-2"
                               placeholder="기본 주소" value="<?= old('address1') ?>" readonly>
                        <input type="text" name="address2" class="form-control"
                               placeholder="상세 주소 (동, 호수 등)" value="<?= old('address2') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">가입하기</button>
                </form>

                <div class="text-center mt-3 small">
                    <a href="/auth/login" class="text-decoration-none">이미 계정이 있으신가요? 로그인</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
function openKakaoPost() {
    new daum.Postcode({
        oncomplete: function(data) {
            document.getElementById('zipcode').value  = data.zonecode;
            document.getElementById('address1').value = data.roadAddress || data.jibunAddress;
        }
    }).open();
}
</script>
<?= $this->endSection() ?>
