<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $board ? '게시판 수정' : '게시판 추가' ?>
<?= $this->section('content') ?>
<div class="mb-2"><a href="/admin/boards" class="text-muted small"><i class="bi bi-arrow-left"></i> 목록</a></div>
<div class="card" style="max-width:600px">
    <div class="card-header bg-white"><strong><?= $board ? '게시판 수정' : '게시판 추가' ?></strong></div>
    <div class="card-body">
        <?php if (session()->has('errors')): ?>
            <div class="alert alert-danger"><?php foreach (session('errors') as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <form method="post" action="<?= $board ? "/admin/boards/{$board['id']}/edit" : '/admin/boards/create' ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label small">슬러그 (영문, -, _)</label>
                <input type="text" name="slug" class="form-control form-control-sm"
                       value="<?= esc($board['slug'] ?? old('slug')) ?>"
                       <?= $board ? 'readonly' : 'required' ?> placeholder="예: free, notice, qna">
            </div>
            <div class="mb-3">
                <label class="form-label small">게시판 이름</label>
                <input type="text" name="name" class="form-control form-control-sm"
                       value="<?= esc($board['name'] ?? old('name')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label small">설명</label>
                <input type="text" name="description" class="form-control form-control-sm"
                       value="<?= esc($board['description'] ?? '') ?>">
            </div>
            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label small">읽기 권한</label>
                    <select name="read_permission" class="form-select form-select-sm">
                        <?php foreach (['guest' => '비회원(전체)', 'member' => '회원', 'admin' => '관리자'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($board['read_permission'] ?? 'guest') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label class="form-label small">쓰기 권한</label>
                    <select name="write_permission" class="form-select form-select-sm">
                        <?php foreach (['guest' => '비회원(전체)', 'member' => '회원', 'admin' => '관리자'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($board['write_permission'] ?? 'member') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label small">페이지당 글 수</label>
                    <input type="number" name="posts_per_page" class="form-control form-control-sm"
                           value="<?= $board['posts_per_page'] ?? 15 ?>" min="5" max="100">
                </div>
                <div class="col">
                    <label class="form-label small">순서</label>
                    <input type="number" name="sort_order" class="form-control form-control-sm"
                           value="<?= $board['sort_order'] ?? 0 ?>">
                </div>
            </div>
            <div class="d-flex gap-3 mb-3">
                <div class="form-check">
                    <input type="checkbox" name="allow_file" value="1" id="allow_file" class="form-check-input"
                           <?= ($board['allow_file'] ?? 1) ? 'checked' : '' ?>>
                    <label for="allow_file" class="form-check-label small">파일 첨부 허용</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="allow_image" value="1" id="allow_image" class="form-check-input"
                           <?= ($board['allow_image'] ?? 1) ? 'checked' : '' ?>>
                    <label for="allow_image" class="form-check-label small">이미지 첨부 허용</label>
                </div>
                <?php if ($board): ?>
                <div class="form-check">
                    <input type="checkbox" name="is_active" value="1" id="is_active" class="form-check-input"
                           <?= $board['is_active'] ? 'checked' : '' ?>>
                    <label for="is_active" class="form-check-label small">활성화</label>
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <a href="/admin/boards" class="btn btn-outline-secondary btn-sm">취소</a>
                <button type="submit" class="btn btn-primary btn-sm">저장</button>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
