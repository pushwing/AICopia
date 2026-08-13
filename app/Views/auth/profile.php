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
$canChangePassword = empty($user['social_provider']);

$showPasswordTab = $canChangePassword && $activeTab === 'password';
$showWithdrawTab = $activeTab === 'withdraw';
$showInfoTab     = ! $showPasswordTab && ! $showWithdrawTab;
?>

<div class="container py-4" style="max-width:1100px">
<div class="row g-4">

<div class="col-lg-3 min-w-0">
    <?= $this->include('components/mypage_sidebar') ?>
</div>

<div class="col-lg-9 min-w-0">
    <div class="d-flex align-items-center gap-3 mb-4">
        <h5 class="fw-bold mb-0"><i class="bi bi-person-circle me-2"></i>내 정보 수정</h5>
        <span class="badge <?= GradeService::BADGE_CLASSES[$grade] ?> fs-6">
            <i class="bi <?= GradeService::ICONS[$grade] ?> me-1"></i><?= GradeService::LABELS[$grade] ?>
        </span>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $showInfoTab ? 'active' : '' ?>" href="/auth/profile">기본 정보</a>
        </li>
        <?php if ($canChangePassword): ?>
        <li class="nav-item">
            <a class="nav-link <?= $showPasswordTab ? 'active' : '' ?>" href="/auth/profile?tab=password">비밀번호 변경</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link text-danger <?= $showWithdrawTab ? 'active' : '' ?>" href="/auth/profile?tab=withdraw">회원 탈퇴</a>
        </li>
    </ul>

    <?php if ($showInfoTab): ?>
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

    <?php elseif ($showPasswordTab): ?>
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

    <?php if ($showWithdrawTab): ?>
    <!-- ── 회원 탈퇴 탭 ── -->
    <div class="card border-danger">
        <div class="card-body">
            <?php if (! ($withdrawal['allowed'] ?? false)): ?>
                <div class="alert alert-warning mb-0">
                    <h6 class="fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>지금은 탈퇴할 수 없습니다</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach (($withdrawal['reasons'] ?? []) as $reason): ?>
                        <li><?= esc($reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <h6 class="fw-bold"><i class="bi bi-exclamation-octagon me-2"></i>탈퇴 시 아래 항목이 소멸됩니다</h6>
                    <ul class="mb-0 ps-3">
                        <li>보유 포인트 <strong><?= esc(number_format($forfeit['point'])) ?>점</strong></li>
                        <li>미사용 쿠폰 <strong><?= esc((string) $forfeit['coupon']) ?>장</strong></li>
                        <li>장바구니 · 찜 목록 · 저장된 배송지</li>
                    </ul>
                    <hr>
                    <p class="mb-0 small">
                        주문 내역은 전자상거래법에 따라 5년간 보관되며, 회원정보는 일정 기간 후 파기됩니다.
                        탈퇴 후에는 복구할 수 없습니다.
                    </p>
                </div>

                <form method="post" action="/auth/withdraw">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">탈퇴 사유</label>
                        <?php foreach (\App\Libraries\WithdrawalService::REASON_CODES as $code => $label): ?>
                            <?php if ($code === 'admin') { continue; } ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reason_code"
                                       id="reason_<?= esc($code) ?>" value="<?= esc($code) ?>"
                                       <?= $code === 'unused' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="reason_<?= esc($code) ?>"><?= esc($label) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="reason_text">상세 사유 (선택)</label>
                        <textarea class="form-control" id="reason_text" name="reason_text" rows="3"
                                  maxlength="500" placeholder="개선에 참고하겠습니다."></textarea>
                    </div>

                    <?php if (empty($user['social_provider'])): ?>
                    <div class="mb-3">
                        <label class="form-label" for="withdraw_password">비밀번호 확인</label>
                        <input type="password" class="form-control" id="withdraw_password" name="password" required>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_text">확인을 위해 <strong>탈퇴합니다</strong> 를 입력해 주세요</label>
                        <input type="text" class="form-control" id="confirm_text" name="confirm_text" required>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('정말 탈퇴하시겠습니까? 복구할 수 없습니다.')">
                        회원 탈퇴
                    </button>
                    <a href="/auth/profile" class="btn btn-outline-secondary">취소</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div>
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
