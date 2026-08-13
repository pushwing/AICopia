<?php
/**
 * 개인화 추천 상품 블록 (재사용 partial).
 *
 * @var array  $recommended  RecommendationService::forUser() 결과
 * @var string $recoTitle    블록 제목 (선택)
 */
$recommended = $recommended ?? [];
if (empty($recommended)) {
    return;
}
$recoTitle = $recoTitle ?? '회원님을 위한 추천 상품';
?>
<div class="mb-4">
    <h6 class="fw-bold mb-3"><i class="bi bi-stars text-primary me-1"></i><?= esc($recoTitle) ?></h6>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
        <?php foreach ($recommended as $p): ?>
        <?php /* 추천 블록은 할인율 배지·카테고리 없이 이미지+이름+가격만 보여 준다 */ ?>
        <?= view('shop/components/product_card', ['p' => $p, 'card_discountBadge' => false]) ?>
        <?php endforeach; ?>
    </div>
</div>
