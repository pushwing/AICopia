<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ─── 홈 ──────────────────────────────────────────────────────────────────────
$routes->get('/',        'Front\ShopController::home');
$routes->get('welcome',  'Front\ShopController::welcome');

// ─── 인증 ────────────────────────────────────────────────────────────────────
$routes->get( 'auth/login',    'Front\AuthController::login');
$routes->post('auth/login',    'Front\AuthController::loginProcess');
$routes->get( 'auth/logout',   'Front\AuthController::logout');
$routes->get( 'auth/register', 'Front\AuthController::register');
$routes->post('auth/register', 'Front\AuthController::registerProcess');
$routes->get( 'auth/verify-pending',     'Front\AuthController::verifyPending');
$routes->get( 'auth/verify/(:segment)',  'Front\AuthController::verifyEmail/$1');
$routes->post('auth/resend',             'Front\AuthController::resendVerification');
$routes->get( 'auth/profile',  'Front\AuthController::profile',       ['filter' => 'auth:member']);
$routes->post('auth/profile',  'Front\AuthController::profileUpdate', ['filter' => 'auth:member']);

// ─── 소셜 로그인 ──────────────────────────────────────────────────────────────
$routes->get('auth/social/(:segment)',          'Front\SocialAuthController::redirect/$1');
$routes->get('auth/social/(:segment)/callback', 'Front\SocialAuthController::callback/$1');

// ─── 게시판 ───────────────────────────────────────────────────────────────────
$routes->get( 'board/(:segment)',                              'Front\BoardController::index/$1');
$routes->get( 'board/(:segment)/write',                        'Front\BoardController::write/$1');
$routes->post('board/(:segment)/write',                        'Front\BoardController::store/$1');
$routes->get( 'board/(:segment)/(:num)',                       'Front\BoardController::view/$1/$2');
$routes->get( 'board/(:segment)/(:num)/edit',                  'Front\BoardController::edit/$1/$2');
$routes->post('board/(:segment)/(:num)/edit',                  'Front\BoardController::update/$1/$2');
$routes->post('board/(:segment)/(:num)/delete',                'Front\BoardController::delete/$1/$2');
$routes->post('board/(:segment)/(:num)/verify',                'Front\BoardController::guestVerify/$1/$2');
$routes->get( 'board/file/(:num)/download',                    'Front\BoardController::download/$1');
$routes->post('board/(:segment)/(:num)/comment',               'Front\BoardController::commentStore/$1/$2');
$routes->post('board/(:segment)/(:num)/comment/(:num)/delete', 'Front\BoardController::commentDelete/$1/$2/$3');

// ─── 에디터 이미지 업로드 ─────────────────────────────────────────────────────
$routes->post('board/image-upload', 'Front\BoardController::imageUpload');

// ─── 문의폼 ───────────────────────────────────────────────────────────────────
$routes->post('inquiry/submit', 'Front\PageController::inquirySubmit');

