<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '스토어 홈페이지 설정' ?>
<?= $this->section('styles') ?>
<style>
.homepage-option {
    display: block;
    position: relative;
    height: 100%;
    padding: 1rem;
    border: 2px solid #dee2e6;
    border-radius: .5rem;
    cursor: pointer;
    transition: border-color .15s ease, background-color .15s ease;
}
.homepage-option:hover { border-color: #adb5bd; }
.homepage-option.is-selected { border-color: #0d6efd; background: #f0f6ff; }
.homepage-option-check {
    position: absolute; top: .75rem; right: .75rem;
    color: #0d6efd; font-size: 1.1rem; opacity: 0; transition: opacity .15s ease;
}
.homepage-option.is-selected .homepage-option-check { opacity: 1; }
.homepage-option-title { display: block; font-weight: 600; margin-top: .75rem; }
.homepage-option-desc { display: block; font-size: .8rem; color: #6c757d; margin-top: .25rem; }

.homepage-preview {
    display: block; height: 5.5rem; padding: .5rem;
    background: #f8f9fa; border: 1px solid #e9ecef; border-radius: .35rem;
}
.homepage-preview-default .hp-bar { display: block; width: 40%; height: .5rem; margin-bottom: .4rem; background: #ced4da; border-radius: .15rem; }
.homepage-preview-default .hp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .25rem; }
.homepage-preview-default .hp-grid span { display: block; height: 1.1rem; background: #dee2e6; border-radius: .15rem; }

.homepage-preview-welcome .hp-hero { display: block; height: 1.3rem; margin-bottom: .35rem; background: #adb5bd; border-radius: .15rem; }
.homepage-preview-welcome .hp-dots { display: flex; gap: .3rem; margin-bottom: .35rem; }
.homepage-preview-welcome .hp-dots span { display: block; width: .6rem; height: .6rem; background: #ced4da; border-radius: 50%; }
.homepage-preview-welcome .hp-rows { display: flex; flex-direction: column; gap: .25rem; }
.homepage-preview-welcome .hp-rows span { display: block; height: .5rem; background: #dee2e6; border-radius: .15rem; }

#welcomeSectionFields.is-inactive { opacity: .5; }

/* Bootstrap의 .d-flex{display:flex!important}가 [hidden]의 display:none을 덮어써 무시되므로 명시적으로 재정의 */
#welcomeSectionNotice[hidden] { display: none !important; }
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>

<form method="post" action="/admin/welcome">
    <?= csrf_field() ?>

    <!-- 스토어 첫화면 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">스토어 첫 화면</div>
        <div class="card-body">
            <p class="text-muted small mb-3">스토어에 접속했을 때 먼저 보여줄 화면을 선택하세요. 선택에 따라 아래 섹션 설정의 적용 여부가 달라집니다.</p>
            <?php $storeHomepage = $cfg['store_homepage']['value'] ?? 'default'; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="homepage-option">
                        <input type="radio" name="store_homepage" value="default" class="visually-hidden homepage-option-input"
                               <?= $storeHomepage === 'default' ? 'checked' : '' ?>>
                        <span class="homepage-option-check"><i class="bi bi-check-circle-fill"></i></span>
                        <span class="homepage-preview homepage-preview-default" aria-hidden="true">
                            <span class="hp-bar"></span>
                            <span class="hp-grid"><span></span><span></span><span></span><span></span><span></span><span></span></span>
                        </span>
                        <span class="homepage-option-title">기본 홈</span>
                        <span class="homepage-option-desc">상품 목록 그리드만 표시합니다.<br>아래 섹션 설정은 적용되지 않습니다.</span>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="homepage-option">
                        <input type="radio" name="store_homepage" value="welcome" class="visually-hidden homepage-option-input"
                               <?= $storeHomepage === 'welcome' ? 'checked' : '' ?>>
                        <span class="homepage-option-check"><i class="bi bi-check-circle-fill"></i></span>
                        <span class="homepage-preview homepage-preview-welcome" aria-hidden="true">
                            <span class="hp-hero"></span>
                            <span class="hp-dots"><span></span><span></span><span></span><span></span></span>
                            <span class="hp-rows"><span></span><span></span><span></span></span>
                        </span>
                        <span class="homepage-option-title">Welcome 페이지</span>
                        <span class="homepage-option-desc">Hero 배너·카테고리·PICK 상품·신상품·할인 섹션으로 구성됩니다.<br>아래에서 세부 구성을 편집할 수 있습니다.</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-secondary d-flex align-items-center gap-2 py-2" id="welcomeSectionNotice" hidden>
        <i class="bi bi-info-circle"></i>
        <span>현재 <strong>기본 홈</strong>을 사용 중이라 아래 섹션 설정은 화면에 적용되지 않습니다. 저장해도 값은 유지되며, <strong>Welcome 페이지</strong>로 바꾸면 다시 적용됩니다.</span>
    </div>

    <div id="welcomeSectionFields">
    <!-- 섹션 표시 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">섹션 표시 / 숨김</div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $toggles = [
                    'welcome_show_hero'          => 'Hero 배너',
                    'welcome_show_categories'    => '카테고리 바로가기',
                    'welcome_show_featured'      => 'PICK 상품 섹션',
                    'welcome_show_new'           => '신상품 섹션',
                    'welcome_show_discount'      => '할인 상품 섹션',
                    'welcome_show_bottom_banner' => '하단 배너',
                ];
                foreach ($toggles as $key => $label):
                    $val = $cfg[$key]['value'] ?? '1';
                ?>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2 border rounded p-3 bg-light">
                        <div class="form-check form-switch mb-0">
                            <input type="hidden" name="<?= $key ?>" value="0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   name="<?= $key ?>" value="1" id="chk_<?= $key ?>"
                                   <?= $val ? 'checked' : '' ?>>
                        </div>
                        <label class="form-check-label fw-semibold" for="chk_<?= $key ?>"><?= $label ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 섹션 설정 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">섹션 제목 / 상품 수</div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $sections = [
                    'PICK 상품' => ['welcome_featured_title', 'welcome_featured_count'],
                    '신상품'    => ['welcome_new_title',      'welcome_new_count'],
                    '할인 상품' => ['welcome_discount_title', 'welcome_discount_count'],
                ];
                foreach ($sections as $sLabel => [$titleKey, $countKey]):
                    $titleVal = $cfg[$titleKey]['value'] ?? $sLabel;
                    $countVal = $cfg[$countKey]['value'] ?? '8';
                ?>
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="fw-semibold small text-muted mb-2"><?= $sLabel ?></div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">섹션 제목</label>
                            <input type="text" name="<?= $titleKey ?>" class="form-control form-control-sm"
                                   value="<?= esc($titleVal) ?>">
                        </div>
                        <div>
                            <label class="form-label small mb-1">표시 상품 수</label>
                            <input type="number" name="<?= $countKey ?>" class="form-control form-control-sm"
                                   value="<?= esc($countVal) ?>" min="1" max="24">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    </div>

    <!-- PICK 상품 관리 안내 -->
    <div class="alert alert-info d-flex align-items-center gap-2 py-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>
            PICK 상품 노출 상품은 <a href="/admin/products" class="alert-link">상품 관리</a>에서 ⭐ 버튼으로 지정합니다.
            <a href="/admin/promotions" class="alert-link">프로모션 캠페인</a>(별도 랜딩페이지)과는 다른 기능이며, 프로모션에 등록한 상품이 자동으로 여기 나오지는 않습니다.
        </span>
    </div>

    <div class="text-end">
        <a href="/welcome" target="_blank" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-eye me-1"></i>미리보기
        </a>
        <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
    </div>
</form>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
(function () {
    const radios  = document.querySelectorAll('.homepage-option-input');
    const section = document.getElementById('welcomeSectionFields');
    const notice  = document.getElementById('welcomeSectionNotice');

    function sync() {
        const selected  = document.querySelector('.homepage-option-input:checked');
        const isWelcome = !!selected && selected.value === 'welcome';

        // inert: 시각적으로만 비활성화 — 값은 그대로 제출되어 저장된 설정을 덮어쓰지 않는다.
        section.inert = !isWelcome;
        section.classList.toggle('is-inactive', !isWelcome);
        notice.hidden = isWelcome;

        radios.forEach(function (radio) {
            radio.closest('.homepage-option').classList.toggle('is-selected', radio.checked);
        });
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', sync);
    });
    sync();
})();
</script>
<?= $this->endSection() ?>
