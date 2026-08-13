<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<?php
$isSoldOut    = $product['status'] === 'sold_out' || $product['stock'] === 0;
$hasDiscount  = $product['discount_price'] !== null;
$displayPrice = $hasDiscount ? $product['discount_price'] : $product['price'];
$discountRate = $hasDiscount ? round((1 - $product['discount_price'] / $product['price']) * 100) : 0;
$primaryImage = null;
$extraImages  = [];
foreach ($images as $img) {
    if ($img['is_primary']) $primaryImage = $img;
    else $extraImages[] = $img;
}
if (! $primaryImage && ! empty($images)) {
    $primaryImage = array_shift($images);
}
$allImages = $primaryImage ? array_merge([$primaryImage], $extraImages) : [];
?>

<div class="container py-5">

    <!-- 상단: 이미지 + 구매 정보 -->
    <div class="row g-5 mb-5">

        <!-- 이미지 영역 -->
        <div class="col-lg-6">
            <?php if (! empty($allImages)): ?>

            <!-- 메인 Carousel -->
            <div id="productCarousel" class="carousel slide mb-3" data-bs-ride="false">
                <div class="carousel-inner rounded" style="background:#f8f9fa">
                    <?php foreach ($allImages as $i => $img): ?>
                    <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
                        <img src="<?= esc($img['media_url']) ?>"
                             alt="<?= esc($img['alt'] ?? $product['name']) ?>"
                             class="d-block w-100"
                             style="aspect-ratio:1;object-fit:cover">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($allImages) > 1): ?>
                <button class="carousel-control-prev" type="button" data-bs-target="#productCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#productCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon"></span>
                </button>
                <?php endif; ?>
            </div>

            <!-- 썸네일 목록 -->
            <?php if (count($allImages) > 1): ?>
            <div class="d-flex gap-2 flex-wrap">
                <?php foreach ($allImages as $i => $img): ?>
                <button type="button"
                        class="thumb-btn border rounded p-0 <?= $i === 0 ? 'border-dark border-2' : 'border-secondary' ?>"
                        style="width:64px;height:64px;overflow:hidden;background:none"
                        data-bs-target="#productCarousel"
                        data-bs-slide-to="<?= $i ?>"
                        onclick="setActiveThumb(this)">
                    <img src="<?= esc($img['media_url']) ?>"
                         alt=""
                         style="width:100%;height:100%;object-fit:cover">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="rounded d-flex align-items-center justify-content-center text-muted"
                 style="aspect-ratio:1;background:#f1f3f5">
                <i class="bi bi-image fs-1"></i>
            </div>
            <?php endif; ?>
        </div>

        <!-- 구매 정보 영역 -->
        <div class="col-lg-6">

            <!-- 카테고리 브레드크럼 -->
            <?php if (! empty($product['category_name'])): ?>
            <div class="text-muted small mb-2"><?= esc($product['category_name']) ?></div>
            <?php endif; ?>

            <!-- 상품명 -->
            <h2 class="fw-bold mb-3"><?= esc($product['name']) ?></h2>

            <!-- 가격 -->
            <div class="mb-4">
                <?php if ($hasDiscount): ?>
                <div class="text-muted text-decoration-line-through mb-1">
                    <?= number_format($product['price']) ?>원
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="fs-3 fw-bold text-danger"><?= number_format($displayPrice) ?>원</span>
                    <span class="badge bg-danger fs-6"><?= $discountRate ?>% 할인</span>
                </div>
                <?php else: ?>
                <span class="fs-3 fw-bold"><?= number_format($displayPrice) ?>원</span>
                <?php endif; ?>
            </div>

            <!-- 배송비 -->
            <div class="mb-4 pb-4 border-bottom">
                <div class="d-flex gap-2 align-items-center small">
                    <span class="text-muted" style="width:70px">배송비</span>
                    <?php if ($product['shipping_type'] === 'free'): ?>
                    <span class="text-success fw-semibold">무료배송</span>
                    <?php elseif ($product['shipping_type'] === 'fixed'): ?>
                    <span><?= number_format($product['shipping_fee']) ?>원</span>
                    <?php else: ?>
                    <span>
                        <?= number_format($product['free_threshold']) ?>원 이상 무료 /
                        미만 <?= number_format($product['shipping_fee']) ?>원
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (! empty($options)): ?>
            <!-- 옵션 선택 -->
            <div class="mb-4 pb-4 border-bottom" id="optionArea">
                <?php foreach ($options as $optGroup): ?>
                <div class="d-flex gap-2 align-items-center mb-2">
                    <span class="text-muted small" style="min-width:70px"><?= esc($optGroup['name']) ?></span>
                    <select class="form-select form-select-sm option-select"
                            data-option-id="<?= (int) $optGroup['id'] ?>"
                            onchange="onOptionChange()">
                        <option value="">선택하세요</option>
                        <?php foreach ($optGroup['values'] as $val): ?>
                        <option value="<?= (int) $val['id'] ?>"><?= esc($val['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <div id="skuInfoMsg" class="text-muted small mt-1" style="min-height:1.2em"></div>
            </div>
            <?php endif; ?>

            <!-- 재고 / 품절 표시 -->
            <?php if ($isSoldOut && empty($options)): ?>
            <div class="alert alert-secondary py-2 mb-4">
                <i class="bi bi-exclamation-circle me-1"></i>현재 품절된 상품입니다.
            </div>
            <?php elseif (empty($options)): ?>
            <div class="text-muted small mb-4">재고 <strong class="text-dark"><?= number_format($product['stock']) ?></strong>개</div>
            <?php endif; ?>

            <?php $addons = $addons ?? []; ?>
            <?php if ($addons !== []): ?>
            <!-- 추가구성상품 -->
            <div class="card mb-3" id="addonSection">
                <div class="card-header bg-light d-flex align-items-center">
                    <span class="fw-semibold">추가구성상품</span>
                    <span class="text-muted small ms-2">추가로 구매를 원하시면 선택하세요.</span>
                </div>
                <div class="card-body" id="addonListArea">
                    <?php foreach ($addons as $i => $addon): ?>
                    <div class="border rounded p-2 mb-2" data-addon-idx="<?= (int) $i ?>">
                        <div class="d-flex align-items-center">
                            <?php if ($addon['file_path']): ?>
                            <img src="<?= esc(base_url($addon['file_path'])) ?>" class="rounded me-3" style="width:56px;height:56px;object-fit:cover" alt="">
                            <?php endif; ?>
                            <?php
                            // discount_price는 0원일 수 있으므로 `?:` 대신 null 여부로만 대체 판단한다
                            // (위 메인 상품 $hasDiscount 처리와 동일한 규칙).
                            $addonPrice = $addon['discount_price'] !== null ? $addon['discount_price'] : $addon['price'];
                            ?>
                            <div class="flex-grow-1">
                                <div class="small fw-semibold"><?= esc($addon['name']) ?></div>
                                <div class="small text-danger"><?= number_format((int) $addonPrice) ?>원</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-addon-select="<?= (int) $i ?>">
                                선택
                            </button>
                        </div>
                        <?php if (! empty($addon['options'])): ?>
                        <div class="mt-2 ps-1">
                            <?php foreach ($addon['options'] as $optGroup): ?>
                            <div class="d-flex gap-2 align-items-center mb-1">
                                <span class="text-muted small" style="min-width:60px"><?= esc($optGroup['name']) ?></span>
                                <select class="form-select form-select-sm addon-option-select"
                                        data-addon-idx="<?= (int) $i ?>"
                                        data-option-id="<?= (int) $optGroup['id'] ?>">
                                    <option value="">선택하세요</option>
                                    <?php foreach ($optGroup['values'] as $val): ?>
                                    <option value="<?= (int) $val['id'] ?>"><?= esc($val['value']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div id="selectedAddons" class="mt-3"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 수량 선택 -->
            <div class="d-flex align-items-center gap-3 mb-4">
                <span class="text-muted small" style="width:70px">수량</span>
                <div class="input-group" style="width:130px">
                    <button class="btn btn-outline-secondary" type="button" id="qtyMinus" <?= $isSoldOut ? 'disabled' : '' ?>>−</button>
                    <input type="number" id="qtyInput" class="form-control text-center"
                           value="1" min="1" max="<?= (int) $product['stock'] ?>"
                           <?= $isSoldOut ? 'disabled' : '' ?>>
                    <button class="btn btn-outline-secondary" type="button" id="qtyPlus" <?= $isSoldOut ? 'disabled' : '' ?>>+</button>
                </div>
            </div>

            <!-- 총 금액 -->
            <?php if (! $isSoldOut): ?>
            <div class="d-flex align-items-center gap-2 mb-4">
                <span class="text-muted small" style="width:70px">합계</span>
                <span class="fs-5 fw-bold" id="totalPrice"><?= number_format($displayPrice) ?>원</span>
            </div>
            <?php endif; ?>

            <!-- 구매 버튼 -->
            <div class="d-grid gap-2">
                <?php if ($isSoldOut): ?>
                <button class="btn btn-secondary btn-lg" disabled>품절</button>
                <!-- 재입고 알림 -->
                <?php if (session()->get('user_id')): ?>
                <button type="button" class="btn btn-outline-secondary btn-restock"
                        data-slug="<?= esc($product['slug']) ?>"
                        data-csrf="<?= csrf_token() ?>" data-csrf-val="<?= csrf_hash() ?>">
                    <i class="bi bi-bell me-1"></i>재입고 알림 신청
                </button>
                <?php else: ?>
                <div class="input-group mt-1">
                    <input type="email" id="restockEmail" class="form-control form-control-sm"
                           placeholder="이메일 입력 후 알림 신청">
                    <button type="button" class="btn btn-outline-secondary btn-restock"
                            data-slug="<?= esc($product['slug']) ?>"
                            data-csrf="<?= csrf_token() ?>" data-csrf-val="<?= csrf_hash() ?>">
                        <i class="bi bi-bell"></i> 알림
                    </button>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <button class="btn btn-primary btn-lg" id="btnAddCart"
                        data-product-id="<?= (int) $product['id'] ?>"
                        data-csrf="<?= csrf_token() ?>"
                        data-csrf-val="<?= csrf_hash() ?>">
                    <i class="bi bi-bag-plus me-1"></i>장바구니 담기
                </button>
                <button class="btn btn-dark btn-lg" id="btnBuyNow"
                        data-product-id="<?= (int) $product['id'] ?>"
                        data-csrf="<?= csrf_token() ?>"
                        data-csrf-val="<?= csrf_hash() ?>">
                    바로구매
                </button>
                <?php endif; ?>
                <!-- 찜하기 -->
                <button class="btn btn-outline-secondary" id="btnWish"
                        data-slug="<?= esc($product['slug']) ?>"
                        data-csrf="<?= csrf_token() ?>"
                        data-csrf-val="<?= csrf_hash() ?>"
                        data-loggedin="<?= session()->get('user_id') ? 'true' : 'false' ?>">
                    <i class="bi <?= ($isWished ?? false) ? 'bi-heart-fill text-danger' : 'bi-heart' ?> me-1"></i>
                    <span id="wishLabel"><?= ($isWished ?? false) ? '찜 해제' : '찜하기' ?></span>
                </button>
            </div>

        </div>
    </div>

    <?php if (! $isSoldOut): ?>
    <!-- 모바일 스티키 구매 바 (스크롤해도 담기/합계가 항상 손닿는 곳에) -->
    <div class="d-lg-none position-fixed bottom-0 start-0 end-0 bg-white border-top d-flex align-items-center gap-2 px-2 py-2"
         id="stickyBuyBar" style="z-index:1040">
        <div class="flex-shrink-0 ps-1">
            <div class="text-muted" style="font-size:.7rem;line-height:1.1">합계</div>
            <div class="fw-bold" id="stickyTotal"><?= number_format($displayPrice) ?>원</div>
        </div>
        <button class="btn btn-primary flex-grow-1 py-2" id="btnStickyCart"
                data-product-id="<?= (int) $product['id'] ?>"
                data-csrf="<?= csrf_token() ?>" data-csrf-val="<?= csrf_hash() ?>">
            <i class="bi bi-bag-plus me-1"></i>장바구니 담기
        </button>
    </div>
    <!-- 고정 바가 본문 하단을 가리지 않도록 확보하는 여백 -->
    <div class="d-lg-none" style="height:76px"></div>
    <?php endif; ?>

    <!-- 하단: 상세정보 탭 -->
    <div class="row">
        <div class="col-12">
            <ul class="nav nav-tabs mb-0" id="detailTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabDesc">상세정보</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabShipping">배송·교환·반품</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReviews">
                        리뷰<?php if (($reviewTotal ?? 0) > 0): ?> <span class="badge bg-secondary ms-1"><?= (int) ($reviewTotal ?? 0) ?></span><?php endif; ?>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabQna">
                        상품 문의<?php if (($qnaTotal ?? 0) > 0): ?> <span class="badge bg-secondary ms-1"><?= (int) ($qnaTotal ?? 0) ?></span><?php endif; ?>
                    </button>
                </li>
            </ul>
            <div class="tab-content border border-top-0 rounded-bottom p-4">
                <div class="tab-pane fade show active" id="tabDesc">
                    <?php if (! empty($product['description'])): ?>
                    <div class="product-desc">
                        <?= $product['description'] ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">상세 내용이 없습니다.</p>
                    <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="tabShipping">
                    <?php if (! empty($shipping_policy)): ?>
                    <div style="white-space:pre-line"><?= esc($shipping_policy) ?></div>
                    <?php else: ?>
                    <p class="text-muted">배송·교환·반품 안내가 없습니다.</p>
                    <?php endif; ?>
                </div>

                <!-- 리뷰 탭 -->
                <div class="tab-pane fade" id="tabReviews">

                    <!-- 평균 평점 요약 -->
                    <?php $rSum = $ratingSummary ?? ['count' => 0, 'average' => 0]; ?>
                    <?php if (($rSum['count'] ?? 0) > 0): ?>
                    <?php $rAvg = (float) $rSum['average']; $rRound = (int) round($rAvg); ?>
                    <div class="d-flex align-items-center gap-2 mb-3 pb-3 border-bottom">
                        <span class="fs-3 fw-bold"><?= esc(number_format($rAvg, 1)) ?></span>
                        <span class="text-warning fs-5 lh-1">
                            <?php for ($s = 1; $s <= 5; $s++): ?><i class="bi bi-star<?= $s <= $rRound ? '-fill' : '' ?>"></i><?php endfor; ?>
                        </span>
                        <span class="text-muted small"><?= (int) $rSum['count'] ?>개 리뷰</span>
                    </div>
                    <?php endif; ?>

                    <!-- 작성 폼 -->
                    <?php if ($canWriteReview ?? false): ?>
                    <div class="card mb-4 border">
                        <div class="card-header bg-white fw-semibold small">리뷰 작성</div>
                        <div class="card-body">
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1 d-block">별점</label>
                                <div id="reviewStarInput" class="fs-4 text-warning lh-1" style="cursor:pointer">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="bi bi-star" data-value="<?= $s ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" id="reviewRating" value="0">
                            </div>
                            <textarea id="reviewContent" class="form-control form-control-sm mb-2" rows="4"
                                      placeholder="구매하신 상품 어떠셨나요? 사진 1장 + 30자 이상 작성 시 150 포인트가 지급됩니다."></textarea>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-1">이미지 첨부 (최대 3장)</label>
                                <input type="file" id="reviewImages" class="form-control form-control-sm"
                                       accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                <div class="form-text small">JPG·PNG·GIF·WEBP, 장당 최대 5MB</div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-sm btn-primary" id="btnReviewSubmit">리뷰 등록</button>
                            </div>
                        </div>
                    </div>
                    <?php elseif (session()->get('user_id')): ?>
                    <div class="alert alert-light text-center small mb-4">
                        배송 완료된 구매 상품에만 리뷰를 작성할 수 있습니다.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light text-center small mb-4">
                        <a href="/auth/login" class="fw-semibold">로그인</a> 후 구매한 상품의 리뷰를 작성할 수 있습니다.
                    </div>
                    <?php endif; ?>

                    <!-- AI 리뷰 요약 -->
                    <?php
                    $rSummary = $reviewSummary ?? null;
                    if (is_array($rSummary) && trim((string) ($rSummary['summary'] ?? '')) !== ''):
                        $sentiment = $rSummary['sentiment'] ?? 'mixed';
                        $sBadge    = ['positive' => ['bg-success', '긍정적'], 'negative' => ['bg-danger', '부정적'], 'mixed' => ['bg-secondary', '복합적']][$sentiment] ?? ['bg-secondary', '복합적'];
                    ?>
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <span class="fw-semibold"><i class="bi bi-stars text-primary me-1"></i>AI 리뷰 요약</span>
                                <span class="badge <?= esc($sBadge[0]) ?> ms-2"><?= esc($sBadge[1]) ?></span>
                            </div>
                            <p class="mb-3 small"><?= esc($rSummary['summary']) ?></p>
                            <div class="row g-3">
                                <?php if (! empty($rSummary['pros'])): ?>
                                <div class="col-sm-6">
                                    <div class="small fw-semibold text-success mb-1"><i class="bi bi-hand-thumbs-up me-1"></i>장점</div>
                                    <ul class="small mb-0 ps-3">
                                        <?php foreach ($rSummary['pros'] as $pro): ?>
                                        <li><?= esc($pro) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                <?php if (! empty($rSummary['cons'])): ?>
                                <div class="col-sm-6">
                                    <div class="small fw-semibold text-danger mb-1"><i class="bi bi-hand-thumbs-down me-1"></i>아쉬운 점</div>
                                    <ul class="small mb-0 ps-3">
                                        <?php foreach ($rSummary['cons'] as $con): ?>
                                        <li><?= esc($con) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted mt-3" style="font-size:.72rem">AI가 구매자 리뷰를 분석한 요약입니다.</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 리뷰 목록 -->
                    <?php if (empty($reviewItems ?? [])): ?>
                    <p class="text-muted text-center py-4">등록된 리뷰가 없습니다.</p>
                    <?php else: ?>
                    <?php $myReviewUserId = (int) (session()->get('user_id') ?? 0); ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach (($reviewItems ?? []) as $review):
                            $rName   = $review['nickname'] ?: $review['username'];
                            $rLen    = mb_strlen($rName);
                            $rMask   = mb_substr($rName, 0, 1) . str_repeat('*', min($rLen - 1, 2));
                            $isOwner = $myReviewUserId === (int) $review['user_id'];
                        ?>
                        <div class="border rounded p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <?php $rvStar = (int) ($review['rating'] ?? 0); ?>
                                    <?php if ($rvStar > 0): ?>
                                    <span class="text-warning small me-1 lh-1"><?php for ($s = 1; $s <= 5; $s++): ?><i class="bi bi-star<?= $s <= $rvStar ? '-fill' : '' ?>"></i><?php endfor; ?></span>
                                    <?php endif; ?>
                                    <span class="fw-semibold small"><?= esc($rMask) ?></span>
                                    <span class="text-muted small ms-2"><?= date('Y.m.d', strtotime($review['created_at'])) ?></span>
                                    <?php if ($review['is_rewarded']): ?>
                                    <span class="badge bg-warning text-dark ms-1 small">포인트 지급</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isOwner): ?>
                                <button class="btn btn-sm btn-outline-danger btn-review-delete"
                                        data-id="<?= $review['id'] ?>">삭제</button>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($review['images'])): ?>
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <?php foreach ($review['images'] as $img): ?>
                                <a href="<?= esc($img['image_path']) ?>" target="_blank">
                                    <img src="<?= esc($img['image_path']) ?>" alt=""
                                         style="width:80px;height:80px;object-fit:cover;border-radius:4px">
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <p class="mb-0 small" style="white-space:pre-line"><?= esc($review['content']) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $reviewTotalPages = (int) ceil(($reviewTotal ?? 0) / max(1, ($reviewPerPage ?? 10)));
                    if ($reviewTotalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center pagination-sm">
                            <?php for ($p = 1; $p <= $reviewTotalPages; $p++): ?>
                            <li class="page-item <?= $p === ($reviewPage ?? 1) ? 'active' : '' ?>">
                                <a class="page-link" href="?review_page=<?= $p ?>#tabReviews"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    <?php endif; // empty reviewItems ?>

                </div><!-- /tabReviews -->

                <!-- 상품 문의 탭 -->
                <div class="tab-pane fade" id="tabQna">

                    <!-- 작성 폼 -->
                    <?php if (session()->get('user_id')): ?>
                    <div class="card mb-4 border">
                        <div class="card-header bg-white fw-semibold small">문의 작성</div>
                        <div class="card-body">
                            <input type="text" id="qnaTitle" class="form-control form-control-sm mb-2"
                                   placeholder="제목을 입력하세요" maxlength="200">
                            <textarea id="qnaContent" class="form-control form-control-sm mb-2"
                                      rows="3" placeholder="문의 내용을 입력하세요"></textarea>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="qnaSecret">
                                    <label class="form-check-label small" for="qnaSecret">비밀글</label>
                                </div>
                                <button class="btn btn-sm btn-primary" id="btnQnaSubmit">문의 등록</button>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light text-center small mb-4">
                        <a href="/auth/login" class="fw-semibold">로그인</a> 후 문의하실 수 있습니다.
                    </div>
                    <?php endif; ?>

                    <!-- 문의 목록 -->
                    <?php if (empty($qnaItems ?? [])): ?>
                    <p class="text-muted text-center py-4">등록된 문의가 없습니다.</p>
                    <?php else: ?>
                    <?php
                    $myQnaUserId = (int) (session()->get('user_id') ?? 0);
                    ?>
                    <div class="accordion" id="qnaAccordion">
                        <?php foreach (($qnaItems ?? []) as $qna):
                            $dName   = $qna['nickname'] ?: $qna['username'];
                            $dLen    = mb_strlen($dName);
                            $dMask   = mb_substr($dName, 0, 1) . str_repeat('*', min($dLen - 1, 2));
                            $isOwner = $myQnaUserId === (int) $qna['user_id'];
                            $canSee  = ! $qna['is_secret'] || $isOwner;
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 small"
                                        type="button"
                                        <?= $canSee ? 'data-bs-toggle="collapse" data-bs-target="#qnaItem' . $qna['id'] . '"' : 'disabled' ?>>
                                    <div class="d-flex w-100 justify-content-between align-items-center me-2 gap-2">
                                        <span class="text-truncate">
                                            <?php if ($qna['is_secret']): ?>
                                            <i class="bi bi-lock-fill text-secondary me-1"></i>
                                            <?php endif; ?>
                                            <?= $canSee ? esc($qna['title']) : '비밀 문의입니다.' ?>
                                        </span>
                                        <span class="text-muted text-nowrap small flex-shrink-0">
                                            <?= esc($dMask) ?> · <?= date('Y.m.d', strtotime($qna['created_at'])) ?>
                                        </span>
                                        <span class="flex-shrink-0">
                                            <?php if ($qna['is_answered']): ?>
                                            <span class="badge bg-success">답변완료</span>
                                            <?php else: ?>
                                            <span class="badge bg-light text-secondary border">답변대기</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </button>
                            </h2>
                            <?php if ($canSee): ?>
                            <div id="qnaItem<?= $qna['id'] ?>" class="accordion-collapse collapse"
                                 data-bs-parent="#qnaAccordion">
                                <div class="accordion-body small">
                                    <p style="white-space:pre-line"><?= esc($qna['content']) ?></p>
                                    <?php if ($qna['is_answered']): ?>
                                    <div class="border-start border-success border-3 ps-3 mt-3 bg-light rounded-end py-2">
                                        <div class="fw-semibold text-success mb-1 small">답변</div>
                                        <p class="mb-1" style="white-space:pre-line"><?= esc($qna['answer'] ?? '') ?></p>
                                        <small class="text-muted"><?= date('Y.m.d H:i', strtotime((string) $qna['answered_at'])) ?></small>
                                    </div>
                                    <?php else: ?>
                                    <p class="text-muted small mb-0">아직 답변이 등록되지 않았습니다.</p>
                                    <?php endif; ?>
                                    <?php if ($isOwner): ?>
                                    <div class="mt-2 text-end">
                                        <button class="btn btn-sm btn-outline-danger btn-qna-delete"
                                                data-id="<?= $qna['id'] ?>">삭제</button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $qnaTotalPages = (int) ceil(($qnaTotal ?? 0) / max(1, ($qnaPerPage ?? 10)));
                    if ($qnaTotalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center pagination-sm">
                            <?php for ($p = 1; $p <= $qnaTotalPages; $p++): ?>
                            <li class="page-item <?= $p === ($qnaPage ?? 1) ? 'active' : '' ?>">
                                <a class="page-link" href="?qna_page=<?= $p ?>#tabQna"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>

                    <?php endif; // empty qnaItems ?>
                </div><!-- /tabQna -->
            </div>
        </div>
    </div>

</div>

<?= view('themes/default/components/recently_viewed', ['recentProducts' => $recentProducts ?? []]) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<style>
.thumb-btn { cursor: pointer; transition: border-color .15s; }
.thumb-btn:hover { border-color: #343a40 !important; }
.product-desc img { max-width: 100%; height: auto; }
</style>
<script>
// SKU 데이터 (서버에서 주입)
const skuData = <?= json_encode(array_map(function ($s) {
    return [
        'id'               => (int) $s['id'],
        'price_diff'       => (int) $s['price_diff'],
        'stock'            => (int) $s['stock'],
        'option_value_ids' => array_map('intval', $s['option_value_ids'] ?? []),
    ];
}, $skus ?? []), JSON_UNESCAPED_UNICODE) ?>;

let basePrice   = <?= (int) $displayPrice ?>;
let currentSkuId = null;

// ─── 부속 애드온 선택 ───────────────────────────────────────────────────────────
// 상품명은 관리자가 직접 입력하지 않을 수도 있다(NaverImageSearchProvider 로 외부 API에서
// 채워질 수 있음) — 신뢰할 수 없는 문자열이므로 HTML 삽입 전 반드시 이스케이프한다.
// JSON_HEX_* 플래그로 스크립트 종료 태그와 같은 값이 스크립트 블록을 탈출하지 못하게 막는다.
const addonsData = <?= json_encode(array_values(array_map(static function (array $a): array {
    // discount_price는 0원일 수 있으므로 `?:` 대신 null 여부로만 대체 판단한다.
    $price = $a['discount_price'] !== null ? $a['discount_price'] : $a['price'];

    return [
        'id'    => (int) $a['id'],
        'name'  => (string) $a['name'],
        'price' => (int) $price,
        'skus'  => array_map(static function (array $s): array {
            return [
                'id'               => (int) $s['id'],
                'price_diff'       => (int) $s['price_diff'],
                'stock'            => (int) $s['stock'],
                'option_value_ids' => array_map('intval', $s['option_value_ids'] ?? []),
                'option_label'     => (string) ($s['option_label'] ?? ''),
            ];
        }, $a['skus'] ?? []),
    ];
}, $addons)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let selectedAddons = [];

function escHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderSelectedAddons() {
    const el = document.getElementById('selectedAddons');
    if (! el) return;
    el.innerHTML = selectedAddons.map(function (a) {
        const skuAttr = ' data-addon-id="' + a.id + '" data-addon-sku="' + (a.skuId || '') + '"';
        return '<div class="d-flex align-items-center border-top pt-2 mt-2 small">'
            + '<div class="flex-grow-1">' + escHtml(a.name) + '</div>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-addon-action="dec"' + skuAttr + '>-</button>'
            + '<span class="mx-2">' + a.qty + '</span>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-addon-action="inc"' + skuAttr + '>+</button>'
            + '<span class="ms-3 fw-semibold">' + (a.price * a.qty).toLocaleString('ko-KR') + '원</span>'
            + '<button type="button" class="btn btn-sm btn-link text-danger" data-addon-action="remove"' + skuAttr + '>삭제</button>'
            + '</div>';
    }).join('');
}

// 목록의 "선택" 버튼: 이벤트 위임 + data-* 속성만 사용(속성 문자열에 데이터를
// 직접 이어붙이지 않는다 — JSON.stringify 는 작은따옴표를 이스케이프하지 않으므로
// onclick 속성 조합 방식은 상품명에 따옴표가 섞이면 속성 탈출로 이어질 수 있다).
//
// 옵션(SKU)이 있는 애드온은 본품의 onOptionChange() 와 동일한 방식으로, 선택한
// 옵션값 조합에 맞는 SKU 를 addon.skus 에서 찾아 sku_id·추가금·라벨을 함께 담는다.
document.getElementById('addonListArea')?.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-addon-select]');
    if (! btn) return;
    const idx   = parseInt(btn.dataset.addonSelect, 10);
    const addon = addonsData[idx];
    if (! addon) return;

    let skuId       = null;
    let priceDiff   = 0;
    let optionLabel = '';

    if (addon.skus && addon.skus.length > 0) {
        const selects  = document.querySelectorAll('.addon-option-select[data-addon-idx="' + idx + '"]');
        const selected = Array.from(selects).map(function (s) { return parseInt(s.value) || 0; });

        if (selected.length === 0 || selected.some(function (v) { return v === 0; })) {
            alert('옵션을 선택해주세요.');
            return;
        }

        const sku = addon.skus.find(function (s) {
            return selected.every(function (vid) { return s.option_value_ids.includes(vid); })
                && s.option_value_ids.length === selected.length;
        });

        if (! sku) {
            alert('해당 옵션 조합은 준비 중입니다.');
            return;
        }
        if (sku.stock < 1) {
            alert('품절된 옵션입니다.');
            return;
        }

        skuId       = sku.id;
        priceDiff   = sku.price_diff;
        optionLabel = sku.option_label;
    }

    const dupKey = addon.id + '_' + (skuId || 0);
    if (selectedAddons.some(function (a) { return (a.id + '_' + (a.skuId || 0)) === dupKey; })) return;

    selectedAddons.push({
        id:    addon.id,
        skuId: skuId,
        name:  addon.name + (optionLabel ? ' (' + optionLabel + ')' : ''),
        price: addon.price + priceDiff,
        qty:   1,
    });
    renderSelectedAddons();
});

document.getElementById('selectedAddons')?.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-addon-action]');
    if (! btn) return;
    const id   = parseInt(btn.dataset.addonId, 10);
    const sku  = btn.dataset.addonSku ? parseInt(btn.dataset.addonSku, 10) : null;
    const item = selectedAddons.find(function (a) { return a.id === id && (a.skuId || null) === sku; });
    if (! item) return;

    switch (btn.dataset.addonAction) {
        case 'inc':
            item.qty += 1;
            break;
        case 'dec':
            item.qty = Math.max(1, item.qty - 1);
            break;
        case 'remove':
            selectedAddons = selectedAddons.filter(function (a) { return a !== item; });
            break;
    }
    renderSelectedAddons();
});

