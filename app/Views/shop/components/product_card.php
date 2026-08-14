<?php
/**
 * 공용 상품 카드 partial — 목록·홈·추천·찜 등 모든 상품 카드의 단일 소스.
 *
 * 그동안 list·welcome(3밴드)·home(2밴드)·recommend·wishlist 에 같은 카드 마크업이
 * 6벌 복제돼 있었다. 이미지 폴백·품절 스크림·할인 배지·가격 포맷 같은 조각을
 * 한곳에서 관리하려고 통합한다. 표면별 차이(찜/액션 버튼·PICK·배지 위치·배송 배지 등)는
 * 아래 옵션으로 흡수한다. 시각 결과는 기존과 동일하며, 링크 구조만 "카드 안쪽 앵커"로
 * 통일했다(예전 welcome/home 신상품·할인 밴드는 앵커가 카드 바깥이었다).
 *
 * 필수:
 * @var array $p  상품 배열. slug·name·price·discount_price·status·stock·primary_image 사용.
 *                카테고리 배지엔 category_name, 조건부 배송엔 shipping_type·free_threshold,
 *                찜/빠른담기엔 id·has_options 를 추가로 사용한다.
 *
 * 선택 옵션(호출부에서 넘긴 값이 기본값을 덮어씀):
 * @var bool   $card_link          카드 전체를 상세 링크(앵커)로 감쌀지 (기본 true)
 * @var bool   $card_category      카테고리 라벨 줄 노출 (기본 false)
 * @var bool   $card_discountBadge 이미지 위 할인율(%) 배지 노출 (기본 true)
 * @var string $card_badgePos      할인율 배지 위치 'left'|'right' (기본 'right')
 * @var bool   $card_pick          PICK 배지(PICK 상품) (기본 false)
 * @var bool   $card_scrim         품절 스크림 노출 (기본 true)
 * @var string $card_shipping      배송 배지 'none'|'free'|'full' (기본 'none'; full=무료+조건부)
 * @var string $card_wish          찜 버튼 'none'|'toggle'|'remove' (기본 'none')
 * @var array  $card_wishedIds     toggle 모드에서 찜된 상품 id 목록
 * @var string $card_action        하단 액션 'none'|'quick' (기본 'none')
 * @var string $card_colId         col 래퍼의 id 속성값(찜 목록 제자리 제거용) (기본 '')
 */
$card_link          ??= true;
$card_category      ??= false;
$card_discountBadge ??= true;
$card_badgePos      ??= 'right';
$card_pick          ??= false;
$card_scrim         ??= true;
$card_shipping      ??= 'none';
$card_wish          ??= 'none';
$card_wishedIds     ??= [];
$card_action        ??= 'none';
$card_colId         ??= '';

