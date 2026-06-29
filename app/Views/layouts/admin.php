<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-name"  content="<?= csrf_token() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>"><?php // session CSRF — 액션 후 page reload 시 갱신 ?>
    <title><?= esc($pageTitle ?? '관리자') ?> | <?= esc($settings['site_name'] ?? 'Admin') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --sidebar-w: 220px; }
        body  { background: #f1f3f5; }
        #sidebar {
            width: var(--sidebar-w); height: 100vh;
            position: fixed; top: 0; left: 0;
            overflow-y: auto; overflow-x: hidden;
            background: #1e2a38; position: fixed; top: 0; left: 0;
        }
        #sidebar .brand { padding: 1rem 1.2rem; color: #fff; font-weight: 700; font-size: 1.1rem; border-bottom: 1px solid #2d3f54; }
        #sidebar .nav-link { color: #a8b7c7; padding: .5rem 1.2rem; font-size: .875rem; }
        #sidebar .nav-link:hover,
        #sidebar .nav-link.active { color: #fff; background: #2d3f54; }
        #sidebar .nav-section { color: #5a7080; font-size: .7rem; padding: .8rem 1.2rem .2rem; letter-spacing: .06em; text-transform: uppercase; }
        #sidebar .nav-section-toggle {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%; background: none; border: none; cursor: pointer;
            color: #5a7080; font-size: .7rem; padding: .8rem 1.2rem .2rem;
            letter-spacing: .06em; text-transform: uppercase;
        }
        #sidebar .nav-section-toggle:hover { color: #8aa0b0; }
        #sidebar .nav-section-toggle .bi-chevron-down { transition: transform .2s; font-size: .65rem; }
        #sidebar .nav-section-toggle.collapsed .bi-chevron-down { transform: rotate(-90deg); }
        #content { margin-left: var(--sidebar-w); padding: 1.5rem; }
        #topbar  { background: #fff; border-bottom: 1px solid #e9ecef; padding: .6rem 1.5rem; margin-left: var(--sidebar-w); position: sticky; top: 0; z-index: 100; }
        .badge-dot { width: 8px; height: 8px; border-radius: 50%; background: #dc3545; display: inline-block; }
        .badge-bronze { background-color: #a0522d; color: #fff; }
        .badge-silver { background-color: #888f94; color: #fff; }
        #sidebar .nav-subgroup { color: #4e6678; font-size: .67rem; font-weight: 600; padding: .55rem 1.2rem .1rem 1.5rem; letter-spacing: .05em; text-transform: uppercase; }
        #sidebar .nav-sublink  { color: #a8b7c7; padding: .38rem 1.2rem .38rem 2.1rem; font-size: .855rem; display: block; text-decoration: none; }
        #sidebar .nav-sublink:hover, #sidebar .nav-sublink.active { color: #fff; background: #2d3f54; }
    </style>
    <?= $this->renderSection('styles') ?>
</head>
<body>

<div id="sidebar">
    <div class="brand"><i class="bi bi-grid-3x3-gap-fill me-2"></i><?= esc($settings['site_name'] ?? 'Admin') ?></div>
    <?php
        $uri = uri_string();
        $inContent  = in_array(true, [
            $uri === 'admin/dashboard', $uri === 'admin',
            str_starts_with($uri, 'admin/pages'), str_starts_with($uri, 'admin/boards'),
            str_starts_with($uri, 'admin/posts'), str_starts_with($uri, 'admin/media'),
            str_starts_with($uri, 'admin/banners'), str_starts_with($uri, 'admin/popups'),
            str_starts_with($uri, 'admin/welcome'),
        ]);
        $inShop     = in_array(true, [
            str_starts_with($uri, 'admin/products'), str_starts_with($uri, 'admin/promotions'),
            str_starts_with($uri, 'admin/suppliers'), str_starts_with($uri, 'admin/inventory'),
            str_starts_with($uri, 'admin/orders'), str_starts_with($uri, 'admin/sales'),
            str_starts_with($uri, 'admin/coupons'), str_starts_with($uri, 'admin/points'),
        ]);
        $inOps      = in_array(true, [
            str_starts_with($uri, 'admin/users'), str_starts_with($uri, 'admin/grade'),
            str_starts_with($uri, 'admin/inquiries'), str_starts_with($uri, 'admin/qna'),
            str_starts_with($uri, 'admin/stats'), str_starts_with($uri, 'admin/menus'),
        ]);
        $inSettings = str_starts_with($uri, 'admin/settings') || str_starts_with($uri, 'admin/schedule');
    ?>
    <nav class="mt-2">
        <!-- 콘텐츠 -->
        <button class="nav-section-toggle <?= $inContent ? '' : 'collapsed' ?>"
                type="button" data-bs-toggle="collapse" data-bs-target="#sec-content"
                aria-expanded="<?= $inContent ? 'true' : 'false' ?>">
            콘텐츠 <i class="bi bi-chevron-down"></i>
        </button>
        <div class="collapse <?= $inContent ? 'show' : '' ?>" id="sec-content">
            <div class="nav-subgroup">기본</div>
            <a href="/admin/dashboard" class="nav-sublink <?= $uri === 'admin/dashboard' || $uri === 'admin' ? 'active' : '' ?>"><i class="bi bi-speedometer2 me-2"></i>대시보드</a>
            <a href="/admin/pages"     class="nav-sublink <?= str_starts_with($uri, 'admin/pages')  ? 'active' : '' ?>"><i class="bi bi-file-text me-2"></i>페이지 관리</a>
            <a href="/admin/media"     class="nav-sublink <?= str_starts_with($uri, 'admin/media')  ? 'active' : '' ?>"><i class="bi bi-images me-2"></i>미디어</a>
            <div class="nav-subgroup">게시판</div>
            <a href="/admin/boards"    class="nav-sublink <?= str_starts_with($uri, 'admin/boards') ? 'active' : '' ?>"><i class="bi bi-card-list me-2"></i>게시판 관리</a>
            <a href="/admin/posts"     class="nav-sublink <?= str_starts_with($uri, 'admin/posts')  ? 'active' : '' ?>"><i class="bi bi-file-earmark-text me-2"></i>전체 게시물</a>
            <div class="nav-subgroup">마케팅</div>
            <a href="/admin/banners"   class="nav-sublink <?= str_starts_with($uri, 'admin/banners') ? 'active' : '' ?>"><i class="bi bi-image me-2"></i>배너 관리</a>
            <a href="/admin/popups"    class="nav-sublink <?= str_starts_with($uri, 'admin/popups')  ? 'active' : '' ?>"><i class="bi bi-window me-2"></i>팝업 관리</a>
            <a href="/admin/welcome"   class="nav-sublink <?= str_starts_with($uri, 'admin/welcome') ? 'active' : '' ?>"><i class="bi bi-layout-text-window-reverse me-2"></i>Welcome 페이지</a>
        </div>

        <!-- 쇼핑 -->
        <button class="nav-section-toggle <?= $inShop ? '' : 'collapsed' ?>"
                type="button" data-bs-toggle="collapse" data-bs-target="#sec-shop"
                aria-expanded="<?= $inShop ? 'true' : 'false' ?>">
            쇼핑 <i class="bi bi-chevron-down"></i>
        </button>
        <div class="collapse <?= $inShop ? 'show' : '' ?>" id="sec-shop">
            <div class="nav-subgroup">상품</div>
            <a href="/admin/products"            class="nav-sublink <?= str_starts_with($uri, 'admin/products') && ! str_starts_with($uri, 'admin/products/categories') ? 'active' : '' ?>"><i class="bi bi-bag me-2"></i>상품 관리<?php if (($lowStockCount ?? 0) > 0): ?><span class="badge bg-warning text-dark ms-1" id="badge-low-stock" style="font-size:.65rem"><?= $lowStockCount ?></span><?php else: ?><span class="badge bg-warning text-dark ms-1 d-none" id="badge-low-stock" style="font-size:.65rem">0</span><?php endif; ?></a>
            <a href="/admin/products/categories" class="nav-sublink <?= str_starts_with($uri, 'admin/products/categories') ? 'active' : '' ?>"><i class="bi bi-tags me-2"></i>카테고리 관리</a>
            <a href="/admin/promotions"          class="nav-sublink <?= str_starts_with($uri, 'admin/promotions') ? 'active' : '' ?>"><i class="bi bi-megaphone me-2"></i>기획전 관리</a>
            <a href="/admin/suppliers"           class="nav-sublink <?= str_starts_with($uri, 'admin/suppliers')  ? 'active' : '' ?>"><i class="bi bi-truck me-2"></i>매입처 관리</a>
            <a href="/admin/inventory"           class="nav-sublink <?= str_starts_with($uri, 'admin/inventory')  ? 'active' : '' ?>"><i class="bi bi-boxes me-2"></i>재고 관리</a>
            <div class="nav-subgroup">거래</div>
            <a href="/admin/orders"  class="nav-sublink <?= str_starts_with($uri, 'admin/orders')  ? 'active' : '' ?>"><i class="bi bi-receipt me-2"></i>주문 관리<?php if (($pendingOrders ?? 0) > 0): ?><span class="badge bg-danger ms-1" id="badge-orders" style="font-size:.65rem"><?= $pendingOrders ?></span><?php endif; ?></a>
            <a href="/admin/sales"   class="nav-sublink <?= str_starts_with($uri, 'admin/sales')   ? 'active' : '' ?>"><i class="bi bi-graph-up-arrow me-2"></i>매출 관리</a>
            <a href="/admin/coupons" class="nav-sublink <?= str_starts_with($uri, 'admin/coupons') ? 'active' : '' ?>"><i class="bi bi-ticket-perforated me-2"></i>쿠폰 관리</a>
            <a href="/admin/points"  class="nav-sublink <?= str_starts_with($uri, 'admin/points')  ? 'active' : '' ?>"><i class="bi bi-star me-2"></i>포인트 관리</a>
        </div>

        <!-- 운영 -->
        <button class="nav-section-toggle <?= $inOps ? '' : 'collapsed' ?>"
                type="button" data-bs-toggle="collapse" data-bs-target="#sec-ops"
                aria-expanded="<?= $inOps ? 'true' : 'false' ?>">
            운영 <i class="bi bi-chevron-down"></i>
        </button>
        <div class="collapse <?= $inOps ? 'show' : '' ?>" id="sec-ops">
            <div class="nav-subgroup">회원</div>
            <a href="/admin/users"          class="nav-sublink <?= str_starts_with($uri, 'admin/users') ? 'active' : '' ?>"><i class="bi bi-person-lines-fill me-2"></i>회원 관리</a>
            <a href="/admin/grade/platinum" class="nav-sublink <?= str_starts_with($uri, 'admin/grade') ? 'active' : '' ?>"><i class="bi bi-trophy-fill me-2"></i>회원 등급</a>
            <div class="nav-subgroup">CS</div>
            <a href="/admin/inquiries" class="nav-sublink <?= str_starts_with($uri, 'admin/inquiries') ? 'active' : '' ?>">
                <i class="bi bi-envelope me-2"></i>문의글 관리
                <?php if ($unreadInquiries > 0): ?>
                <span class="badge bg-danger ms-1" id="badge-inquiries"><?= $unreadInquiries ?></span>
                <?php else: ?>
                <span class="badge bg-danger ms-1 d-none" id="badge-inquiries">0</span>
                <?php endif; ?>
            </a>
            <a href="/admin/qna" class="nav-sublink <?= str_starts_with($uri, 'admin/qna') ? 'active' : '' ?>">
                <i class="bi bi-chat-dots me-2"></i>상품 문의 관리
                <?php if ($unansweredQna > 0): ?>
                <span class="badge bg-danger ms-1" id="badge-qna"><?= $unansweredQna ?></span>
                <?php else: ?>
                <span class="badge bg-danger ms-1 d-none" id="badge-qna">0</span>
                <?php endif; ?>
            </a>
            <a href="/admin/reviews" class="nav-sublink <?= str_starts_with($uri, 'admin/reviews') ? 'active' : '' ?>">
                <i class="bi bi-star-half me-2"></i>리뷰 관리
                <span class="badge bg-danger ms-1 d-none" id="badge-reviews" title="AI가 감지한 부정 리뷰">0</span>
            </a>
            <div class="nav-subgroup">분석·관리</div>
            <a href="/admin/stats" class="nav-sublink <?= str_starts_with($uri, 'admin/stats') ? 'active' : '' ?>"><i class="bi bi-bar-chart-line me-2"></i>접속 통계</a>
            <a href="/admin/menus" class="nav-sublink <?= str_starts_with($uri, 'admin/menus') ? 'active' : '' ?>"><i class="bi bi-list-ul me-2"></i>메뉴 관리</a>
        </div>

        <!-- 설정 -->
        <button class="nav-section-toggle <?= $inSettings ? '' : 'collapsed' ?>"
                type="button" data-bs-toggle="collapse" data-bs-target="#sec-settings"
                aria-expanded="<?= $inSettings ? 'true' : 'false' ?>">
            설정 <i class="bi bi-chevron-down"></i>
        </button>
        <div class="collapse <?= $inSettings ? 'show' : '' ?>" id="sec-settings">
            <a href="/admin/settings/general" class="nav-link <?= $uri !== 'admin/settings/oauth' && $uri !== 'admin/settings/theme' && ! str_starts_with($uri, 'admin/settings/theme') && str_starts_with($uri, 'admin/settings') ? 'active' : '' ?>"><i class="bi bi-gear me-2"></i>사이트 설정</a>
            <a href="/admin/settings/theme"   class="nav-link <?= str_starts_with($uri, 'admin/settings/theme') ? 'active' : '' ?>"><i class="bi bi-palette me-2"></i>테마 관리</a>
            <a href="/admin/settings/oauth"   class="nav-link <?= $uri === 'admin/settings/oauth' ? 'active' : '' ?>"><i class="bi bi-people me-2"></i>소셜 로그인</a>
            <a href="/admin/schedule"         class="nav-link <?= str_starts_with($uri, 'admin/schedule') ? 'active' : '' ?>"><i class="bi bi-clock-history me-2"></i>배치 작업</a>
        </div>

        <div class="nav-section mt-3">사이트</div>
        <a href="/" target="_blank" class="nav-link"><i class="bi bi-box-arrow-up-right me-2"></i>사이트 보기</a>
        <a href="/auth/logout" class="nav-link text-danger"><i class="bi bi-box-arrow-right me-2"></i>로그아웃</a>
    </nav>

    <script>
    (function () {
        var STORE_KEY = 'adminNavOpen';
        var sections  = ['sec-content', 'sec-shop', 'sec-ops', 'sec-settings'];

        // 활성 섹션은 항상 열림 — localStorage 복원은 비활성 섹션에만 적용
        var saved = {};
        try { saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}'); } catch (e) {}

        sections.forEach(function (id) {
            var el  = document.getElementById(id);
            var btn = document.querySelector('[data-bs-target="#' + id + '"]');
            if (! el || ! btn) return;

            var isActive = el.classList.contains('show');
            if (isActive) return; // 활성 섹션은 서버 렌더링 상태 유지

            // localStorage에 저장된 상태 복원
            if (saved[id] === true) {
                el.classList.add('show');
                btn.classList.remove('collapsed');
                btn.setAttribute('aria-expanded', 'true');
            }
        });

        // 토글 시 저장
        sections.forEach(function (id) {
            var el = document.getElementById(id);
            if (! el) return;
            el.addEventListener('show.bs.collapse', function () {
                saved[id] = true;
                localStorage.setItem(STORE_KEY, JSON.stringify(saved));
            });
            el.addEventListener('hide.bs.collapse', function () {
                saved[id] = false;
                localStorage.setItem(STORE_KEY, JSON.stringify(saved));
            });
        });
    }());
    </script>
</div>

<div id="topbar" class="d-flex align-items-center justify-content-between">
    <strong class="text-dark"><?= esc($pageTitle ?? '') ?></strong>
    <div class="d-flex align-items-center gap-3">
        <?php
        $totalBadge = ($unreadInquiries ?? 0) + ($unansweredQna ?? 0) + ($lowStockCount ?? 0) + ($pendingOrders ?? 0);
        ?>
        <div class="position-relative" id="notif-bell-wrap" style="cursor:default">
            <i class="bi bi-bell fs-5 text-muted" id="notif-bell"></i>
            <?php if ($totalBadge > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  id="badge-total" style="font-size:.6rem"><?= $totalBadge ?></span>
            <?php else: ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                  id="badge-total" style="font-size:.6rem">0</span>
            <?php endif; ?>
        </div>
        <span class="text-muted small"><i class="bi bi-person-circle me-1"></i><?= esc($authUser['nickname']) ?></span>
    </div>
</div>

<div id="content">
    <?php foreach (['success', 'error'] as $type): ?>
        <?php if (session()->has($type)): ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
            <?= esc(session($type)) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (session()->has('errors')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php foreach (session('errors') as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?= $this->renderSection('content') ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?= $this->renderSection('scripts') ?>
<script>
(function () {
    function setBadge(id, count) {
        var el = document.getElementById(id);
        if (! el) return;
        el.textContent = count;
        el.classList.toggle('d-none', count === 0);
    }

    function refreshCounts() {
        fetch('/admin/notifications/counts')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                setBadge('badge-inquiries', d.unread_inquiries || 0);
                setBadge('badge-qna',       d.unanswered_qna  || 0);
                setBadge('badge-low-stock', d.low_stock       || 0);
                setBadge('badge-orders',    d.pending_orders  || 0);
                setBadge('badge-reviews',   d.negative_reviews|| 0);
                setBadge('badge-total',     d.total           || 0);
            })
            .catch(function() {});
    }

    // 30초마다 갱신
    setInterval(refreshCounts, 30000);
}());
</script>
</body>
</html>