function onOptionChange() {
    const selects = document.querySelectorAll('.option-select');
    const selected = Array.from(selects).map(function (s) { return parseInt(s.value) || 0; });
    const allPicked = selected.every(function (v) { return v > 0; });

    currentSkuId = null;
    const msg = document.getElementById('skuInfoMsg');

    if (! allPicked) {
        if (msg) msg.textContent = '';
        updatePriceDisplay(basePrice, <?= (int) $product['stock'] ?>);
        return;
    }

    // 선택한 옵션값 조합에 맞는 SKU 탐색
    const sku = skuData.find(function (s) {
        return selected.every(function (vid) { return s.option_value_ids.includes(vid); })
            && s.option_value_ids.length === selected.length;
    });

    if (! sku) {
        if (msg) msg.textContent = '해당 조합은 준비 중입니다.';
        updatePriceDisplay(basePrice, 0);
        return;
    }

    currentSkuId = sku.id;
    const finalPrice = basePrice + sku.price_diff;
    if (msg) {
        msg.textContent = sku.stock > 0
            ? '재고 ' + sku.stock + '개'
            : '품절';
    }
    updatePriceDisplay(finalPrice, sku.stock);
}

function updatePriceDisplay(price, stock) {
    const qtyInput = document.getElementById('qtyInput');
    const totalEl  = document.getElementById('totalPrice');
    const qtyMinus = document.getElementById('qtyMinus');
    const qtyPlus  = document.getElementById('qtyPlus');
    const btnCart  = document.getElementById('btnAddCart');
    const btnBuy   = document.getElementById('btnBuyNow');
    const btnSticky = document.getElementById('btnStickyCart');
    const soldOutMsg = document.querySelector('.alert.alert-secondary');

    const isSoldOut = stock < 1;

    if (qtyInput) {
        qtyInput.disabled = isSoldOut;
        qtyInput.max      = stock;
        if (parseInt(qtyInput.value) > stock) qtyInput.value = Math.max(1, stock);
    }
    if (qtyMinus) qtyMinus.disabled = isSoldOut;
    if (qtyPlus)  qtyPlus.disabled  = isSoldOut;
    if (btnCart)  btnCart.disabled   = isSoldOut;
    if (btnBuy)   btnBuy.disabled    = isSoldOut;
    if (btnSticky) btnSticky.disabled = isSoldOut;
    if (totalEl) {
        const qty = parseInt(qtyInput?.value || 1);
        totalEl.textContent = (price * qty).toLocaleString('ko-KR') + '원';
        const stickyEl = document.getElementById('stickyTotal');
        if (stickyEl) stickyEl.textContent = totalEl.textContent;
    }

    // 현재 unitPrice 업데이트 (수량 변경 핸들러가 참조)
    window._currentUnitPrice = price;
    window._currentMaxQty    = stock;
}

