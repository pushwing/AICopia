<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
use App\Libraries\GradeService;
$providerLabel = match($user['social_provider'] ?? null) {
    'google' => '구글',
    'kakao'  => '카카오',
    'naver'  => '네이버',
    default  => null,
};
$grade = $user['grade'] ?? 'bronze';

// 소셜 로그인 계정은 비밀번호가 없어 '비밀번호 변경' 탭을 제공하지 않는다.
// 그러면 탭이 '기본 정보' 하나만 남는데, 탭 하나짜리 탭 바는 아무 정보도 주지
// 못한 채 카드와 떨어진 빈 상자처럼 보이므로 탭 바 자체를 그리지 않는다.
$canChangePassword = empty($user['social_provider']);

// 소셜 계정이 /auth/profile?tab=password 로 직접 들어와도 쓸 수 없는 폼 대신
// 기본 정보를 보여준다(POST 는 AuthController::profileUpdate() 에서 이미 차단).
$showPasswordTab = $canChangePassword && $activeTab === 'password';
?>

<div class="container py-4" style="max-width:640px">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h5 class="fw-bold mb-0"><i class="bi bi-person-circle me-2"></i>내 정보 수정</h5>
        <span class="badge <?= GradeService::BADGE_CLASSES[$grade] ?> fs-6">
            <i class="bi <?= GradeService::ICONS[$grade] ?> me-1"></i><?= GradeService::LABELS[$grade] ?>
        </span>
    </div>

    <?php if ($canChangePassword): ?>
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $showPasswordTab ? '' : 'active' ?>" href="/auth/profile">기본 정보</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $showPasswordTab ? 'active' : '' ?>" href="/auth/profile?tab=password">비밀번호 변경</a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (! $showPasswordTab): ?>
    <!-- ── 기본 정보 탭 ── -->
    <div class="card">
        <div class="card-body">
            <form method="post" action="/auth/profile">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="info">

                <div class="mb-3">
                    <label class="form-label">이메일</label>
                    <input type="text" class="form-control" value="<?= esc($user['email']) ?>" disabled>
                    <?php if ($providerLabel): ?>
                    <div class="form-text">
                        <i class="bi bi-link-45deg"></i> <?= $providerLabel ?> 소셜 로그인 연동 계정
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label">닉네임 <span class="text-danger">*</span></label>
                    <input type="text" name="nickname" class="form-control"
                           value="<?= esc(old('nickname', $user['nickname'])) ?>"
                           required minlength="2" maxlength="20">
                </div>

                <div class="mb-3">
                    <label class="form-label">휴대폰번호 <span class="text-danger">*</span></label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= esc(old('phone', $user['phone'] ?? '')) ?>"
                           required maxlength="20" placeholder="01012345678">
                </div>

                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="form-label">성별</label>
                        <select name="gender" class="form-select">
                            <option value="">선택 안함</option>
                            <option value="M" <?= old('gender', $user['gender'] ?? '') === 'M' ? 'selected' : '' ?>>남성</option>
                            <option value="F" <?= old('gender', $user['gender'] ?? '') === 'F' ? 'selected' : '' ?>>여성</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">생년월일</label>
                        <input type="date" name="birthday" class="form-control"
                               value="<?= esc(old('birthday', $user['birthday'] ?? '')) ?>"
                               max="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="mb-3 text-muted small">
                    <i class="bi bi-clock me-1"></i>가입일: <?= date('Y년 n월 j일', strtotime($user['created_at'])) ?>
                    <?php if ($user['last_login']): ?>
                    &nbsp;·&nbsp; 최근 로그인: <?= substr($user['last_login'], 0, 16) ?>
                    <?php endif; ?>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── 비밀번호 변경 탭 ── -->
    <div class="card">
        <div class="card-body">
            <form method="post" action="/auth/profile">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="password">

                <div class="mb-3">
                    <label class="form-label">현재 비밀번호 <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                </div>

                <div class="mb-3">
                    <label class="form-label">새 비밀번호 <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" id="new_password" class="form-control"
                           required minlength="8" autocomplete="new-password">
                    <div class="form-text">8자 이상</div>
                </div>

                <div class="mb-4">
                    <label class="form-label">새 비밀번호 확인 <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                           required autocomplete="new-password">
                    <div id="pw-match" class="form-text"></div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm px-4">변경</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
// 비밀번호 확인 실시간 체크
const np = document.getElementById('new_password');
const cp = document.getElementById('confirm_password');
if (cp) {
    cp.addEventListener('input', function () {
        const msg = document.getElementById('pw-match');
        if (! this.value) { msg.textContent = ''; return; }
        if (this.value === np.value) {
            msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> 비밀번호가 일치합니다.</span>';
        } else {
            msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> 비밀번호가 일치하지 않습니다.</span>';
        }
    });
}
</script>
<?= $this->endSection() ?>