$isSoldOut    = $p['status'] === 'sold_out' || (int) $p['stock'] === 0;
$displayPrice = $p['discount_price'] ?? $p['price'];
$hasDiscount  = $p['discount_price'] !== null;
$showScrim    = $card_scrim && $isSoldOut;
// PICK·찜·빠른담기 등 카드 위/아래에 겹치거나 붙는 요소가 있으면 position-relative 가 필요하다.
$needsRelative = $card_pick || $card_wish !== 'none' || $card_action !== 'none';
?>
<div class="col"<?= $card_colId !== '' ? ' id="' . esc($card_colId, 'attr') . '"' : '' ?>>
    <div class="card h-100 product-card<?= $needsRelative ? ' position-relative' : '' ?>">

        <?php if ($card_pick): ?>
        <span class="badge bg-danger position-absolute" style="top:8px;left:8px;z-index:1">PICK</span>
        <?php endif; ?>

        <?php if ($card_wish === 'toggle'):
            $isWishedItem = in_array((int) $p['id'], $card_wishedIds, true); ?>
        <button class="btn-wish position-absolute btn btn-sm"
                style="top:6px;right:6px;z-index:2;padding:2px 6px;background:rgba(255,255,255,.92);border:none;box-shadow:0 1px 3px rgba(0,0,0,.18)"
                data-slug="<?= esc($p['slug']) ?>"
                data-csrf="<?= csrf_token() ?>"
                data-csrf-val="<?= csrf_hash() ?>"
                data-loggedin="<?= session()->get('user_id') ? 'true' : 'false' ?>"
                aria-pressed="<?= $isWishedItem ? 'true' : 'false' ?>"
                aria-label="<?= $isWishedItem ? '찜 해제' : '찜하기' ?>"
                title="<?= $isWishedItem ? '찜 해제' : '찜하기' ?>">
            <i class="bi <?= $isWishedItem ? 'bi-heart-fill' : 'bi-heart' ?> text-danger"></i>
        </button>
        <?php elseif ($card_wish === 'remove'): ?>
        <button class="btn-wish-remove btn btn-sm position-absolute"
                style="top:6px;right:6px;z-index:2;padding:2px 6px;background:rgba(255,255,255,.85);border:none"
                data-slug="<?= esc($p['slug']) ?>"
                data-item="<?= (int) $p['id'] ?>"
                data-csrf="<?= csrf_token() ?>"
                data-csrf-val="<?= csrf_hash() ?>"
                title="찜 해제">
            <i class="bi bi-heart-fill text-danger"></i>
        </button>
        <?php endif; ?>

        <?php if ($card_link): ?>
        <a href="/shop/<?= esc($p['slug']) ?>" class="text-decoration-none text-dark<?= $card_action !== 'none' ? ' flex-grow-1' : '' ?>">
        <?php endif; ?>

            <div class="position-relative" style="aspect-ratio:1;overflow:hidden;background:#f8f9fa">
                <?php if (! empty($p['primary_image'])): ?>
                <img src="<?= esc($p['primary_image']) ?>" alt="<?= esc($p['name']) ?>"
                     style="width:100%;height:100%;object-fit:cover" loading="lazy">
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    <i class="bi bi-image fs-1"></i>
                </div>
                <?php endif; ?>

                <?php if ($showScrim): ?>
                <div class="product-soldout-scrim">
                    <span class="badge bg-dark fs-6">품절</span>
                </div>
                <?php endif; ?>

                <?php if ($card_discountBadge && $hasDiscount && ! $showScrim):
                    $rate = round((1 - $p['discount_price'] / $p['price']) * 100); ?>
                <span class="badge bg-danger position-absolute" style="top:8px;<?= $card_badgePos === 'left' ? 'left' : 'right' ?>:8px"><?= $rate ?>%</span>
                <?php endif; ?>
            </div>

            <div class="card-body p-2">
                <?php if ($card_category): ?>
                <div class="small text-muted mb-1"><?= esc($p['category_name'] ?? '') ?></div>
                <?php endif; ?>
                <div class="fw-semibold text-truncate fs-compact"><?= esc($p['name']) ?></div>
                <div class="mt-1">
                    <?php if ($hasDiscount): ?>
                    <span class="text-muted text-decoration-line-through small"><?= number_format($p['price']) ?>원</span>
                    <span class="text-danger fw-bold ms-1"><?= number_format($displayPrice) ?>원</span>
                    <?php else: ?>
                    <span class="fw-bold"><?= number_format($displayPrice) ?>원</span>
                    <?php endif; ?>
                </div>

                <?php if ($card_shipping !== 'none' && ($p['shipping_type'] ?? '') === 'free'): ?>
                <span class="badge bg-light text-success border border-success small mt-1">무료배송</span>
                <?php elseif ($card_shipping === 'full' && ($p['shipping_type'] ?? '') === 'conditional'): ?>
                <span class="badge bg-light text-secondary border small mt-1"><?= number_format((int) $p['free_threshold']) ?>원 이상 무료</span>
                <?php endif; ?>
            </div>

        <?php if ($card_link): ?>
        </a>
        <?php endif; ?>

        <?php if ($card_action === 'quick' && ! $isSoldOut): ?>
            <?php if ($p['has_options']): ?>
            <a href="/shop/<?= esc($p['slug']) ?>"
               class="btn btn-sm btn-outline-secondary w-100 py-2"
               style="border-radius:0 0 calc(.75rem - 1px) calc(.75rem - 1px)">
                <i class="bi bi-bag"></i> 옵션 선택
            </a>
            <?php else: ?>
            <button type="button"
                    class="btn btn-sm btn-primary w-100 py-2 btn-quick-cart"
                    style="border-radius:0 0 calc(.75rem - 1px) calc(.75rem - 1px)"
                    data-product-id="<?= $p['id'] ?>"
                    data-csrf="<?= csrf_token() ?>"
                    data-csrf-val="<?= csrf_hash() ?>">
                <i class="bi bi-cart-plus"></i> 담기
            </button>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
