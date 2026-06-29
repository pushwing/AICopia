<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '소셜 로그인 설정' ?>
<?= $this->section('content') ?>

<!-- 탭 -->
<ul class="nav nav-tabs mb-4">
    <?php foreach (['general' => '기본', 'contact' => '연락처', 'sns' => 'SNS', 'seo' => 'SEO', 'footer' => '푸터', 'shop' => '쇼핑'] as $g => $label): ?>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/<?= $g ?>"><?= $label ?></a>
    </li>
    <?php endforeach; ?>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/pg">결제수단</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/admin/settings/oauth">소셜 로그인</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/api">외부 API</a>
    </li>
</ul>

<!-- 활성화 토글 -->
<form method="post" action="/admin/settings/oauth">
    <?= csrf_field() ?>

    <div class="card border-0 shadow-sm mb-4" style="max-width:600px">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-toggle-on me-2 text-primary"></i>소셜 로그인 활성화
        </div>
        <div class="card-body p-0">

            <?php
            $providers = [
                'naver'  => ['label' => '네이버 로그인',  'icon' => 'bi-n-circle-fill text-success', 'badge' => 'bg-success'],
                'kakao'  => ['label' => '카카오 로그인',  'icon' => 'bi-chat-fill text-warning',      'badge' => 'bg-warning text-dark'],
                'google' => ['label' => '구글 로그인',    'icon' => 'bi-google text-danger',          'badge' => 'bg-danger'],
            ];
            foreach ($providers as $key => $p):
                $enabled = ($settings["oauth_enabled_{$key}"] ?? '1') === '1';
            ?>
            <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi <?= $p['icon'] ?> fs-5"></i>
                    <span class="fw-semibold small"><?= esc($p['label']) ?></span>
                    <span class="badge <?= $p['badge'] ?> small"><?= strtoupper($key) ?></span>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="oauth_enabled_<?= esc($key) ?>" value="1"
                           id="oauth_<?= esc($key) ?>"
                           <?= $enabled ? 'checked' : '' ?>>
                    <label class="form-check-label small text-muted" for="oauth_<?= esc($key) ?>">
                        <?= $enabled ? '활성' : '비활성' ?>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
        <div class="card-footer bg-white border-0 text-end p-3">
            <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
        </div>
    </div>
</form>

<div class="alert alert-info small">
    <i class="bi bi-shield-lock me-2"></i>
    소셜 로그인 API 키는 보안상 <strong>서버의 <code>.env</code> 파일</strong>에 직접 입력해야 합니다.
</div>

<!-- 콜백 URL 안내 -->
<div class="card border-0 shadow-sm mb-4" style="max-width:600px">
    <div class="card-header bg-white fw-semibold">콜백(Redirect) URL</div>
    <div class="card-body">
        <p class="small text-muted mb-2">각 소셜 앱 콘솔에 아래 URL을 등록하세요.</p>
        <table class="table table-sm small">
            <thead class="table-light"><tr><th>제공자</th><th>콜백 URL</th></tr></thead>
            <tbody>
                <tr><td><i class="bi bi-n-circle-fill text-success me-1"></i>네이버</td>
                    <td><code><?= base_url('auth/social/naver/callback') ?></code></td></tr>
                <tr><td><i class="bi bi-chat-fill text-warning me-1"></i>카카오</td>
                    <td><code><?= base_url('auth/social/kakao/callback') ?></code></td></tr>
                <tr><td><i class="bi bi-google text-danger me-1"></i>구글</td>
                    <td><code><?= base_url('auth/social/google/callback') ?></code></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- .env 설정 가이드 -->
<div class="card border-0 shadow-sm mb-4" style="max-width:600px">
    <div class="card-header bg-white fw-semibold">.env 설정 방법</div>
    <div class="card-body">
        <pre class="bg-dark text-white rounded p-3 small"><code># ─── 네이버 로그인 ────────────────────────────────────
# https://developers.naver.com/apps/#/register
oauth.naver.client_id     = YOUR_NAVER_CLIENT_ID
oauth.naver.client_secret = YOUR_NAVER_CLIENT_SECRET

# ─── 카카오 로그인 ────────────────────────────────────
# https://developers.kakao.com/console/app
# REST API 키 사용
oauth.kakao.client_id     = YOUR_KAKAO_REST_API_KEY
oauth.kakao.client_secret = YOUR_KAKAO_CLIENT_SECRET

# ─── 구글 로그인 ─────────────────────────────────────
# https://console.cloud.google.com/apis/credentials
oauth.google.client_id     = YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com
oauth.google.client_secret = YOUR_GOOGLE_CLIENT_SECRET</code></pre>
    </div>
</div>

<!-- 앱 등록 바로가기 -->
<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-header bg-white fw-semibold">앱 등록 바로가기</div>
    <div class="list-group list-group-flush">
        <a href="https://developers.naver.com/apps/#/register" target="_blank"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3">
            <span class="badge bg-success fs-6 px-2">N</span>
            <div>
                <div class="fw-semibold small">네이버 개발자 센터</div>
                <div class="text-muted small">애플리케이션 등록 → 네이버 로그인 API 사용 신청</div>
            </div>
            <i class="bi bi-box-arrow-up-right ms-auto text-muted"></i>
        </a>
        <a href="https://developers.kakao.com/console/app" target="_blank"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3">
            <span class="badge bg-warning text-dark fs-6 px-2">K</span>
            <div>
                <div class="fw-semibold small">카카오 개발자 콘솔</div>
                <div class="text-muted small">앱 생성 → 카카오 로그인 활성화 → 동의항목 설정</div>
            </div>
            <i class="bi bi-box-arrow-up-right ms-auto text-muted"></i>
        </a>
        <a href="https://console.cloud.google.com/apis/credentials" target="_blank"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3">
            <span class="badge bg-danger fs-6 px-2">G</span>
            <div>
                <div class="fw-semibold small">Google Cloud Console</div>
                <div class="text-muted small">OAuth 2.0 클라이언트 ID 생성 → 웹 애플리케이션 유형 선택</div>
            </div>
            <i class="bi bi-box-arrow-up-right ms-auto text-muted"></i>
        </a>
    </div>
</div>

<?= $this->endSection() ?>
