<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '문의 상세' ?>
<?= $this->section('content') ?>

<?php
$catLabels = ['shipping' => '배송', 'refund' => '환불', 'product' => '상품', 'payment' => '결제', 'etc' => '기타'];
$catColors = ['shipping' => 'info', 'refund' => 'warning', 'product' => 'primary', 'payment' => 'success', 'etc' => 'secondary'];
$priLabels = ['high' => '긴급', 'normal' => '보통', 'low' => '낮음'];
$priColors = ['high' => 'danger', 'normal' => 'secondary', 'low' => 'light'];
$mailtoSubject = 'Re: ' . ($inquiry['subject'] ?: $inquiry['name'] . '님 문의 답장');
?>

<div class="mb-2"><a href="/admin/inquiries" class="text-muted small"><i class="bi bi-arrow-left"></i> 목록</a></div>

<div class="card border-0 shadow-sm" style="max-width:700px">
    <div class="card-body p-4">
        <?php if (! empty($inquiry['category'])): ?>
        <div class="mb-3">
            <span class="badge bg-<?= $catColors[$inquiry['category']] ?? 'secondary' ?>"><?= esc($catLabels[$inquiry['category']] ?? $inquiry['category']) ?></span>
            <?php if (! empty($inquiry['priority'])): ?>
            <span class="badge bg-<?= $priColors[$inquiry['priority']] ?? 'secondary' ?> <?= $inquiry['priority'] === 'low' ? 'text-dark border' : '' ?>">우선순위: <?= esc($priLabels[$inquiry['priority']] ?? $inquiry['priority']) ?></span>
            <?php endif; ?>
            <span class="text-muted small ms-1">AI 자동 분류</span>
        </div>
        <?php endif; ?>
        <table class="table table-borderless small mb-4">
            <tr><th style="width:80px" class="text-muted">이름</th><td><?= esc($inquiry['name']) ?></td></tr>
            <tr><th class="text-muted">이메일</th><td><a href="mailto:<?= esc($inquiry['email']) ?>"><?= esc($inquiry['email']) ?></a></td></tr>
            <?php if ($inquiry['phone']): ?>
            <tr><th class="text-muted">연락처</th><td><?= esc($inquiry['phone']) ?></td></tr>
            <?php endif; ?>
            <?php if ($inquiry['subject']): ?>
            <tr><th class="text-muted">제목</th><td><?= esc($inquiry['subject']) ?></td></tr>
            <?php endif; ?>
            <tr><th class="text-muted">날짜</th><td><?= $inquiry['created_at'] ?></td></tr>
        </table>
        <div class="border rounded p-3 bg-light" style="white-space:pre-wrap"><?= esc($inquiry['message']) ?></div>

        <!-- AI 답변 초안 -->
        <div class="mt-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnSuggestReply">
                <i class="bi bi-stars me-1"></i>AI 답변 초안 생성
            </button>
            <div id="replyDraftWrap" class="mt-2 d-none">
                <textarea id="replyDraft" class="form-control form-control-sm" rows="6"></textarea>
                <div class="mt-2 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCopyReply"><i class="bi bi-clipboard me-1"></i>복사</button>
                    <span class="text-muted small align-self-center">초안은 아래 '이메일로 답장' 본문에도 자동 반영됩니다.</span>
                </div>
            </div>
        </div>

        <div class="mt-3 text-end">
            <a href="mailto:<?= esc($inquiry['email']) ?>?subject=<?= rawurlencode($mailtoSubject) ?>"
               id="mailtoReply" class="btn btn-primary btn-sm">
                <i class="bi bi-reply me-1"></i>이메일로 답장
            </a>
        </div>
    </div>
</div>

<script>
(function () {
    var btn    = document.getElementById('btnSuggestReply');
    var wrap   = document.getElementById('replyDraftWrap');
    var draft  = document.getElementById('replyDraft');
    var mailto = document.getElementById('mailtoReply');
    var baseMail = 'mailto:<?= esc($inquiry['email'], 'js') ?>?subject=' + encodeURIComponent('<?= esc($mailtoSubject, 'js') ?>');
    var csrf   = { name: '<?= csrf_token() ?>', hash: '<?= csrf_hash() ?>' };

    function syncMailto() {
        var body = draft.value.trim();
        mailto.href = baseMail + (body ? '&body=' + encodeURIComponent(body) : '');
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>생성 중…';
        var fd = new FormData();
        fd.append(csrf.name, csrf.hash);
        fetch('/admin/inquiries/<?= (int) $inquiry['id'] ?>/suggest-reply', { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (! res.ok || res.d.error) {
                    alert(res.d.error || 'AI 답변 생성에 실패했습니다.');
                    if (res.d.setup_url) location.href = res.d.setup_url;
                    return;
                }
                wrap.classList.remove('d-none');
                draft.value = res.d.reply;
                syncMailto();
            })
            .catch(function () { alert('요청 중 오류가 발생했습니다.'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-stars me-1"></i>AI 답변 초안 다시 생성';
            });
    });

    draft.addEventListener('input', syncMailto);

    document.getElementById('btnCopyReply').addEventListener('click', function () {
        navigator.clipboard.writeText(draft.value).then(function () {
            var b = document.getElementById('btnCopyReply');
            b.innerHTML = '<i class="bi bi-check2 me-1"></i>복사됨';
            setTimeout(function () { b.innerHTML = '<i class="bi bi-clipboard me-1"></i>복사'; }, 1500);
        });
    });
}());
</script>

<?= $this->endSection() ?>