(function () {
    window._currentUnitPrice = basePrice;
    window._currentMaxQty    = <?= (int) $product['stock'] ?>;

    const qtyInput = document.getElementById('qtyInput');
    const totalEl  = document.getElementById('totalPrice');

    function updateTotal() {
        if (! qtyInput || ! totalEl) return;
        const maxQty = window._currentMaxQty || 0;
        const qty    = Math.max(1, Math.min(parseInt(qtyInput.value) || 1, maxQty || 9999));
        qtyInput.value = qty;
        totalEl.textContent = ((window._currentUnitPrice || basePrice) * qty).toLocaleString('ko-KR') + '원';
        const stickyEl = document.getElementById('stickyTotal');
        if (stickyEl) stickyEl.textContent = totalEl.textContent;
    }

    document.getElementById('qtyMinus')?.addEventListener('click', function () {
        qtyInput.value = Math.max(1, parseInt(qtyInput.value) - 1);
        updateTotal();
    });
    document.getElementById('qtyPlus')?.addEventListener('click', function () {
        const max = window._currentMaxQty || 9999;
        qtyInput.value = Math.min(max, parseInt(qtyInput.value) + 1);
        updateTotal();
    });
    qtyInput?.addEventListener('input', updateTotal);
})();

function setActiveThumb(btn) {
    document.querySelectorAll('.thumb-btn').forEach(function (b) {
        b.classList.remove('border-dark', 'border-2');
        b.classList.add('border-secondary');
    });
    btn.classList.remove('border-secondary');
    btn.classList.add('border-dark', 'border-2');
}

