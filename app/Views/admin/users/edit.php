<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '회원 수정' ?>
<?php use App\Libraries\GradeService; ?>

<?= $this->section('content') ?>

<div class="mb-2"><a href="/admin/users" class="text-muted small"><i class="bi bi-arrow-left"></i> 목록</a></div>

<?php $isUnverified = ! $member['is_active'] && ! empty($member['email_verify_token']); ?>

<div class="card" style="max-width:560px">
    <div class="card-header bg-white"><strong>회원 정보 수정</strong></div>
    <div class="card-body">

        <div class="row mb-3">
            <div class="col-6">
                <label class="form-label small fw-bold">이메일</label>
                <p class="form-control-plaintext"><?= esc($member['email']) ?></p>
            </div>
            <div class="col-6">
                <label class="form-label small fw-bold">가입 방식</label>
                <p class="form-control-plaintext">
                    <?= $member['social_provider'] ? esc($member['social_provider']) . ' 소셜 로그인' : '이메일' ?>
                </p>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">이메일 인증</label>
            <div class="d-flex align-items-center gap-2">
                <?php if ($member['is_active'] && empty($member['email_verify_token'])): ?>
                    <span class="badge bg-success">인증 완료</span>
                <?php elseif ($isUnverified): ?>
                    <span class="badge bg-warning text-dark">미인증</span>
                    <?php if ($member['email_verify_token_at']): ?>
                        <span class="text-muted small">
                            토큰 발급: <?= date('Y-n-j H:i', strtotime($member['email_verify_token_at'])) ?>
                        </span>
                    <?php endif; ?>
                    <form method="post" action="/admin/users/<?= $member['id'] ?>/verify" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-success"
                                onclick="return confirm('이메일 인증을 수동으로 완료 처리하시겠습니까?')">
                            수동 인증 처리
                        </button>
                    </form>
                    <form method="post" action="/admin/users/<?= $member['id'] ?>/resend-verify" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-warning">인증 메일 재발송</button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-secondary">해당 없음 (소셜 로그인)</span>
                <?php endif; ?>
            </div>
        </div>

        <hr class="my-3">

        <form method="post" action="/admin/users/<?= $member['id'] ?>/edit">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label small">닉네임</label>
                <input type="text" name="nickname" class="form-control form-control-sm"
                       value="<?= esc($member['nickname']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label small">휴대폰번호</label>
                <input type="text" name="phone" class="form-control form-control-sm"
                       placeholder="010-0000-0000" value="<?= esc($member['phone'] ?? '') ?>">
            </div>

            <div class="row mb-3">
                <div class="col-6">
                    <label class="form-label small">성별</label>
                    <select name="gender" class="form-select form-select-sm">
                        <option value="">미지정</option>
                        <option value="M" <?= ($member['gender'] ?? '') === 'M' ? 'selected' : '' ?>>남성</option>
                        <option value="F" <?= ($member['gender'] ?? '') === 'F' ? 'selected' : '' ?>>여성</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small">생일</label>
                    <input type="date" name="birthday" class="form-control form-control-sm"
                           value="<?= esc($member['birthday'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small">역할</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>일반회원</option>
                    <option value="admin"  <?= $member['role'] === 'admin'  ? 'selected' : '' ?>>관리자</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small">회원 등급</label>
                <?php $curGrade = $member['grade'] ?? 'bronze'; ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge <?= GradeService::BADGE_CLASSES[$curGrade] ?>">
                        <i class="bi <?= GradeService::ICONS[$curGrade] ?> me-1"></i><?= GradeService::LABELS[$curGrade] ?>
                    </span>
                    <span class="text-muted small">현재 등급</span>
                </div>
                <select name="grade" class="form-select form-select-sm">
                    <?php foreach (GradeService::LABELS as $gk => $gl): ?>
                    <option value="<?= $gk ?>" <?= $curGrade === $gk ? 'selected' : '' ?>><?= esc($gl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">플래티넘은 <a href="/admin/grade/platinum">플래티넘 승급 관리</a> 화면에서도 처리할 수 있습니다.</div>
            </div>

            <div class="mb-4">
                <label class="form-label small">상태</label>
                <select name="is_active" class="form-select form-select-sm">
                    <option value="1" <?= $member['is_active'] ? 'selected' : '' ?>>활성</option>
                    <option value="0" <?= ! $member['is_active'] ? 'selected' : '' ?>>비활성 (로그인 차단)</option>
                </select>
                <?php if ($isUnverified): ?>
                    <div class="form-text text-warning">※ 상태를 '활성'으로 저장하면 이메일 인증 토큰도 함께 초기화됩니다.</div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 justify-content-end">
                <a href="/admin/users" class="btn btn-outline-secondary btn-sm">취소</a>
                <button type="submit" class="btn btn-primary btn-sm">저장</button>
            </div>
        </form>
    </div>
</div>

<!-- 회원 활동 탭 -->
<div class="mt-4" style="max-width:800px">
    <ul class="nav nav-tabs" id="memberTabs">
        <li class="nav-item">
            <button class="nav-link active" data-tab="orders">주문 내역</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-tab="points">포인트 내역</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-tab="coupons">보유 쿠폰</button>
        </li>
    </ul>
    <div class="border border-top-0 rounded-bottom p-3 bg-white" id="tabContent">
        <div class="text-muted small text-center py-3">탭을 클릭하면 데이터를 불러옵니다.</div>
    </div>
</div>

<?= $this->section('scripts') ?>
<script>
(function () {
    var memberId  = <?= (int) $member['id'] ?>;
    var loadedTab = null;

    function renderOrders(data) {
        if (!data.length) return '<p class="text-muted small mb-0">주문 내역이 없습니다.</p>';
        var STATUS = {paid:'결제완료',preparing:'배송준비',shipped:'배송중',delivered:'배송완료',
                      cancelled:'취소',awaiting_payment:'입금대기',pending:'주문대기',expired:'만료',
                      refund_requested:'환불요청',refunded:'환불완료',return_requested:'반품요청',return_approved:'반품승인'};
        var html = '<table class="table table-sm small mb-0"><thead><tr>'
                 + '<th>주문번호</th><th>상태</th><th>결제금액</th><th>주문일</th></tr></thead><tbody>';
        data.forEach(function (o) {
            html += '<tr><td><a href="/admin/orders/' + o.id + '" class="text-decoration-none">' + o.order_number + '</a></td>'
                  + '<td>' + (STATUS[o.status] || o.status) + '</td>'
                  + '<td>' + parseInt(o.payable_amount).toLocaleString() + '원</td>'
                  + '<td>' + o.created_at.slice(0, 10) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function renderPoints(res) {
        var TYPE = {earn:'적립', use:'사용', refund:'환불', cancel:'취소', admin:'관리자'};
        var html = '<p class="small mb-2">현재 잔액: <strong>' + (res.balance || 0).toLocaleString() + 'P</strong></p>';
        if (!res.data.length) return html + '<p class="text-muted small mb-0">포인트 내역이 없습니다.</p>';
        html += '<table class="table table-sm small mb-0"><thead><tr>'
              + '<th>구분</th><th>금액</th><th>내용</th><th>날짜</th></tr></thead><tbody>';
        res.data.forEach(function (p) {
            var sign = p.amount > 0 ? '+' : '';
            html += '<tr><td>' + (TYPE[p.type] || p.type) + '</td>'
                  + '<td class="' + (p.amount > 0 ? 'text-success' : 'text-danger') + '">'
                  + sign + parseInt(p.amount).toLocaleString() + 'P</td>'
                  + '<td>' + (p.note || '') + '</td>'
                  + '<td>' + p.created_at.slice(0, 10) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function renderCoupons(data) {
        if (!data.length) return '<p class="text-muted small mb-0">보유 쿠폰이 없습니다.</p>';
        var html = '<table class="table table-sm small mb-0"><thead><tr>'
                 + '<th>쿠폰명</th><th>코드</th><th>사용</th><th>만료일</th></tr></thead><tbody>';
        data.forEach(function (c) {
            html += '<tr><td>' + c.name + '</td><td><code>' + c.code + '</code></td>'
                  + '<td>' + (c.status === 'used' ? '<span class="text-muted">사용됨</span>' : c.status === 'expired' ? '<span class="text-danger">만료</span>' : '<span class="text-success">미사용</span>') + '</td>'
                  + '<td>' + (c.expires_at ? c.expires_at.slice(0, 10) : '—') + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function loadTab(tab) {
        if (loadedTab === tab) return;
        loadedTab = tab;
        document.getElementById('tabContent').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';

        fetch('/admin/users/' + memberId + '/tab/' + tab)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var html = '';
                if (tab === 'orders')  html = renderOrders(res.data);
                if (tab === 'points')  html = renderPoints(res);
                if (tab === 'coupons') html = renderCoupons(res.data);
                document.getElementById('tabContent').innerHTML = html;
            })
            .catch(function () {
                document.getElementById('tabContent').innerHTML = '<p class="text-danger small">데이터를 불러오지 못했습니다.</p>';
            });
    }

    document.querySelectorAll('#memberTabs .nav-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#memberTabs .nav-link').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            loadTab(btn.dataset.tab);
        });
    });

    // 기본 탭 자동 로드
    loadTab('orders');
})();
</script>
<?= $this->endSection() ?>

<?= $this->endSection() ?>
