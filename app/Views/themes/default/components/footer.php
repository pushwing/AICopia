<footer class="bg-dark text-white mt-5 py-4">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="fw-bold mb-2"><?= esc($settings['site_name'] ?? '') ?></div>
                <p class="text-secondary small mb-0"><?= esc($settings['site_desc'] ?? '') ?></p>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold mb-2 small text-uppercase text-secondary">연락처</div>
                <?php if (!empty($settings['phone'])): ?>
                    <div class="small"><i class="bi bi-telephone me-1"></i><?= esc($settings['phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($settings['email'])): ?>
                    <div class="small"><i class="bi bi-envelope me-1"></i><?= esc($settings['email']) ?></div>
                <?php endif; ?>
                <?php if (!empty($settings['address'])): ?>
                    <div class="small"><i class="bi bi-geo-alt me-1"></i><?= esc($settings['address']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="fw-semibold mb-2 small text-uppercase text-secondary">SNS</div>
                <div class="d-flex gap-3">
                    <?php if (!empty($settings['instagram'])): ?>
                        <a href="<?= esc($settings['instagram']) ?>" target="_blank" class="text-secondary fs-5"><i class="bi bi-instagram"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($settings['youtube'])): ?>
                        <a href="<?= esc($settings['youtube']) ?>" target="_blank" class="text-secondary fs-5"><i class="bi bi-youtube"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($settings['blog'])): ?>
                        <a href="<?= esc($settings['blog']) ?>" target="_blank" class="text-secondary fs-5"><i class="bi bi-rss"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($settings['kakao'])): ?>
                        <a href="<?= esc($settings['kakao']) ?>" target="_blank" class="text-secondary fs-5"><i class="bi bi-chat-fill"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <hr class="border-secondary mt-4">
        <div class="d-flex justify-content-between align-items-center small text-secondary">
            <span><?= esc($settings['copyright'] ?? '') ?></span>
            <?php if (!empty($settings['business_num'])): ?>
                <span>사업자번호: <?= esc($settings['business_num']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</footer>
