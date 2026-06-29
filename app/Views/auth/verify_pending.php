<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container">
<div class="row justify-content-center my-5">
    <div class="col-sm-6 col-md-5">
        <div class="card text-center">
            <div class="card-body p-5">
                <i class="bi bi-envelope-check fs-1 text-primary d-block mb-3"></i>
                <h5 class="fw-bold mb-2">이메일을 확인해주세요</h5>
                <p class="text-muted mb-1">
                    <strong><?= esc(session('verify_email') ?? '') ?></strong>으로<br>
                    인증 메일을 발송했습니다.
                </p>
                <p class="text-muted small mb-4">
                    메일 내 인증 링크는 <strong>24시간</strong> 동안 유효합니다.<br>
                    스팸함도 확인해보세요.
                </p>

                <?php if (session('resend_success')): ?>
                <div class="alert alert-success py-2 small">인증 메일을 다시 발송했습니다.</div>
                <?php endif; ?>

                <hr class="my-3">
                <p class="small text-muted mb-2">메일을 받지 못하셨나요?</p>
                <form method="post" action="/auth/resend">
                    <?= csrf_field() ?>
                    <input type="hidden" name="email" value="<?= esc(session('verify_email') ?? '') ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-repeat me-1"></i>인증 메일 재발송
                    </button>
                </form>

                <div class="mt-3">
                    <a href="/auth/login" class="text-decoration-none small text-muted">로그인 페이지로</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?= $this->endSection() ?>
