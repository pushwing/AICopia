<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = 'Welcome 페이지 설정' ?>
<?= $this->section('content') ?>

<form method="post" action="/admin/welcome">
    <?= csrf_field() ?>

    <!-- 스토어 첫화면 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">스토어 첫화면</div>
        <div class="card-body" style="max-width:400px">
            <label class="form-label small fw-semibold">스토어 접속 시 첫 화면</label>
            <select name="store_homepage" class="form-select form-select-sm">
                <option value="default" <?= ($cfg['store_homepage']['value'] ?? 'default') === 'default' ? 'selected' : '' ?>>기본 홈</option>
                <option value="welcome" <?= ($cfg['store_homepage']['value'] ?? 'default') === 'welcome' ? 'selected' : '' ?>>Welcome 페이지</option>
            </select>
            <div class="form-text">기본 홈: 상품 목록 페이지 / Welcome: 기획전·신상품·할인 섹션 페이지</div>
        </div>
    </div>

    <!-- 섹션 표시 -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">섹션 표시 / 숨김</div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $toggles = [
                    'welcome_show_hero'          => 'Hero 배너',
                    'welcome_show_categories'    => '카테고리 바로가기',
                    'welcome_show_featured'      => '기획전 섹션',
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
                    '기획전'    => ['welcome_featured_title', 'welcome_featured_count'],
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

    <!-- 기획전 상품 관리 안내 -->
    <div class="alert alert-info d-flex align-items-center gap-2 py-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>기획전 노출 상품은 <a href="/admin/products" class="alert-link">상품 관리</a>에서 ⭐ 버튼으로 지정합니다.</span>
    </div>

    <div class="text-end">
        <a href="/welcome" target="_blank" class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-eye me-1"></i>미리보기
        </a>
        <button type="submit" class="btn btn-primary btn-sm px-4">저장</button>
    </div>
</form>

<?= $this->endSection() ?>
