<?php
/**
 * 마이페이지 공통 좌측 메뉴.
 *
 * 주문 내역·찜·쿠폰·포인트·배송지·내 정보 화면에서 함께 include 해
 * 어느 화면에 있든 나머지 마이페이지 메뉴로 바로 이동할 수 있게 한다.
 * 현재 URI 로 활성 항목을 판별한다(주문 상세 등 하위 경로 포함).
 */
$currentUri = '/' . trim(uri_string(), '/');

$mypageMenu = [
    ['url' => '/mypage/orders',    'icon' => 'bi-bag',               'label' => '주문 내역'],
    ['url' => '/mypage/wishlist',  'icon' => 'bi-heart',             'label' => '찜한 상품'],
    ['url' => '/mypage/coupons',   'icon' => 'bi-ticket-perforated', 'label' => '쿠폰'],
    ['url' => '/mypage/points',    'icon' => 'bi-star',              'label' => '포인트'],
    ['url' => '/mypage/addresses', 'icon' => 'bi-geo-alt',           'label' => '배송지 관리'],
    ['url' => '/auth/profile',     'icon' => 'bi-person-gear',       'label' => '내 정보'],
];
?>
<aside class="mypage-sidebar mb-3 mb-lg-0">
    <div class="card">
        <div class="card-header bg-white py-3 fw-bold">
            <i class="bi bi-person-circle me-2"></i>
            <?php if (! empty($authUser['nickname'])): ?>
            <?= esc($authUser['nickname']) ?>님
            <?php else: ?>
            마이페이지
            <?php endif; ?>
        </div>
        <nav class="list-group list-group-flush">
            <?php foreach ($mypageMenu as $item):
                $isActive = $currentUri === $item['url'] || str_starts_with($currentUri, $item['url'] . '/');
            ?>
            <a href="<?= esc($item['url']) ?>"
               class="list-group-item list-group-item-action d-flex align-items-center <?= $isActive ? 'active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <i class="bi <?= esc($item['icon']) ?>"></i><?= esc($item['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</aside>