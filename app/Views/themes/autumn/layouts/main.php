<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $seo = new \App\Libraries\SeoHelper($settings);
    echo $seo->render($page ?? null);
    echo $seo->gaScript();
    echo $seo->gtmHeadScript();
    ?>
    <?php if (!empty($settings['favicon'])): ?>
    <link rel="icon" href="/<?= esc($settings['favicon']) ?>">
    <?php endif; ?>
    <?php /* CDN(부트스트랩·아이콘) 연결을 미리 열어 초기 렌더 지연을 줄인다 */ ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <?php /* SRI(integrity) — CDN 변조/오염 시 로드를 차단한다. 해시는 5.3.0/1.11.0 실제 파일 기준 */ ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
          integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"
          integrity="sha384-QuGBSgV5Im3DzL2z+8Ko9/hqNy/N0O7zwvXAtfd1MvPKWa/UbeLV65cfm4BV5Wgq" crossorigin="anonymous">
    <?php /* 로컬 에셋에 파일 수정시각 기반 버전을 붙여 배포·수정 시 옛 브라우저 캐시를 무효화한다 */ ?>
    <?php $assetVer = static fn (string $p): string => is_file(FCPATH . $p) ? '?v=' . filemtime(FCPATH . $p) : ''; ?>
    <link rel="stylesheet" href="/themes/default/css/style.css<?= $assetVer('themes/default/css/style.css') ?>">
    <?php /* 파생 테마 리틴트 — 활성 테마의 팔레트 오버라이드 CSS가 있으면 중립 default 위에 얹는다.
             default 는 중립 캔버스로 남기고(Empty-Brand Rule), 색은 여기 파생 테마에서만 주입한다. */ ?>
    <?php $themeCss = 'themes/' . ($settings['active_theme'] ?? 'default') . '/css/style.css'; ?>
    <?php if (($settings['active_theme'] ?? 'default') !== 'default' && is_file(FCPATH . $themeCss)): ?>
    <link rel="stylesheet" href="/<?= esc($themeCss) ?>?v=<?= filemtime(FCPATH . $themeCss) ?>">
    <?php endif; ?>
</head>
<body>
<?= $seo->gtmBodyScript() ?>

<?= $this->include('components/navbar') ?>

<div class="d-flex align-items-start">
    <?php if (!empty($subLeftBanners)): ?>
    <aside class="sp-banner-slot d-none d-md-block flex-shrink-0 p-2">
        <?php foreach ($subLeftBanners as $b): ?>
        <?php if ($b['link_url']): ?>
        <a href="<?= esc($b['link_url']) ?>" target="<?= esc($b['link_target']) ?>">
            <img src="/<?= esc($b['image_path']) ?>" alt="" class="sp-banner-img">
        </a>
        <?php else: ?>
        <img src="/<?= esc($b['image_path']) ?>" alt="" class="sp-banner-img">
        <?php endif; ?>
        <?php endforeach; ?>
    </aside>
    <?php endif; ?>

    <?php /* min-w-0 이 빠지면(예전 오타 min-width-0 처럼 style.css 에 없는 이름이면)
             flex 아이템 기본값 min-width:auto 때문에 본문의 min-content — 줄바꿈되지
             않는 표·긴 주문번호 등 — 만큼 main 이 늘어나 페이지 전체가 가로로 넘친다. */ ?>
    <main class="flex-grow-1 min-w-0">
        <?php foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $cls): ?>
            <?php if (session()->has($key)): ?>
            <div class="container mt-3">
                <div class="alert alert-<?= $cls ?> alert-dismissible fade show">
                    <?= esc(session($key)) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?= $this->renderSection('content') ?>
    </main>
</div>

<?= $this->include('components/footer') ?>

<?php /* ─── 공용 토스트/확인 모달 (네이티브 alert/confirm 대체) ───
        window.toast(message, type) 와 window.confirmDialog(opts)->Promise 가
        이 컨테이너를 사용한다. 정의는 main.js. */ ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:1090"></div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true" aria-labelledby="confirmModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle" data-confirm-title>확인</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
            </div>
            <div class="modal-body" data-confirm-message style="white-space:pre-line"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-confirm-cancel>취소</button>
                <button type="button" class="btn btn-primary" data-confirm-ok>확인</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script src="/themes/default/js/main.js<?= $assetVer('themes/default/js/main.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
<?= $this->include('components/popups') ?>
<script src="/themes/default/js/popup.js<?= $assetVer('themes/default/js/popup.js') ?>"></script>
</body>
</html>
