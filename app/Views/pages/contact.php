<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="container py-5">
    <div class="row g-5">
        <div class="col-lg-5">
            <h2 class="fw-bold mb-4"><?= esc($page['title']) ?></h2>
            <p class="text-muted mb-4"><?= esc($settings['site_desc'] ?? '') ?></p>
            <div class="d-flex flex-column gap-3">
                <?php if (!empty($settings['phone'])): ?>
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 rounded p-2"><i class="bi bi-telephone text-primary fs-5"></i></div>
                    <div>
                        <div class="text-muted small">전화</div>
                        <a href="tel:<?= esc($settings['phone']) ?>" class="fw-semibold text-decoration-none"><?= esc($settings['phone']) ?></a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($settings['email'])): ?>
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 rounded p-2"><i class="bi bi-envelope text-primary fs-5"></i></div>
                    <div>
                        <div class="text-muted small">이메일</div>
                        <a href="mailto:<?= esc($settings['email']) ?>" class="fw-semibold text-decoration-none"><?= esc($settings['email']) ?></a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($settings['address'])): ?>
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 rounded p-2"><i class="bi bi-geo-alt text-primary fs-5"></i></div>
                    <div>
                        <div class="text-muted small">주소</div>
                        <span class="fw-semibold"><?= esc($settings['address']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm p-4">
                <?php if (! session()->has('success')): ?>
                    <?= $this->include('components/contact_form') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
