<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="container py-4">
<div class="mb-2">
    <a href="/board/<?= esc($board['slug']) ?>" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left"></i> <?= esc($board['name']) ?> 목록
    </a>
</div>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-1"><?= esc($post['title']) ?></h5>
        <div class="d-flex gap-3 text-muted small">
            <span><i class="bi bi-person"></i> <?= esc($post['user_nickname'] ?? $post['author_name']) ?></span>
            <span><i class="bi bi-clock"></i> <?= $post['created_at'] ?></span>
            <span><i class="bi bi-eye"></i> <?= number_format($post['views']) ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="post-content">
            <?= $post['content'] ?>
        </div>

        <!-- 이미지 첨부 -->
        <?php $images = array_filter($files, fn($f) => $f['is_image']); ?>
        <?php if ($images): ?>
        <hr>
        <div class="row g-2 mt-2">
            <?php foreach ($images as $img): ?>
            <div class="col-auto">
                <a href="/<?= esc($img['file_path']) ?>" target="_blank">
                    <img src="/<?= esc($img['file_path']) ?>" alt="<?= esc($img['original_name']) ?>"
                         class="img-thumbnail" style="max-height:200px;">
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 파일 첨부 -->
        <?php $attachments = array_filter($files, fn($f) => !$f['is_image']); ?>
        <?php if ($attachments): ?>
        <hr>
        <div class="file-list">
            <div class="text-muted small mb-1"><i class="bi bi-paperclip"></i> 첨부파일</div>
            <?php foreach ($attachments as $file): ?>
            <div>
                <a href="/board/file/<?= $file['id'] ?>/download" class="text-decoration-none">
                    <i class="bi bi-file-earmark"></i>
                    <?= esc($file['original_name']) ?>
                    <span class="text-muted">(<?= round($file['file_size'] / 1024) ?>KB)</span>
                    <span class="text-muted small">↓<?= $file['download_count'] ?></span>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 수정/삭제 버튼 -->
    <div class="card-footer bg-white d-flex gap-2 justify-content-end">
        <?php
        $userId = session()->get('user_id');
        $role   = session()->get('user_role') ?? 'guest';
        $canEdit = $role === 'admin' || ($userId && $post['user_id'] == $userId);
        ?>
        <?php if ($canEdit): ?>
            <a href="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">수정</a>
            <form method="post" action="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>/delete"
                  onsubmit="return confirm('삭제하시겠습니까?')">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger">삭제</button>
            </form>
        <?php elseif (!$userId && $post['author_password']): ?>
            <!-- 비회원 수정/삭제: 비밀번호 입력 모달 -->
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#guestModal" data-action="edit">수정</button>
            <button class="btn btn-sm btn-outline-danger"    data-bs-toggle="modal" data-bs-target="#guestModal" data-action="delete">삭제</button>
        <?php endif; ?>
    </div>
</div>

<!-- 댓글 -->
<div id="comments" class="card mb-4">
    <div class="card-header bg-white">
        <strong>댓글 <?= count($comments) ?>개</strong>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($comments as $c): ?>
        <div class="list-group-item comment-box ps-3">
            <div class="d-flex justify-content-between">
                <strong class="small"><?= esc($c['user_nickname'] ?? $c['author_name']) ?></strong>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small"><?= date('Y년 n월 j일 G시 i분', strtotime($c['created_at'])) ?></span>
                    <?php if ($role === 'admin' || ($userId && $c['user_id'] == $userId)): ?>
                    <form method="post" action="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>/comment/<?= $c['id'] ?>/delete"
                          onsubmit="return confirm('삭제?')">
                        <?= csrf_field() ?>
                        <button class="btn btn-link btn-sm text-danger p-0">삭제</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="mb-0 mt-1 small"><?= nl2br(esc($c['content'])) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 댓글 작성 -->
    <div class="card-footer bg-white">
        <form method="post" action="/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>/comment">
            <?= csrf_field() ?>
            <?php if (! session()->get('user_id')): ?>
            <div class="row g-2 mb-2">
                <div class="col-sm-3">
                    <input type="text" name="author_name" class="form-control form-control-sm" placeholder="이름" required>
                </div>
                <div class="col-sm-3">
                    <input type="password" name="author_password" class="form-control form-control-sm" placeholder="비밀번호" required>
                </div>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <textarea name="content" class="form-control form-control-sm" rows="2" placeholder="댓글을 입력하세요" required></textarea>
                <button class="btn btn-primary btn-sm px-3">등록</button>
            </div>
        </form>
    </div>
</div>

<!-- 비회원 수정/삭제 모달 -->
<div class="modal fade" id="guestModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="post" id="guestForm">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h6 class="modal-title">비밀번호 확인</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="password" name="author_password" class="form-control" placeholder="작성 시 비밀번호" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-sm">확인</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
const guestModal = document.getElementById('guestModal');
guestModal.addEventListener('show.bs.modal', function(e) {
    const action = e.relatedTarget.dataset.action;
    const base = '/board/<?= esc($board['slug']) ?>/<?= $post['id'] ?>';
    document.getElementById('guestForm').action = action === 'edit' ? base + '/verify' : base + '/delete';
});
</script>
<?= $this->endSection() ?>
