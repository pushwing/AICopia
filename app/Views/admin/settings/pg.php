<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '결제수단 설정' ?>
<?= $this->section('content') ?>

<!-- 탭 -->
<ul class="nav nav-tabs mb-4">
    <?php foreach (['general' => '기본', 'contact' => '연락처', 'sns' => 'SNS', 'seo' => 'SEO', 'footer' => '푸터', 'shop' => '쇼핑', 'grade' => '등급/포인트'] as $g => $label): ?>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/<?= $g ?>"><?= $label ?></a>
    </li>
    <?php endforeach; ?>
    <li class="nav-item">
        <a class="nav-link active" href="/admin/settings/pg">결제수단</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/oauth">소셜 로그인</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/api">외부 API</a>
    </li>
</ul>

<form method="post" action="/admin/settings/pg">
    <?= csrf_field() ?>

    <!-- 결제수단 활성화 -->
    <div class="card border-0 shadow-sm mb-4" style="max-width:700px">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-credit-card me-2 text-primary"></i>결제수단 활성화
        </div>
        <div class="card-body p-0">
            <?php foreach ($pgList as $key => $p): ?>
            <?php $enabled = ($settings["pg_enabled_{$key}"] ?? '1') === '1'; ?>
            <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
                <div>
                    <div class="fw-semibold small"><?= esc($p['label']) ?></div>
                    <div class="text-muted small"><?= esc($p['desc']) ?></div>
                    <?php if ($p['env'] !== []): ?>
                    <div class="mt-1">
                        <?php foreach ($p['env'] as $envKey): ?>
                        <span class="badge bg-light text-dark border small fw-normal me-1"><?= esc($envKey) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="pg_enabled_<?= esc($key) ?>" value="1"
                           id="pg_<?= esc($key) ?>"
                           <?= $enabled ? 'checked' : '' ?>>
                    <label class="form-check-label small text-muted" for="pg_<?= esc($key) ?>">
                        <?= $enabled ? '활성' : '비활성' ?>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 무통장입금 계좌 정보 -->
    <div class="card border-0 shadow-sm mb-4" style="max-width:700px">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-bank me-2 text-primary"></i>무통장입금 계좌 정보
        </div>
        <div class="card-body">
            <?php foreach ($bankSettings as $s): ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold"><?= esc($s['label']) ?></label>
                <input type="text" name="<?= esc($s['key']) ?>" class="form-control form-control-sm"
                       value="<?= esc($s['value']) ?>">
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card-footer bg-white border-0 text-end p-3">
            <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
        </div>
    </div>
</form>

<div class="alert alert-info small" style="max-width:700px">
    <i class="bi bi-shield-lock me-2"></i>
    PG사 API 키(Client ID·Secret 등)는 보안상 <strong>서버의 <code>.env</code> 파일</strong>에 직접 입력해야 합니다.
    위 뱃지는 각 PG사에 필요한 <code>.env</code> 키 이름입니다.
</div>

<?= $this->endSection() ?>