// ─── 관리자 ──────────────────────────────────────────────────────────────────
$routes->group('admin', ['filter' => 'auth:admin'], function ($routes) {
    // 대시보드
    $routes->get('',           'Admin\DashboardController::index');
    $routes->get('dashboard',  'Admin\DashboardController::index');
    $routes->get('chart-data', 'Admin\DashboardController::chartData');

    // 페이지 관리
    $routes->get( 'pages',              'Admin\PageManagerController::index');
    $routes->get( 'pages/create',       'Admin\PageManagerController::create');
    $routes->post('pages/create',       'Admin\PageManagerController::store');
    $routes->get( 'pages/(:num)/edit',  'Admin\PageManagerController::edit/$1');
    $routes->post('pages/(:num)/edit',  'Admin\PageManagerController::update/$1');
    $routes->post('pages/(:num)/delete','Admin\PageManagerController::delete/$1');

    // 게시판 관리
    $routes->get( 'boards',              'Admin\BoardManagerController::index');
    $routes->get( 'boards/create',       'Admin\BoardManagerController::create');
    $routes->post('boards/create',       'Admin\BoardManagerController::store');
    $routes->get( 'boards/(:num)/edit',  'Admin\BoardManagerController::edit/$1');
    $routes->post('boards/(:num)/edit',  'Admin\BoardManagerController::update/$1');
    $routes->get( 'boards/(:num)/posts', 'Admin\BoardManagerController::posts/$1');
    $routes->post('posts/(:num)/delete', 'Admin\BoardManagerController::deletePost/$1');

    // 메뉴 관리
    $routes->get( 'menus',              'Admin\MenuController::index');
    $routes->post('menus',              'Admin\MenuController::store');
    $routes->post('menus/(:num)/edit',  'Admin\MenuController::update/$1');
    $routes->post('menus/(:num)/move',  'Admin\MenuController::move/$1');
    $routes->post('menus/(:num)/delete','Admin\MenuController::delete/$1');

    // 미디어 라이브러리
    $routes->get( 'media',              'Admin\MediaController::index');
    $routes->post('media/upload',       'Admin\MediaController::upload');
    $routes->post('media/(:num)/alt',   'Admin\MediaController::updateAlt/$1');
    $routes->post('media/(:num)/delete','Admin\MediaController::delete/$1');

    // 사이트 설정
    $routes->get( 'settings',                   'Admin\SettingController::index');
    $routes->get( 'settings/(:segment)',         'Admin\SettingController::index/$1');
    $routes->post('settings/theme/upload',       'Admin\SettingController::uploadTheme');
    $routes->post('settings/smtp-test',          'Admin\SettingController::smtpTest');
    $routes->post('settings/(:segment)',         'Admin\SettingController::update/$1');

    // 알림 배지
    $routes->get('notifications/counts', 'Admin\NotificationController::counts');

    // 배치 작업 관리
    $routes->get( 'schedule',                    'Admin\ScheduleController::index');
    $routes->post('schedule/(:segment)/toggle',  'Admin\ScheduleController::toggle/$1');
    $routes->post('schedule/(:segment)/cron',    'Admin\ScheduleController::updateCron/$1');

    // 전체 게시물 관리
    $routes->get( 'posts/json',          'Admin\PostController::json');
    $routes->get( 'posts',              'Admin\PostController::index');
    $routes->post('posts/(:num)/delete','Admin\PostController::delete/$1');

    // 회원 관리
    $routes->get( 'users/json',                 'Admin\UserController::json');
    $routes->get( 'users/export',               'Admin\UserController::export');
    $routes->get( 'users',                      'Admin\UserController::index');
    $routes->get( 'users/(:num)/edit',            'Admin\UserController::edit/$1');
    $routes->post('users/(:num)/edit',            'Admin\UserController::update/$1');
    $routes->post('users/(:num)/delete',          'Admin\UserController::delete/$1');
    $routes->post('users/(:num)/verify',          'Admin\UserController::manualVerify/$1');
    $routes->post('users/(:num)/resend-verify',   'Admin\UserController::resendVerify/$1');
    $routes->get( 'users/(:num)/tab/orders',      'Admin\UserController::tabOrders/$1');
    $routes->get( 'users/(:num)/tab/points',      'Admin\UserController::tabPoints/$1');
    $routes->get( 'users/(:num)/tab/coupons',     'Admin\UserController::tabCoupons/$1');

    // 문의 수신함
    $routes->get( 'inquiries',              'Admin\InquiryController::index');
    $routes->get( 'inquiries/(:num)',       'Admin\InquiryController::view/$1');
    $routes->post('inquiries/(:num)/suggest-reply', 'Admin\InquiryController::suggestReply/$1');
    $routes->post('inquiries/(:num)/delete','Admin\InquiryController::delete/$1');

    // 상품 관리
    $routes->post('products/bulk',                           'Admin\ProductController::bulk');
    $routes->get( 'products/json',                           'Admin\ProductController::json');
    $routes->get( 'products',                                'Admin\ProductController::index');
    $routes->get( 'products/import-template',                'Admin\ProductController::importTemplate');
    $routes->get( 'products/import',                         'Admin\ProductController::import');
    $routes->post('products/import',                         'Admin\ProductController::importProcess');
    $routes->post('products/import/confirm',                 'Admin\ProductController::importConfirm');
    $routes->get( 'products/create',                         'Admin\ProductController::create');
    $routes->post('products/create',                         'Admin\ProductController::store');
    $routes->get( 'products/(:num)/edit',                    'Admin\ProductController::edit/$1');
    $routes->post('products/(:num)/edit',                    'Admin\ProductController::update/$1');
    $routes->post('products/(:num)/copy',                    'Admin\ProductController::copy/$1');
    $routes->post('products/(:num)/delete',                  'Admin\ProductController::delete/$1');
    $routes->post('products/(:num)/stock',                   'Admin\ProductController::updateStock/$1');
    $routes->post('products/(:num)/featured',               'Admin\ProductController::toggleFeatured/$1');
    $routes->post('products/image/(:num)/delete',            'Admin\ProductController::imageDelete/$1');
    $routes->get( 'products/unassigned',                     'Admin\ProductController::unassigned');
    $routes->post('products/assign-category',               'Admin\ProductController::assignCategory');
    $routes->post('products/suggest-category',              'Admin\ProductController::suggestCategory');
    $routes->post('products/generate-description',          'Admin\ProductController::generateDescription');
    $routes->post('products/extract-from-image',            'Admin\ProductController::extractFromImage');
    $routes->get( 'products/naver-search',                  'Admin\ProductController::naverSearch');
    $routes->post('products/import-image',                  'Admin\ProductController::importImage');
    $routes->get( 'products/categories',                     'Admin\ProductController::categories');
    $routes->post('products/categories',                     'Admin\ProductController::categoryStore');
    $routes->post('products/categories/publish',             'Admin\ProductController::categoryPublish');
    $routes->post('products/categories/(:num)/move',         'Admin\ProductController::categoryMove/$1');
    $routes->post('products/categories/(:num)/edit',         'Admin\ProductController::categoryUpdate/$1');
    $routes->post('products/categories/(:num)/delete',       'Admin\ProductController::categoryDelete/$1');

    // 재고 관리
    $routes->get( 'inventory',                        'Admin\InventoryController::index');
    $routes->get( 'inventory/suggestions',            'Admin\InventoryController::suggestions');
    $routes->post('inventory/(:num)/adjust',          'Admin\InventoryController::adjust/$1');
    $routes->get( 'inventory/(:num)/logs',            'Admin\InventoryController::logs/$1');

    // 매출 관리
    $routes->get('sales',         'Admin\SalesController::index');
    $routes->get('sales/export',  'Admin\SalesController::exportExcel');
    $routes->post('sales/ai-report', 'Admin\SalesController::aiReport');

    // 쿠폰 관리
    $routes->get( 'coupons/json',               'Admin\CouponController::json');
    $routes->get( 'coupons',                   'Admin\CouponController::index');
    $routes->get( 'coupons/create',            'Admin\CouponController::create');
    $routes->post('coupons/create',            'Admin\CouponController::store');
    $routes->get( 'coupons/(:num)/edit',       'Admin\CouponController::edit/$1');
    $routes->post('coupons/(:num)/edit',       'Admin\CouponController::update/$1');
    $routes->post('coupons/(:num)/delete',     'Admin\CouponController::delete/$1');
    $routes->get( 'coupons/(:num)/issue',            'Admin\CouponController::issueForm/$1');
    $routes->post('coupons/(:num)/issue',            'Admin\CouponController::issue/$1');
    $routes->post('coupons/(:num)/issue-grade',      'Admin\CouponController::issueGrade/$1');

    // 회원 등급 관리
    $routes->get( 'grade/platinum',                  'Admin\GradeController::platinum');
    $routes->post('grade/platinum/(:num)/promote',   'Admin\GradeController::promote/$1');

    // 포인트 관리
    $routes->get( 'points',                    'Admin\PointController::index');
    $routes->get( 'points/(:num)/history',     'Admin\PointController::history/$1');
    $routes->post('points/adjust',             'Admin\PointController::adjust');

    // 주문 관리
    $routes->get( 'orders/json',                     'Admin\OrderController::json');
    $routes->get( 'orders',                          'Admin\OrderController::index');
    $routes->get( 'orders/anomalies',                'Admin\OrderController::anomalies');
    $routes->get( 'orders/export',                   'Admin\OrderController::exportExcel');
    $routes->get( 'orders/tracking-template',        'Admin\OrderController::trackingTemplate');
    $routes->get( 'orders/tracking-export',          'Admin\OrderController::trackingExport');
    $routes->get( 'orders/tracking-upload',          'Admin\OrderController::trackingUploadForm');
    $routes->post('orders/tracking-upload',          'Admin\OrderController::trackingUploadProcess');
    $routes->post('orders/bulk-status',              'Admin\OrderController::bulkUpdateStatus');
    $routes->get( 'orders/(:num)',                 'Admin\OrderController::detail/$1');
    $routes->post('orders/(:num)/status',          'Admin\OrderController::updateStatus/$1');
    $routes->post('orders/(:num)/tracking',        'Admin\OrderController::updateTracking/$1');
    $routes->post('orders/(:num)/cancel',          'Admin\OrderController::cancel/$1');
    $routes->post('orders/(:num)/refund',          'Admin\OrderController::refund/$1');
    $routes->post('orders/(:num)/bank_confirm',    'Admin\OrderController::confirmBankTransfer/$1');
    $routes->post('orders/(:num)/return-approve',    'Admin\OrderController::approveReturn/$1');
    $routes->post('orders/(:num)/return-reject',     'Admin\OrderController::rejectReturn/$1');
    $routes->post('orders/(:num)/return-refund',     'Admin\OrderController::confirmReturnRefund/$1');
    $routes->get( 'orders/exchange-product-search',  'Admin\OrderController::exchangeProductSearch');
    $routes->post('orders/(:num)/exchange-approve',  'Admin\OrderController::approveExchange/$1');
    $routes->post('orders/(:num)/exchange-reject',   'Admin\OrderController::rejectExchange/$1');
    $routes->post('orders/(:num)/exchange-complete', 'Admin\OrderController::completeExchange/$1');
    $routes->post('orders/(:num)/memos',                        'Admin\OrderController::memoStore/$1');
    $routes->post('orders/(:num)/memos/(:num)/delete',          'Admin\OrderController::memoDelete/$1/$2');

    // 배너 관리
    $routes->get( 'banners',              'Admin\BannerController::index');
    $routes->get( 'banners/create',       'Admin\BannerController::create');
    $routes->post('banners/create',       'Admin\BannerController::store');
    $routes->get( 'banners/(:num)/edit',  'Admin\BannerController::edit/$1');
    $routes->post('banners/(:num)/edit',  'Admin\BannerController::update/$1');
    $routes->post('banners/(:num)/delete','Admin\BannerController::delete/$1');
    $routes->get( 'welcome',              'Admin\WelcomeController::index');
    $routes->post('welcome',              'Admin\WelcomeController::update');

    // 팝업 관리
    $routes->get( 'popups',              'Admin\PopupController::index');
    $routes->get( 'popups/create',       'Admin\PopupController::create');
    $routes->post('popups/create',       'Admin\PopupController::store');
    $routes->get( 'popups/(:num)/edit',  'Admin\PopupController::edit/$1');
    $routes->post('popups/(:num)/edit',  'Admin\PopupController::update/$1');
    $routes->post('popups/(:num)/delete','Admin\PopupController::delete/$1');

    // 상품 문의 관리
    $routes->get( 'qna',               'Admin\QnaController::index');
    $routes->post('qna/(:num)/answer',         'Admin\QnaController::answer/$1');
    $routes->post('qna/(:num)/suggest-answer', 'Admin\QnaController::suggestAnswer/$1');
    $routes->post('qna/(:num)/delete',         'Admin\QnaController::delete/$1');

    // 리뷰 관리
    $routes->get( 'reviews/json',          'Admin\ReviewController::json');
    $routes->get( 'reviews',              'Admin\ReviewController::index');
    $routes->post('reviews/(:num)/toggle-hidden', 'Admin\ReviewController::toggleHidden/$1');
    $routes->post('reviews/(:num)/delete',        'Admin\ReviewController::delete/$1');

    // 접속 통계
    $routes->get('stats', 'Admin\StatsController::index');

    // 매입처 관리
    $routes->get( 'suppliers',              'Admin\SupplierController::index');
    $routes->get( 'suppliers/create',       'Admin\SupplierController::create');
    $routes->post('suppliers/create',       'Admin\SupplierController::store');
    $routes->get( 'suppliers/(:num)/edit',  'Admin\SupplierController::edit/$1');
    $routes->post('suppliers/(:num)/edit',  'Admin\SupplierController::update/$1');
    $routes->post('suppliers/(:num)/delete','Admin\SupplierController::delete/$1');

    // 기획전 관리
    $routes->get( 'promotions',                          'Admin\PromotionController::index');
    $routes->get( 'promotions/create',                   'Admin\PromotionController::create');
    $routes->post('promotions/create',                   'Admin\PromotionController::store');
    $routes->get( 'promotions/product-search',           'Admin\PromotionController::productSearch');
    $routes->get( 'promotions/(:num)/edit',              'Admin\PromotionController::edit/$1');
    $routes->post('promotions/(:num)/edit',              'Admin\PromotionController::update/$1');
    $routes->post('promotions/(:num)/delete',            'Admin\PromotionController::delete/$1');
});