// 캐러셀 슬라이드 전환 시 썸네일 동기화
document.getElementById('productCarousel')?.addEventListener('slide.bs.carousel', function (e) {
    const thumbs = document.querySelectorAll('.thumb-btn');
    thumbs.forEach(function (b, i) {
        b.classList.toggle('border-dark', i === e.to);
        b.classList.toggle('border-2',    i === e.to);
        b.classList.toggle('border-secondary', i !== e.to);
    });
});

// ─── 장바구니 담기 / 바로구매 ─────────────────────────────────────────────────
function addToCart(btn, onSuccess) {
    // 옵션이 있는데 SKU를 선택하지 않았으면 안내
    const hasOptions = skuData.length > 0;
    if (hasOptions && currentSkuId === null) {
        const selects = document.querySelectorAll('.option-select');
        const unselected = Array.from(selects).find(function (s) { return ! s.value; });
        if (unselected) {
            const label = unselected.previousElementSibling?.textContent?.trim() || '옵션';
            alert(label + '을(를) 선택해주세요.');
            return;
        }
    }

    const qty = parseInt(document.getElementById('qtyInput')?.value || 1);
    const body = new FormData();
    body.append(btn.dataset.csrf, btn.dataset.csrfVal);
    body.append('product_id', btn.dataset.productId);
    body.append('qty', qty);
    if (currentSkuId) body.append('sku_id', currentSkuId);
    selectedAddons.forEach(function (a, i) {
        body.append('addons[' + i + '][product_id]', a.id);
        body.append('addons[' + i + '][qty]', a.qty);
        if (a.skuId) body.append('addons[' + i + '][sku_id]', a.skuId);
    });

    btn.disabled = true;
    fetch('/cart/add-bundle', { method: 'POST', body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.skipped && data.skipped.length > 0) {
                alert(data.skipped.join('\n'));
            }
            if (data.success) {
                // 네비바 장바구니 뱃지 업데이트
                const badge = document.getElementById('cartBadge');
                if (badge) {
                    badge.textContent  = data.cartCount;
                    badge.style.display = data.cartCount > 0 ? '' : 'none';
                }
                selectedAddons = [];
                renderSelectedAddons();
                onSuccess(data);
            } else {
                alert(data.message);
                btn.disabled = false;
            }
        })
        .catch(function () {
            alert('오류가 발생했습니다. 다시 시도해주세요.');
            btn.disabled = false;
        });
}

