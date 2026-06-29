<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '테마 관리' ?>
<?= $this->section('content') ?>


<div style="max-width:720px">

    <!-- 업로드 카드 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h6 class="fw-semibold mb-1"><i class="bi bi-upload me-2"></i>테마 업로드</h6>
            <p class="text-muted small mb-3">
                ZIP 파일로 패키징된 테마를 업로드합니다.
                파일명이 테마 이름이 됩니다 (예: <code>my-theme.zip</code> → <code>my-theme</code>).
            </p>

            <form id="themeUploadForm" method="post" action="/admin/settings/theme/upload" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="d-flex gap-2 align-items-start">
                    <div class="flex-grow-1">
                        <input id="themeZipInput" type="file" name="theme_zip" class="form-control form-control-sm" accept=".zip" required>
                        <div class="form-text">
                            필수: <code>views/layouts/main.php</code> · <code>public/css/style.css</code>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary text-nowrap">업로드 · 설치</button>
                </div>
            </form>

            <!-- ZIP 구조 안내 (접기/펼치기) -->
            <div class="mt-3">
                <a class="small text-muted" data-bs-toggle="collapse" href="#zipStructure" role="button">
                    <i class="bi bi-info-circle me-1"></i>ZIP 구조 보기
                </a>
                <div class="collapse mt-2" id="zipStructure">
                    <pre class="bg-light rounded p-3 small mb-0">my-theme.zip
├── views/                       ← app/Views/themes/my-theme/ 로 복사
│   ├── layouts/
│   │   └── main.php             ★ 필수
│   └── components/
│       ├── navbar.php
│       ├── footer.php
│       └── contact_form.php
└── public/                      ← public/themes/my-theme/ 로 복사
    ├── css/
    │   └── style.css            ★ 필수
    ├── js/
    │   └── main.js
    └── thumbnail.png              (관리자 미리보기 이미지)</pre>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mb-3">설치된 테마 목록 — 직접 폴더를 추가해도 자동으로 표시됩니다.</p>

    <div class="row g-3">
        <?php foreach ($availableThemes as $theme): ?>
        <div class="col-sm-6">
            <div class="card border-2 <?= $activeTheme === $theme['name'] ? 'border-primary' : 'border-light' ?> shadow-sm h-100">
                <!-- 미리보기 영역 -->
                <div class="card-img-top d-flex align-items-center justify-content-center bg-light"
                     style="height:120px; overflow:hidden; border-bottom:1px solid #e9ecef">
                    <?php
                    $preview = FCPATH . "themes/{$theme['name']}/thumbnail.png";
                    if (is_file($preview)): ?>
                        <img src="/themes/<?= esc($theme['name']) ?>/thumbnail.png"
                             style="width:100%;height:120px;object-fit:cover" alt="">
                    <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="bi bi-palette fs-2"></i>
                            <div class="small mt-1"><?= esc($theme['label']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong class="fs-6"><?= esc($theme['label']) ?></strong>
                        <?php if ($activeTheme === $theme['name']): ?>
                            <span class="badge bg-primary">적용 중</span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 mb-3">
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-file-earmark-code me-1"></i>레이아웃
                            <?= $theme['has_layout'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-muted"></i>' ?>
                        </span>
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-palette2 me-1"></i>CSS
                            <?= $theme['has_css'] ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-muted"></i>' ?>
                        </span>
                    </div>

                    <?php if ($activeTheme !== $theme['name']): ?>
                    <form method="post" action="/admin/settings/theme">
                        <?= csrf_field() ?>
                        <input type="hidden" name="active_theme" value="<?= esc($theme['name']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">이 테마 적용</button>
                    </form>
                    <?php else: ?>
                        <button class="btn btn-sm btn-primary w-100" disabled>현재 테마</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($availableThemes)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <code>app/Views/themes/</code> 폴더에 테마가 없습니다.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>

<!-- 중복 테마 확인 모달 -->
<div class="modal fade" id="dupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content border-0 shadow">
            <div class="modal-body p-4 text-center">
                <div class="mb-3" style="font-size:2.5rem">⚠️</div>
                <h6 class="fw-semibold mb-2">이미 설치된 테마입니다</h6>
                <p class="text-muted small mb-4">
                    <code id="dupThemeName"></code> 테마가 이미 존재합니다.<br>
                    파일을 덮어쓰고 업데이트하시겠습니까?
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-4"
                            data-bs-dismiss="modal">취소</button>
                    <button type="button" class="btn btn-warning btn-sm px-4"
                            id="btnConfirmUpdate">업데이트</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->section('scripts') ?>
<script>
(function () {
    // PHP에서 현재 설치된 테마 이름 목록 전달
    const existingThemes = <?= json_encode(array_keys($availableThemes)) ?>;

    // PHP의 테마명 추출 로직과 동일하게 처리
    function toThemeName(filename) {
        return filename
            .replace(/\.zip$/i, '')
            .toLowerCase()
            .replace(/[^a-z0-9\-_]+/g, '-')
            .replace(/^[-_]+|[-_]+$/g, '');
    }

    const form    = document.getElementById('themeUploadForm');
    const input   = document.getElementById('themeZipInput');
    const modal   = new bootstrap.Modal(document.getElementById('dupModal'));
    const nameEl  = document.getElementById('dupThemeName');
    const btnOk   = document.getElementById('btnConfirmUpdate');

    form.addEventListener('submit', function (e) {
        if (!input.files.length) return;

        const themeName = toThemeName(input.files[0].name);

        if (existingThemes.includes(themeName)) {
            e.preventDefault();
            nameEl.textContent = themeName;
            modal.show();
        }
    });

    // 업데이트 확인 → 모달 닫고 폼 제출
    btnOk.addEventListener('click', function () {
        modal.hide();
        document.getElementById('dupModal').addEventListener(
            'hidden.bs.modal',
            function () { form.submit(); },
            { once: true }
        );
    });
})();
</script>
<?= $this->endSection() ?>
