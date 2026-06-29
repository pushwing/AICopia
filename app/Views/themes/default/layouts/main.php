<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $seo = new \App\Libraries\SeoHelper($settings);
    echo $seo->render($page ?? null);
    echo $seo->gaScript();
    ?>
    <?php if (!empty($settings['favicon'])): ?>
    <link rel="icon" href="/<?= esc($settings['favicon']) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/themes/default/css/style.css">
</head>
<body>

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

    <main class="flex-grow-1 min-width-0">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/themes/default/js/main.js"></script>
<?= $this->renderSection('scripts') ?>
<?= $this->include('components/popups') ?>
<script src="/themes/default/js/popup.js"></script>
</body>
</html>
