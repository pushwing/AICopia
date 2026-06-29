<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccessLogModel;
use App\Models\InquiryModel;
use App\Models\OrderModel;
use App\Models\PostModel;
use App\Models\ProductModel;
use App\Models\UserModel;

class DashboardController extends BaseController
{
    /** GET /admin/chart-data — 대시보드 차트용 JSON */
    public function chartData(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();

        // 최근 30일 일별 매출
        $rows = $db->query("
            SELECT DATE(created_at) AS day, SUM(payable_amount) AS revenue
            FROM orders
            WHERE status NOT IN ('pending','expired','cancelled','refunded')
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY day
            ORDER BY day ASC
        ")->getResultArray();

        $salesMap = [];
        foreach ($rows as $r) {
            $salesMap[$r['day']] = (int) $r['revenue'];
        }

        $salesLabels = [];
        $salesData   = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $salesLabels[] = date('m/d', strtotime($day));
            $salesData[]   = $salesMap[$day] ?? 0;
        }

        // 상품별 판매량 TOP 5 (최근 30일)
        $topProducts = $db->query("
            SELECT oi.product_name, SUM(oi.qty) AS total_qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE o.status NOT IN ('pending','expired','cancelled','refunded')
              AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY oi.product_name
            ORDER BY total_qty DESC
            LIMIT 5
        ")->getResultArray();

        return $this->response->setJSON([
            'sales'   => ['labels' => $salesLabels, 'data' => $salesData],
            'top'     => [
                'labels' => array_column($topProducts, 'product_name'),
                'data'   => array_map('intval', array_column($topProducts, 'total_qty')),
            ],
        ]);
    }

    public function index(): string
    {
        $postModel    = new PostModel();
        $userModel    = new UserModel();
        $inquiryModel = new InquiryModel();
        $orderModel   = new OrderModel();
        $productModel = new ProductModel();

        $db = \Config\Database::connect();

        $accessLogModel = new AccessLogModel();
        $todayStats = $accessLogModel->getTodayStats();
        $monthPv    = $accessLogModel->where('created_at >=', date('Y-m-01') . ' 00:00:00')->countAllResults();

        $recentOrders = $db->table('orders o')
            ->select('o.id, o.order_number, o.status, o.total_amount, o.created_at, u.nickname AS user_nickname')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->orderBy('o.id', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        $lowStockProducts = $productModel
            ->select('id, name, stock, status')
            ->where('stock <=', 5)
            ->where('deleted_at IS NULL', null, false)
            ->orderBy('stock', 'ASC')
            ->findAll(10);

        // 매출 통계 (취소·환불·만료·대기 제외)
        $todayStart = date('Y-m-d') . ' 00:00:00';
        $weekStart  = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $monthStart = date('Y-m-01') . ' 00:00:00';
        $excludedStatuses = ['pending', 'expired', 'cancelled', 'refunded'];

        $salesRow = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END), 0) AS today_sales,
                COALESCE(SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END), 0) AS week_sales,
                COALESCE(SUM(total_amount), 0)                                            AS month_sales
            FROM orders
            WHERE status NOT IN ('pending','expired','cancelled','refunded')
              AND created_at >= ?
        ", [$todayStart, $weekStart, $monthStart])->getRowArray();

        // 운영 현황 카운트
        $todayOrderCount   = $db->table('orders')
            ->where('created_at >=', $todayStart)->countAllResults();
        $pendingOrderCount = $db->table('orders')
            ->whereIn('status', ['awaiting_payment', 'preparing'])->countAllResults();
        $todayUserCount    = $db->table('users')
            ->where('created_at >=', $todayStart)->countAllResults();
        $lowStockCount     = $productModel
            ->where('stock <=', 5)
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();

        return $this->render('admin/dashboard/index', [
            'stats' => [
                'total_posts'     => $postModel->countAll(),
                'total_users'     => $userModel->countAll(),
                'total_inquiries' => $inquiryModel->countAll(),
                'unread_inquiries'=> $inquiryModel->getUnreadCount(),
            ],
            'salesStats' => [
                'today' => (int) ($salesRow['today_sales'] ?? 0),
                'week'  => (int) ($salesRow['week_sales']  ?? 0),
                'month' => (int) ($salesRow['month_sales'] ?? 0),
            ],
            'operationStats' => [
                'today_orders'   => $todayOrderCount,
                'pending_orders' => $pendingOrderCount,
                'low_stock'      => $lowStockCount,
                'today_users'    => $todayUserCount,
                'unread_inquiries' => $inquiryModel->getUnreadCount(),
            ],
            'recentInquiries'  => $inquiryModel->orderBy('id', 'DESC')->findAll(5),
            'recentPosts'      => $postModel
                ->select('posts.*, boards.slug as board_slug, boards.name as board_name, users.nickname as user_nickname')
                ->join('boards', 'boards.id = posts.board_id', 'left')
                ->join('users', 'users.id = posts.user_id', 'left')
                ->orderBy('posts.id', 'DESC')
                ->findAll(5),
            'recentOrders'     => $recentOrders,
            'lowStockProducts' => $lowStockProducts,
            'accessStats'      => [
                'today_pv' => $todayStats['pv'],
                'today_uv' => $todayStats['uv'],
                'month_pv' => $monthPv,
            ],
        ]);
    }
}
