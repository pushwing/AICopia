<?php if (!empty($banners)): ?>
<div class="container py-3">
    <div class="main-banner-wrap">
        <?php foreach ($banners as $b): ?>
        <?php if ($b['link_url']): ?>
        <a href="<?= esc($b['link_url']) ?>" target="<?= esc($b['link_target']) ?>">
            <img src="/<?= esc($b['image_path']) ?>" alt="">
        </a>
        <?php else: ?>
        <img src="/<?= esc($b['image_path']) ?>" alt="">
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
