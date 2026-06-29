<?php if (empty($activePopups)) return; ?>

<div id="popup-layer">
<?php foreach ($activePopups as $p): ?>
<div class="site-popup" id="popup-<?= $p['id'] ?>"
     style="left:<?= (int)$p['pos_x'] ?>px;top:<?= (int)$p['pos_y'] ?>px"
     data-id="<?= $p['id'] ?>">
    <button type="button" class="site-popup-close" aria-label="닫기">&times;</button>
    <?php if ($p['image_path']): ?>
    <img src="/<?= esc($p['image_path']) ?>" alt="<?= esc($p['title']) ?>" class="site-popup-img">
    <?php endif; ?>
    <?php if ($p['content']): ?>
    <?php /* TinyMCE 저장 HTML — 관리자 입력이므로 raw 출력 허용 */ ?>
    <div class="site-popup-body"><?= $p['content'] ?></div>
    <?php endif; ?>
    <div class="site-popup-footer">
        <label class="site-popup-today">
            <input type="checkbox" class="popup-hide-today" data-id="<?= $p['id'] ?>">
            오늘 하루 보지 않기
        </label>
    </div>
</div>
<?php endforeach; ?>
</div>