document.getElementById('btnAddCart')?.addEventListener('click', function () {
    const btn = this;
    addToCart(btn, function () {
        btn.innerHTML    = '<i class="bi bi-check-lg me-1"></i>담기 완료';
        setTimeout(function () {
            btn.innerHTML = '<i class="bi bi-bag-plus me-1"></i>장바구니 담기';
            btn.disabled  = false;
        }, 1500);
    });
});

document.getElementById('btnBuyNow')?.addEventListener('click', function () {
    addToCart(this, function () {
        window.location.href = '/cart';
    });
});

// 모바일 스티키 바 담기 — 메인 담기와 동일 동작(옵션·추가구성 전역 상태 재사용)
document.getElementById('btnStickyCart')?.addEventListener('click', function () {
    const btn = this;
    addToCart(btn, function () {
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>담기 완료';
        setTimeout(function () {
            btn.innerHTML = '<i class="bi bi-bag-plus me-1"></i>장바구니 담기';
            btn.disabled  = false;
        }, 1500);
    });
});

// ─── 리뷰 ─────────────────────────────────────────────────────────────────────
// 별점 입력 인터랙션
(function () {
    const wrap = document.getElementById('reviewStarInput');
    if (! wrap) return;
    const hidden = document.getElementById('reviewRating');
    const stars  = wrap.querySelectorAll('i');
    const paint  = function (val) {
        stars.forEach(function (st) {
            const v = parseInt(st.dataset.value, 10);
            st.classList.toggle('bi-star-fill', v <= val);
            st.classList.toggle('bi-star', v > val);
        });
    };
    stars.forEach(function (st) {
        st.addEventListener('mouseenter', function () { paint(parseInt(st.dataset.value, 10)); });
        st.addEventListener('click', function () {
            hidden.value = st.dataset.value;
            paint(parseInt(st.dataset.value, 10));
        });
    });
    wrap.addEventListener('mouseleave', function () { paint(parseInt(hidden.value, 10) || 0); });
})();