// ─── 쇼핑 ────────────────────────────────────────────────────────────────────
$routes->get( 'shop',                                       'Front\ShopController::index');
$routes->get( 'shop/(:segment)',                            'Front\ShopController::detail/$1');
$routes->post('shop/(:segment)/qna',                        'Front\ShopController::qnaStore/$1',  ['filter' => 'auth:member']);
$routes->post('shop/(:segment)/qna/(:num)/delete',          'Front\ShopController::qnaDelete/$1/$2',     ['filter' => 'auth:member']);
$routes->post('shop/(:segment)/review',                     'Front\ShopController::reviewStore/$1',      ['filter' => 'auth:member']);
$routes->post('shop/(:segment)/review/(:num)/delete',       'Front\ShopController::reviewDelete/$1/$2',  ['filter' => 'auth:member']);
$routes->post('shop/(:segment)/wish',                       'Front\ShopController::wishToggle/$1',       ['filter' => 'auth:member']);
$routes->post('shop/(:segment)/restock-alert',              'Front\ShopController::restockAlert/$1');

// ─── 장바구니 ──────────────────────────────────────────────────────────────────
// add: 비로그인도 허용 (세션 저장), 나머지: 로그인 필요
$routes->post('cart/add', 'Front\CartController::add');
$routes->group('cart', ['filter' => 'auth:member'], function ($routes) {
    $routes->get( '',       'Front\CartController::index');
    $routes->post('update', 'Front\CartController::update');
    $routes->post('delete', 'Front\CartController::delete');
    $routes->post('clear',  'Front\CartController::clear');
});

