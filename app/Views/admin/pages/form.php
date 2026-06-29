<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = $page ? '페이지 수정' : '페이지 추가' ?>
<?= $this->section('content') ?>

<div class="card border-0 shadow-sm" style="max-width:800px">
    <div class="card-body p-4">
        <?php if (session()->has('errors')): ?>
            <div class="alert alert-danger"><?php foreach (session('errors') as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="post" action="<?= $page ? "/admin/pages/{$page['id']}/edit" : '/admin/pages/create' ?>">
            <?= csrf_field() ?>
            <div class="row g-3">
                <?php if (! $page): ?>
                <div class="col-md-6">
                    <label class="form-label small">슬러그 (영문, -, _) *</label>
                    <input type="text" name="slug" class="form-control form-control-sm"
                           value="<?= old('slug') ?>" required placeholder="예: about, service">
                </div>
                <?php endif; ?>
                <div class="col-md-<?= $page ? '6' : '6' ?>">
                    <label class="form-label small">레이아웃</label>
                    <select name="layout" class="form-select form-select-sm">
                        <?php foreach (['default' => '기본', 'contact' => '문의폼', 'landing' => '랜딩'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= ($page['layout'] ?? 'default') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small">제목 *</label>
                    <input type="text" name="title" class="form-control" value="<?= esc(old('title', $page['title'] ?? '')) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small">내용</label>
                    <textarea name="content" id="editor" class="form-control" rows="12"><?= esc(old('content', $page['content'] ?? '')) ?></textarea>
                </div>
                <div class="col-12"><hr class="my-1"><p class="text-muted small mb-2">SEO 설정</p></div>
                <div class="col-md-6">
                    <label class="form-label small">메타 타이틀</label>
                    <input type="text" name="meta_title" class="form-control form-control-sm" value="<?= esc(old('meta_title', $page['meta_title'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">메타 설명</label>
                    <input type="text" name="meta_desc" class="form-control form-control-sm" value="<?= esc(old('meta_desc', $page['meta_desc'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">순서</label>
                    <input type="number" name="sort_order" class="form-control form-control-sm" value="<?= $page['sort_order'] ?? 0 ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">상태</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="published" <?= ($page['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>공개</option>
                        <option value="draft"     <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>>초안</option>
                    </select>
                </div>
            </div>
            <div class="d-flex gap-2 justify-content-end mt-4">
                <a href="/admin/pages" class="btn btn-outline-secondary btn-sm">취소</a>
                <button type="submit" class="btn btn-primary btn-sm">저장</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<!-- TinyMCE 에디터 (무료 CDN) -->
<script src="https://cdn.tiny.cloud/1/<?= config('Editor')->tinymceApiKey ?>/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#editor',
    language: 'ko_KR',
    height: 400,
    plugins: 'lists link image table code',
    toolbar: 'undo redo | formatselect | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code',
    images_upload_url: '/admin/media/upload',
    automatic_uploads: true,
    convert_urls: false,
    relative_urls: false,
    document_base_url: '<?= base_url('/') ?>',
});
</script>
<?= $this->endSection() ?>
