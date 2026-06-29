<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container">
        <!-- 로고 -->
        <?php if (!empty($settings['site_logo'])): ?>
            <a class="navbar-brand" href="/"><img src="/<?= esc($settings['site_logo']) ?>" alt="<?= esc($settings['site_name']) ?>" style="height:40px"></a>
        <?php else: ?>
            <a class="navbar-brand fw-bold" href="/"><?= esc($settings['site_name'] ?? '') ?></a>
        <?php endif; ?>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($menus as $menu): ?>
                <?php if ($menu['url'] === '#categories'): ?>
                <?php if (!empty($categories)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="/shop" data-bs-toggle="dropdown">
                        <?= esc($menu['title']) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="/shop">전체 상품</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($categories as $cat): ?>
                            <?php if (!empty($cat['children'])): ?>
                            <li><a class="dropdown-item fw-semibold" href="/shop?category_id=<?= $cat['id'] ?>"><?= esc($cat['name']) ?></a></li>
                            <?php foreach ($cat['children'] as $child): ?>
                            <li><a class="dropdown-item ps-4 text-muted" href="/shop?category_id=<?= $child['id'] ?>"><?= esc($child['name']) ?></a></li>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <li><a class="dropdown-item" href="/shop?category_id=<?= $cat['id'] ?>"><?= esc($cat['name']) ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php elseif (!empty($menu['children'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <?= esc($menu['title']) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <?php foreach ($menu['children'] as $child): ?>
                        <li>
                            <a class="dropdown-item" href="<?= esc($child['url']) ?>" target="<?= esc($child['target']) ?>">
                                <?= esc($child['title']) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= esc($menu['url']) ?>" target="<?= esc($menu['target']) ?>">
                        <?= esc($menu['title']) ?>
                    </a>
                </li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <!-- 우측 -->
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($settings['phone'])): ?>
                    <a href="tel:<?= esc($settings['phone']) ?>" class="btn btn-outline-primary btn-sm d-none d-lg-inline-flex">
                        <i class="bi bi-telephone me-1"></i><?= esc($settings['phone']) ?>
                    </a>
                <?php endif; ?>
                <?php if ($authUser['loggedIn']): ?>
                    <?php if ($authUser['role'] === 'admin'): ?>
                        <a href="/admin" class="btn btn-sm btn-outline-warning">관리자</a>
                    <?php endif; ?>
                    <a href="/cart" class="btn btn-sm btn-outline-secondary position-relative" title="장바구니">
                        <i class="bi bi-cart2"></i>
                        <?php if (($cartCount ?? 0) > 0): ?>
                        <span id="cartBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="font-size:.6rem;padding:.2em .4em">
                            <?= (int) $cartCount ?>
                        </span>
                        <?php else: ?>
                        <span id="cartBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="font-size:.6rem;padding:.2em .4em;display:none">0</span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person me-1"></i><?= esc($authUser['nickname']) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/mypage/orders">
                                <i class="bi bi-bag me-2"></i>주문내역
                            </a></li>
                            <li><a class="dropdown-item" href="/mypage/coupons">
                                <i class="bi bi-ticket-perforated me-2"></i>쿠폰
                            </a></li>
                            <li><a class="dropdown-item" href="/mypage/points">
                                <i class="bi bi-star me-2"></i>포인트
                            </a></li>
                            <li><a class="dropdown-item" href="/mypage/addresses">
                                <i class="bi bi-geo-alt me-2"></i>배송지 관리
                            </a></li>
                            <li><a class="dropdown-item" href="/auth/profile">
                                <i class="bi bi-person-gear me-2"></i>내 정보
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/auth/logout">
                                <i class="bi bi-box-arrow-right me-2"></i>로그아웃
                            </a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="/auth/login" class="btn btn-sm btn-outline-secondary">로그인</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