// ─── 주문 (로그인 필요) ──────────────────────────────────────────────────────
$routes->group('order', ['filter' => 'auth:member'], function ($routes) {
    $routes->get( '',                         'Front\OrderController::index');
    $routes->post('create',                   'Front\OrderController::create');
    $routes->post('cancel',                   'Front\OrderController::cancel');
    $routes->get( 'complete/(:segment)',      'Front\OrderController::complete/$1');
    $routes->get( 'fail/(:segment)',          'Front\OrderController::fail/$1');
    $routes->get( 'bank_transfer/(:segment)', 'Front\OrderController::bankTransfer/$1');
});

// ─── 쿠폰 (로그인 필요) ──────────────────────────────────────────────────────
$routes->post('coupon/check', 'Front\CouponController::check', ['filter' => 'auth:member']);

// ─── 마이페이지 (로그인 필요) ────────────────────────────────────────────────
$routes->group('mypage', ['filter' => 'auth:member'], function ($routes) {
    $routes->get( 'orders',                  'Front\MyPageController::orders');
    $routes->get( 'orders/(:segment)',       'Front\MyPageController::orderDetail/$1');
    $routes->post('orders/cancel',           'Front\MyPageController::cancel');
    $routes->post('orders/reorder',          'Front\MyPageController::reorder');
    $routes->post('orders/confirm-delivery', 'Front\MyPageController::confirmDelivery');
    $routes->post('orders/return-request',    'Front\MyPageController::requestReturn');
    $routes->post('orders/exchange-request', 'Front\MyPageController::requestExchange');
    // 배송지 관리
    $routes->get( 'addresses',                      'Front\MyPageController::addresses');
    $routes->post('addresses',                      'Front\MyPageController::addressStore');
    $routes->post('addresses/(:num)/default',       'Front\MyPageController::addressSetDefault/$1');
    $routes->post('addresses/(:num)/delete',        'Front\MyPageController::addressDelete/$1');
    // 쿠폰 · 포인트 · 찜
    $routes->get('coupons',  'Front\MyPageController::coupons');
    $routes->get('points',   'Front\MyPageController::points');
    $routes->get('wishlist', 'Front\MyPageController::wishlist');
});

// ─── PG 콜백 (PG 서버에서 직접 호출 — CSRF 예외 필요) ────────────────────────
$routes->get( 'payment/callback/(:segment)', 'Front\PaymentController::callback/$1');
$routes->post('payment/callback/(:segment)', 'Front\PaymentController::callback/$1');

// ─── 기획전 ───────────────────────────────────────────────────────────────────
$routes->get('promotions',             'Front\PromotionController::index');
$routes->get('promotion/(:segment)',   'Front\PromotionController::detail/$1');

// ─── 동적 페이지 (반드시 마지막에 위치) ──────────────────────────────────────────
$routes->get('(:segment)', 'Front\PageController::show/$1');