document.getElementById('btnReviewSubmit')?.addEventListener('click', function () {
    const rating = parseInt(document.getElementById('reviewRating')?.value ?? '0', 10) || 0;
    if (rating < 1 || rating > 5) { alert('별점을 선택해주세요.'); return; }

    const content = (document.getElementById('reviewContent')?.value ?? '').trim();
    if (! content) { alert('리뷰 내용을 입력해주세요.'); return; }

    const files = document.getElementById('reviewImages')?.files ?? [];
    if (files.length > 3) { alert('이미지는 최대 3장까지 첨부할 수 있습니다.'); return; }

    const fd = new FormData();
    fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
    fd.append('rating', rating);
    fd.append('content', content);
    for (let i = 0; i < files.length; i++) fd.append('images[]', files[i]);

    fetch('/shop/<?= esc($product['slug']) ?>/review', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) { alert(data.message); if (data.success) location.reload(); })
        .catch(function () { alert('오류가 발생했습니다.'); });
});

document.querySelectorAll('.btn-review-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (! confirm('리뷰를 삭제하시겠습니까? 지급된 포인트도 회수됩니다.')) return;
        const fd = new FormData();
        fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
        fetch('/shop/<?= esc($product['slug']) ?>/review/' + btn.dataset.id + '/delete',
              { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) location.reload();
                else alert(data.message || '삭제에 실패했습니다.');
            })
            .catch(function () { alert('오류가 발생했습니다.'); });
    });
});

