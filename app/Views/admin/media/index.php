<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '미디어 라이브러리' ?>
<?= $this->section('content') ?>

<!-- 업로드 영역 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div id="dropZone" class="border border-dashed rounded p-4 text-center text-muted"
             style="border-style:dashed!important; cursor:pointer">
            <i class="bi bi-cloud-upload fs-2"></i>
            <div>이미지를 드래그하거나 클릭하여 업로드</div>
            <div class="small text-muted">jpg, png, gif, webp, svg / 최대 5MB</div>
            <input type="file" id="fileInput" multiple accept="image/*" class="d-none">
        </div>
        <div id="uploadProgress" class="mt-2"></div>
    </div>
</div>

<!-- 미디어 그리드 -->
<div class="row g-3" id="mediaGrid">
    <?php foreach ($mediaList as $m): ?>
    <div class="col-6 col-sm-4 col-md-3 col-xl-2" id="media-<?= $m['id'] ?>">
        <div class="card border-0 shadow-sm h-100">
            <div class="ratio ratio-1x1">
                <img src="/<?= esc($m['file_path']) ?>" class="img-fluid object-fit-cover rounded-top" alt="<?= esc($m['alt']) ?>">
            </div>
            <div class="card-body p-2">
                <div class="small text-truncate text-muted mb-1" title="<?= esc($m['original_name']) ?>"><?= esc($m['original_name']) ?></div>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control form-control-sm" value="/<?= esc($m['file_path']) ?>"
                           onclick="this.select()" readonly title="클릭하여 경로 복사">
                </div>
                <div class="d-flex gap-1 mt-1">
                    <button class="btn btn-xs btn-outline-secondary btn-sm flex-fill"
                            onclick="copyPath('/<?= esc($m['file_path']) ?>')"><i class="bi bi-clipboard"></i></button>
                    <form method="post" action="/admin/media/<?= $m['id'] ?>/delete" onsubmit="return confirm('삭제?')" class="flex-fill">
                        <?= csrf_field() ?>
                        <button class="btn btn-xs btn-outline-danger btn-sm w-100"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 페이지네이션 -->
<?php if ($totalPages > 1): ?>
<nav class="mt-4 d-flex justify-content-center">
    <ul class="pagination pagination-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-primary'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-primary'));
dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('border-primary'); uploadFiles(e.dataTransfer.files); });
fileInput.addEventListener('change', () => uploadFiles(fileInput.files));

async function uploadFiles(files) {
    for (const file of files) {
        const fd = new FormData();
        fd.append('file', file);
        const res  = await fetch('/admin/media/upload', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
            document.getElementById('uploadProgress').innerHTML =
                `<div class="alert alert-success alert-sm py-1 small">업로드 완료: <code>${data.path}</code></div>`;
            setTimeout(() => location.reload(), 800);
        } else {
            alert(data.error);
        }
    }
}

function copyPath(path) {
    navigator.clipboard.writeText(path).then(() => alert('경로가 복사되었습니다:\n' + path));
}
</script>
<?= $this->endSection() ?>
