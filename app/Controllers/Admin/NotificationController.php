<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\InquiryModel;
use App\Models\ProductModel;
use App\Models\ProductQnaModel;

class NotificationController extends BaseController
{
    /** GET /admin/notifications/counts */
    public function counts(): \CodeIgniter\HTTP\ResponseInterface
    {
        $settings          = $this->viewData['settings'] ?? [];
        $lowStockThreshold = (int) ($settings['low_stock_threshold'] ?? $settings['stock_alert_threshold'] ?? 5);

        $unreadInquiries = (new InquiryModel())->getUnreadCount();
        $unansweredQna   = (new ProductQnaModel())->getUnansweredCount();
        $lowStock        = (new ProductModel())
            ->where('stock <=', $lowStockThreshold)
            ->where('status !=', 'hidden')
            ->countAllResults();
        $pendingOrders   = (int) \Config\Database::connect()
            ->table('orders')
            ->whereIn('status', ['paid', 'awaiting_payment'])
            ->countAllResults();
        $negativeReviews = (int) \Config\Database::connect()
            ->table('product_reviews')
            ->where('is_negative', 1)
            ->where('is_hidden', 0)
            ->countAllResults();

        return $this->response->setJSON([
            'unread_inquiries' => $unreadInquiries,
            'unanswered_qna'   => $unansweredQna,
            'low_stock'        => $lowStock,
            'pending_orders'   => $pendingOrders,
            'negative_reviews' => $negativeReviews,
            'total'            => $unreadInquiries + $unansweredQna + $lowStock + $pendingOrders + $negativeReviews,
        ]);
    }
}
