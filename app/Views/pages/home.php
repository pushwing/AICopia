<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- 메인 상단 배너 -->
<?= view('components/banner_slot', ['banners' => $mainTopBanners]) ?>

<!-- 히어로 -->
<section class="py-5 bg-primary text-white">
    <div class="container py-4 text-center">
        <h1 class="display-5 fw-bold"><?= esc($settings['site_name'] ?? '') ?></h1>
        <p class="lead mb-4"><?= esc($settings['site_desc'] ?? '') ?></p>
        <a href="/contact" class="btn btn-light btn-lg me-2">문의하기</a>
        <a href="/service" class="btn btn-outline-light btn-lg">서비스 보기</a>
    </div>
</section>

<!-- 서비스 소개 (샘플 3열) -->
<section class="py-5">
    <div class="container">
        <h2 class="text-center mb-4 fw-bold">우리의 서비스</h2>
        <div class="row g-4">
            <?php
            $services = [
                ['icon' => 'bi-laptop',    'title' => '웹사이트 제작', 'desc' => '기업 홈페이지부터 쇼핑몰까지 빠르고 정확하게 제작합니다.'],
                ['icon' => 'bi-phone',     'title' => '반응형 디자인', 'desc' => '모든 기기에서 최적화된 화면을 제공합니다.'],
                ['icon' => 'bi-headset',   'title' => '유지보수',      'desc' => '제작 후에도 지속적인 관리와 지원을 제공합니다.'],
            ];
            foreach ($services as $s):
            ?>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm text-center p-4">
                    <i class="bi <?= $s['icon'] ?> fs-1 text-primary mb-3"></i>
                    <h5 class="fw-bold"><?= $s['title'] ?></h5>
                    <p class="text-muted small"><?= $s['desc'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- 최신 공지 -->
<?php if (!empty($latestPosts)): ?>
<section class="py-5 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">최신 공지사항</h5>
            <a href="/board/notice" class="text-decoration-none small">전체보기 <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="list-group">
            <?php foreach ($latestPosts as $post): ?>
            <a href="/board/notice/<?= $post['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <span><?= esc($post['title']) ?></span>
                <span class="text-muted small"><?= date('Y년 n월 j일', strtotime($post['created_at'])) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="py-5 bg-dark text-white text-center">
    <div class="container">
        <h3 class="fw-bold mb-2">프로젝트를 시작할 준비가 되셨나요?</h3>
        <p class="text-secondary mb-4">지금 바로 문의하세요. 빠르게 답변드립니다.</p>
        <?php if (!empty($settings['phone'])): ?>
            <a href="tel:<?= esc($settings['phone']) ?>" class="btn btn-outline-light me-2">
                <i class="bi bi-telephone me-1"></i><?= esc($settings['phone']) ?>
            </a>
        <?php endif; ?>
        <a href="/contact" class="btn btn-primary">온라인 문의</a>
    </div>
</section>

<!-- 메인 하단 배너 -->
<?= view('components/banner_slot', ['banners' => $mainBotBanners]) ?>

<?= $this->endSection() ?>