// ─── 상품 문의 ─────────────────────────────────────────────────────────────────
document.getElementById('btnQnaSubmit')?.addEventListener('click', function () {
    const title   = (document.getElementById('qnaTitle')?.value  ?? '').trim();
    const content = (document.getElementById('qnaContent')?.value ?? '').trim();
    const secret  = document.getElementById('qnaSecret')?.checked ? 1 : 0;

    if (! title || ! content) {
        alert('제목과 내용을 입력해주세요.');
        return;
    }

    const fd = new FormData();
    fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
    fd.append('title',     title);
    fd.append('content',   content);
    fd.append('is_secret', secret);

    fetch('/shop/<?= esc($product['slug']) ?>/qna', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            alert(data.message);
            if (data.success) location.reload();
        })
        .catch(function () { alert('오류가 발생했습니다.'); });
});

document.querySelectorAll('.btn-qna-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (! confirm('문의를 삭제하시겠습니까?')) return;
        const fd = new FormData();
        fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
        fetch('/shop/<?= esc($product['slug']) ?>/qna/' + btn.dataset.id + '/delete',
              { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) location.reload();
                else alert(data.message || '삭제에 실패했습니다.');
            })
            .catch(function () { alert('오류가 발생했습니다.'); });
    });
});

// hash에 따라 해당 탭 활성화
(function () {
    const hash = window.location.hash;
    if (hash === '#tabQna' || hash === '#tabReviews') {
        const trigger = document.querySelector('[data-bs-target="' + hash + '"]');
        if (trigger) new bootstrap.Tab(trigger).show();
    }
})();

