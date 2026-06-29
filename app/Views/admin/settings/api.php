<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '외부 API 설정' ?>
<?= $this->section('content') ?>

<!-- 탭 -->
<ul class="nav nav-tabs mb-4">
    <?php foreach (['general' => '기본', 'contact' => '연락처', 'sns' => 'SNS', 'seo' => 'SEO', 'footer' => '푸터', 'shop' => '쇼핑', 'grade' => '등급/포인트'] as $g => $label): ?>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/<?= $g ?>"><?= $label ?></a>
    </li>
    <?php endforeach; ?>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/pg">결제수단</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="/admin/settings/oauth">소셜 로그인</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="/admin/settings/api">외부 API</a>
    </li>
</ul>

<form method="post" action="/admin/settings/api" style="max-width:600px">
    <?= csrf_field() ?>

    <!-- AI 제공자 선택 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-stars me-2 text-primary"></i>AI 기능 설정
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">AI 제공자</label>
                <select name="ai_provider" class="form-select form-select-sm">
                    <?php
                    $currentProvider = $settings['ai_provider'] ?? (getenv('AI_PROVIDER') ?: 'groq');
                    ?>
                    <option value="groq"        <?= $currentProvider === 'groq'        ? 'selected' : '' ?>>Groq (llama3 — 무료·빠름)</option>
                    <option value="claude"      <?= $currentProvider === 'claude'      ? 'selected' : '' ?>>Claude (Haiku — 고품질)</option>
                    <option value="openrouter"  <?= $currentProvider === 'openrouter'  ? 'selected' : '' ?>>OpenRouter (멀티모델 라우터)</option>
                </select>
                <div class="form-text">상품 카테고리 추천·설명 생성·문의 답변에 사용됩니다.</div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Groq API Key</label>
                <input type="password" name="groq_api_key" class="form-control form-control-sm"
                       value="<?= esc($settings['groq_api_key'] ?? '') ?>"
                       placeholder="gsk_..." autocomplete="new-password">
                <div class="form-text">
                    <a href="https://console.groq.com/keys" target="_blank">console.groq.com</a>에서 무료 발급
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Anthropic (Claude) API Key</label>
                <input type="password" name="anthropic_api_key" class="form-control form-control-sm"
                       value="<?= esc($settings['anthropic_api_key'] ?? '') ?>"
                       placeholder="sk-ant-..." autocomplete="new-password">
                <div class="form-text">
                    <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a>에서 발급
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">OpenRouter API Key</label>
                <input type="password" name="openrouter_api_key" class="form-control form-control-sm"
                       value="<?= esc($settings['openrouter_api_key'] ?? '') ?>"
                       placeholder="sk-or-..." autocomplete="new-password">
                <div class="form-text">
                    <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai</a>에서 발급 — 무료 모델 포함 200개+ 지원
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label small fw-semibold">OpenRouter 모델</label>
                <input type="text" name="openrouter_model" class="form-control form-control-sm"
                       value="<?= esc($settings['openrouter_model'] ?? '') ?>"
                       placeholder="meta-llama/llama-3.1-8b-instruct:free">
                <div class="form-text">
                    비워두면 <code>meta-llama/llama-3.1-8b-instruct:free</code> 사용.
                    사용 가능 모델: <a href="https://openrouter.ai/models" target="_blank">openrouter.ai/models</a>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white border-0 text-end p-3">
            <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
        </div>
    </div>

    <!-- AI 프롬프트 (고급) -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center"
             role="button" data-bs-toggle="collapse" data-bs-target="#aiPromptBody">
            <span><i class="bi bi-sliders me-2 text-primary"></i>AI 프롬프트 (고급)</span>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div class="collapse" id="aiPromptBody">
            <div class="card-body">
                <p class="text-muted small">
                    비워두면 기본 프롬프트가 사용됩니다. 톤·정책을 조정할 때만 입력하세요.
                    <code>{categories}</code> 등 중괄호 변수는 자동으로 치환됩니다.
                </p>
                <?php
                $promptLabels   = \App\Libraries\AiProvider\AiPrompts::labels();
                $promptDefaults = \App\Libraries\AiProvider\AiPrompts::defaults();
                ?>
                <?php foreach (\App\Libraries\AiProvider\AiPrompts::KEYS as $pk): ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold"><?= esc($promptLabels[$pk] ?? $pk) ?></label>
                    <textarea name="ai_prompt_<?= esc($pk) ?>" class="form-control form-control-sm font-monospace"
                              rows="5" placeholder="<?= esc($promptDefaults[$pk] ?? '') ?>"><?= esc($settings['ai_prompt_' . $pk] ?? '') ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-white border-0 text-end p-3">
                <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
            </div>
        </div>
    </div>

    <!-- 네이버 쇼핑 검색 API -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <span class="badge bg-success me-2">N</span>네이버 쇼핑 검색 API
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label small fw-semibold">클라이언트 ID</label>
                <input type="text" name="naver_shopping_client_id" class="form-control form-control-sm"
                       value="<?= esc($settings['naver_shopping_client_id'] ?? '') ?>"
                       placeholder="발급받은 클라이언트 ID 입력">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">클라이언트 Secret</label>
                <input type="password" name="naver_shopping_client_secret" class="form-control form-control-sm"
                       value="<?= esc($settings['naver_shopping_client_secret'] ?? '') ?>"
                       placeholder="발급받은 클라이언트 Secret 입력"
                       autocomplete="new-password">
                <div class="form-text">저장된 Secret은 보안을 위해 마스킹 표시됩니다.</div>
            </div>
        </div>
        <div class="card-footer bg-white border-0 text-end p-3">
            <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
        </div>
    </div>

</form>

<!-- 키 발급 안내 -->
<div class="card border-0 shadow-sm mb-4" style="max-width:600px">
    <div class="card-header bg-white fw-semibold">API 키 발급 방법</div>
    <div class="card-body small text-muted">
        <ol class="mb-0 ps-3">
            <li class="mb-1"><a href="https://developers.naver.com/apps/#/register" target="_blank">네이버 개발자센터</a>에 접속해 로그인합니다.</li>
            <li class="mb-1"><strong>Application 등록</strong> → 사용 API에서 <strong>검색</strong>을 선택합니다.</li>
            <li class="mb-1">등록 후 <strong>Client ID</strong>와 <strong>Client Secret</strong>을 위 폼에 입력합니다.</li>
            <li>일 검색 호출 한도: <strong>25,000건</strong> (무료)</li>
        </ol>
    </div>
</div>

<?= $this->endSection() ?>
