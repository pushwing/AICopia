<?php

namespace App\Controllers;

use App\Models\BannerModel;
use App\Models\CategoryModel;
use App\Models\InquiryModel;
use App\Models\MenuModel;
use App\Models\PopupModel;
use App\Models\ProductModel;
use App\Models\ProductQnaModel;
use App\Models\SettingModel;
use CodeIgniter\Controller;

class BaseController extends Controller
{
    protected array $viewData = [];

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        // 전역 사이트 설정 (캐시됨)
        $settings = (new SettingModel())->getAllAsMap();

        // 전역 네비게이션 메뉴 (캐시됨)
        $menus = (new MenuModel())->getTree();

        // 로그인 정보
        $userId   = (int) session()->get('user_id');
        $authUser = [
            'id'       => session()->get('user_id'),
            'nickname' => session()->get('user_nickname'),
            'role'     => session()->get('user_role') ?? 'guest',
            'grade'    => session()->get('user_grade') ?? 'bronze',
            'loggedIn' => (bool) session()->get('user_id'),
        ];

        // 관리자용: 미읽음 문의 수, 미답변 상품 문의 수, 재고 부족 상품 수, 처리 대기 주문 수
        $unreadInquiries  = 0;
        $unansweredQna    = 0;
        $lowStockCount    = 0;
        $pendingOrders    = 0;
        if ($authUser['role'] === 'admin') {
            $unreadInquiries   = (new InquiryModel())->getUnreadCount();
            $unansweredQna     = (new ProductQnaModel())->getUnansweredCount();
            $lowStockThreshold = (int) ($settings['low_stock_threshold'] ?? $settings['stock_alert_threshold'] ?? 5);
            $lowStockCount     = (new ProductModel())
                ->where('stock <=', $lowStockThreshold)
                ->where('status !=', 'hidden')
                ->countAllResults();
            $pendingOrders = (int) \Config\Database::connect()
                ->table('orders')
                ->whereIn('status', ['paid', 'awaiting_payment'])
                ->countAllResults();
        }

        // 장바구니 수 (로그인 회원만)
        $cartCount = $authUser['loggedIn']
            ? (int) \Config\Database::connect()->table('cart_items')->where('user_id', $userId)->countAllResults()
            : 0;

        $isAdmin = str_starts_with(uri_string(), 'admin');

        $subLeftBanners = $isAdmin
            ? []
            : (new BannerModel())->getActiveByPosition('sub_left');

        $activePopups = $isAdmin
            ? []
            : (new PopupModel())->getActiveForPage(uri_string());

        $categories = $isAdmin ? [] : (new CategoryModel())->getTree();

        $this->viewData = compact('settings', 'menus', 'authUser', 'unreadInquiries', 'unansweredQna', 'lowStockCount', 'pendingOrders', 'subLeftBanners', 'activePopups', 'cartCount', 'categories');
    }

    protected function getUserRole(): string
    {
        return (string) (session()->get('user_role') ?? 'guest');
    }

    /**
     * guest | member | admin 권한 체크
     */
    protected function checkPermission(string $required): bool
    {
        $role = $this->getUserRole();
        return match ($required) {
            'guest'  => true,
            'member' => in_array($role, ['member', 'admin']),
            'admin'  => $role === 'admin',
            default  => false,
        };
    }

    protected function render(string $view, array $data = []): string
    {
        return view($view, array_merge($this->viewData, $data));
    }
}