// ─── 재입고 알림 ───────────────────────────────────────────────────────────────
(function () {
    document.querySelectorAll('.btn-restock').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var slug       = btn.dataset.slug;
            var emailInput = document.getElementById('restockEmail');
            var email      = '';
            if (emailInput) {
                email = emailInput.value.trim();
                if (! email) { alert('이메일을 입력해주세요.'); emailInput.focus(); return; }
                if (! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    alert('올바른 이메일 형식을 입력해주세요.'); return;
                }
            }
            var fd = new FormData();
            fd.append(btn.dataset.csrf, btn.dataset.csrfVal);
            if (email) fd.append('email', email);
            btn.disabled = true;
            fetch('/shop/' + slug + '/restock-alert', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    alert(data.message);
                    if (data.success && ! data.already) {
                        btn.innerHTML = '<i class="bi bi-bell-fill me-1"></i>알림 신청 완료';
                    } else {
                        btn.disabled = false;
                    }
                })
                .catch(function () {
                    alert('오류가 발생했습니다. 다시 시도해주세요.');
                    btn.disabled = false;
                });
        });
    });
})();

// ─── 찜하기 ───────────────────────────────────────────────────────────────────
(function () {
    var btn = document.getElementById('btnWish');
    if (! btn) return;
    btn.addEventListener('click', function () {
        if (btn.dataset.loggedin !== 'true') {
            if (confirm('로그인이 필요합니다. 로그인 페이지로 이동할까요?')) {
                window.location.href = '/auth/login';
            }
            return;
        }
        var fd = new FormData();
        fd.append(btn.dataset.csrf, btn.dataset.csrfVal);
        fetch('/shop/' + btn.dataset.slug + '/wish', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (! data.success) return;
                var icon  = btn.querySelector('i');
                var label = document.getElementById('wishLabel');
                if (data.wished) {
                    icon.className  = 'bi bi-heart-fill text-danger me-1';
                    label.textContent = '찜 해제';
                } else {
                    icon.className  = 'bi bi-heart me-1';
                    label.textContent = '찜하기';
                }
            });
    });
})();
</script>
<?= $this->endSection() ?>
