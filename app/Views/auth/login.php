<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<div class="container">
<div class="row justify-content-center my-5">
    <div class="col-sm-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h5 class="mb-4 fw-bold">로그인</h5>

                <?php if (session()->has('error')): ?>
                <div class="alert alert-danger py-2 small">
                    <?= esc(session('error')) ?>
                    <?php if (session()->has('unverified_email')): ?>
                    <form method="post" action="/auth/resend" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="email" value="<?= esc(session('unverified_email')) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-arrow-repeat me-1"></i>인증 메일 재발송
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 일반 로그인 -->
                <form method="post" action="/auth/login">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="이메일"
                               value="<?= old('email') ?>" required>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="비밀번호" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">로그인</button>
                </form>

                <?php
                $oauthNaver  = ($settings['oauth_enabled_naver']  ?? '1') === '1';
                $oauthKakao  = ($settings['oauth_enabled_kakao']  ?? '1') === '1';
                $oauthGoogle = ($settings['oauth_enabled_google'] ?? '1') === '1';
                $hasOauth    = $oauthNaver || $oauthKakao || $oauthGoogle;
                ?>

                <?php if ($hasOauth): ?>
                <!-- 소셜 로그인 구분선 -->
                <div class="d-flex align-items-center my-4">
                    <hr class="flex-grow-1">
                    <span class="px-3 text-muted small">또는 소셜 계정으로 로그인</span>
                    <hr class="flex-grow-1">
                </div>

                <!-- 소셜 로그인 버튼 -->
                <div class="d-flex flex-column gap-2">

                    <?php if ($oauthNaver): ?>
                    <!-- 네이버 -->
                    <a href="/auth/social/naver" class="btn d-flex align-items-center justify-content-center gap-2 fw-semibold"
                       style="background:#03C75A; color:#fff; border:none;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="white">
                            <path d="M16.273 12.845L7.376 0H0v24h7.727V11.155L16.624 24H24V0h-7.727z"/>
                        </svg>
                        네이버로 로그인
                    </a>
                    <?php endif; ?>

                    <?php if ($oauthKakao): ?>
                    <!-- 카카오 -->
                    <a href="/auth/social/kakao" class="btn d-flex align-items-center justify-content-center gap-2 fw-semibold"
                       style="background:#FEE500; color:#191919; border:none;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="#191919">
                            <path d="M12 3C6.477 3 2 6.477 2 10.5c0 2.572 1.563 4.836 3.938 6.188l-.938 3.5 4.063-2.688C9.316 17.828 10.636 18 12 18c5.523 0 10-3.477 10-7.5S17.523 3 12 3z"/>
                        </svg>
                        카카오로 로그인
                    </a>
                    <?php endif; ?>

                    <?php if ($oauthGoogle): ?>
                    <!-- 구글 -->
                    <a href="/auth/social/google" class="btn d-flex align-items-center justify-content-center gap-2 fw-semibold"
                       style="background:#fff; color:#3c4043; border:1px solid #dadce0;">
                        <svg width="18" height="18" viewBox="0 0 24 24">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Google로 로그인
                    </a>
                    <?php endif; ?>

                </div>
                <?php endif; ?>

                <div class="text-center mt-4 small">
                    <a href="/auth/register" class="text-decoration-none">회원가입</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?= $this->endSection() ?>
