<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '상품 문의 관리' ?>
<?= $this->section('content') ?>

<!-- 필터 -->
<form method="get" action="/admin/qna" class="row g-2 mb-3">
    <div class="col-auto">
        <input type="text" name="q" value="<?= esc($keyword) ?>"
               class="form-control" placeholder="제목·작성자·상품명">
    </div>
    <div class="col-auto">
        <select name="answered" class="form-select">
            <option value="">전체</option>
            <option value="0" <?= $answered === '0' ? 'selected' : '' ?>>미답변</option>
            <option value="1" <?= $answered === '1' ? 'selected' : '' ?>>답변완료</option>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary">검색</button>
    </div>
    <?php if ($keyword !== '' || $answered !== ''): ?>
    <div class="col-auto">
        <a href="/admin/qna" class="btn btn-outline-secondary">초기화</a>
    </div>
    <?php endif; ?>
    <div class="col-auto ms-auto">
        <span class="text-muted small">총 <?= number_format($total) ?>건</span>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <table class="table table-hover mb-0 small">
        <thead class="table-light">
            <tr>
                <th style="width:180px">상품</th>
                <th>제목</th>
                <th style="width:90px">작성자</th>
                <th style="width:90px">작성일</th>
                <th style="width:80px">상태</th>
                <th style="width:60px"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $qna):
                $colId    = 'qna-row-' . $qna['id'];
                $name     = $qna['nickname'] ?: $qna['username'];
                $nameLen  = mb_strlen($name);
                $masked   = mb_substr($name, 0, 1) . str_repeat('*', min($nameLen - 1, 2));
            ?>
            <tr data-bs-toggle="collapse" data-bs-target="#<?= $colId ?>"
                style="cursor:pointer"
                class="<?= ! $qna['is_answered'] ? 'fw-semibold' : '' ?>">
                <td class="text-truncate" style="max-width:180px">
                    <?= esc($qna['product_name']) ?>
                </td>
                <td>
                    <?php if ($qna['is_secret']): ?>
                    <i class="bi bi-lock-fill text-secondary me-1" title="비밀글"></i>
                    <?php endif; ?>
                    <?= esc($qna['title']) ?>
                </td>
                <td><?= esc($masked) ?></td>
                <td><?= date('y.m.d', strtotime($qna['created_at'])) ?></td>
                <td>
                    <?php if ($qna['is_answered']): ?>
                    <span class="badge bg-success">답변완료</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">미답변</span>
                    <?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()">
                    <form method="post" action="/admin/qna/<?= $qna['id'] ?>/delete"
                          class="d-inline" onsubmit="return confirm('삭제하시겠습니까?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger">삭제</button>
                    </form>
                </td>
            </tr>
            <tr>
                <td colspan="6" class="p-0 border-0">
                    <div class="collapse" id="<?= $colId ?>">
                        <div class="p-3 bg-light border-bottom">
                            <p class="mb-3" style="white-space:pre-line"><?= esc($qna['content']) ?></p>

                            <?php if ($qna['is_answered']): ?>
                            <div class="border-start border-success border-3 ps-3 pt-1">
                                <div class="small fw-semibold text-success mb-1">답변</div>
                                <p class="mb-1 small" style="white-space:pre-line"><?= esc($qna['answer']) ?></p>
                                <small class="text-muted"><?= date('Y-m-d H:i', strtotime((string) $qna['answered_at'])) ?></small>
                            </div>
                            <?php else: ?>
                            <form method="post" action="/admin/qna/<?= $qna['id'] ?>/answer">
                                <?= csrf_field() ?>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-semibold small mb-0">답변 작성</label>
                                    <button type="button" class="btn btn-sm btn-outline-info btn-ai-answer"
                                            data-qna-id="<?= $qna['id'] ?>">
                                        <i class="bi bi-stars me-1"></i>AI 답변 생성
                                    </button>
                                </div>
                                <textarea name="answer" id="answer-<?= $qna['id'] ?>"
                                          class="form-control form-control-sm mb-2"
                                          rows="3" placeholder="답변 내용을 입력하세요" required></textarea>
                                <button class="btn btn-sm btn-success">답변 등록</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
            <tr><td colspan="6" class="text-center py-5 text-muted">문의가 없습니다.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total > $perPage):
    $totalPages = (int) ceil($total / $perPage);
    $qs = http_build_query(array_filter(['q' => $keyword, 'answered' => $answered]));
    $qs = $qs ? '&' . $qs : '';
?>
<nav class="mt-3">
    <ul class="pagination justify-content-center pagination-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="/admin/qna?page=<?= $p ?><?= $qs ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('.btn-ai-answer').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const qnaId   = this.dataset.qnaId;
        const textarea = document.getElementById('answer-' + qnaId);
        const original = this.innerHTML;

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>생성 중…';

        try {
            const res  = await fetch('/admin/qna/' + qnaId + '/suggest-answer', {
                method : 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                body   : new URLSearchParams({'<?= csrf_token() ?>': '<?= csrf_hash() ?>'}),
            });
            const data = await res.json();

            if (data.error) {
                const msg = data.setup_url
                    ? `${data.error}\n설정 바로가기: ${location.origin}${data.setup_url}`
                    : data.error;
                alert(msg);
                return;
            }

            textarea.value = data.answer ?? '';
            textarea.focus();
        } catch (e) {
            alert('네트워크 오류가 발생했습니다.');
        } finally {
            this.disabled  = false;
            this.innerHTML = original;
        }
    });
});
</script>
<?= $this->endSection() ?>
