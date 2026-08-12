<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\OrderAttemptModel;
use App\Models\OrderModel;

/**
 * 주문 시도 로그 — 결제 확정 전 이탈한 시도(order_attempts) + 레거시
 * orders 의 pending/expired 행을 조회하는 유일한 화면이다. (이슈 #214 PR2)
 */
class OrderAttemptController extends BaseController
{
    private const array PG_LABELS = [
        'free'          => '무료 주문',
        'bank_transfer' => '무통장입금',
        'toss'          => '토스페이먼츠',
        'inicis'        => 'KG이니시스',
        'nicepay'       => '나이스페이',
        'kakaopay'      => '카카오페이',
        'naverpay'      => '네이버페이',
        'payco'         => 'PAYCO',
    ];

    private readonly OrderAttemptModel $attemptModel;
    private readonly OrderModel        $orderModel;

    public function __construct()
    {
        $this->attemptModel = new OrderAttemptModel();
        $this->orderModel   = new OrderModel();
    }

    /** GET /admin/order-attempts */
    public function index(): string
    {
        $keyword = trim($this->request->getGet('q') ?? '');
        $status  = $this->request->getGet('status') ?? '';
        $from    = trim($this->request->getGet('from') ?? '');
        $to      = trim($this->request->getGet('to') ?? '');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        $result = $this->attemptModel->adminGetAll([
            'keyword' => $keyword,
            'status'  => $status,
            'from'    => $from,
            'to'      => $to,
            'page'    => $page,
        ]);

        return $this->render('admin/order_attempts/index', array_merge($result, [
            'keyword'             => $keyword,
            'status'              => $status,
            'from'                => $from,
            'to'                  => $to,
            'attemptStatusLabels' => OrderAttemptModel::STATUS_LABELS,
            'legacyStatusLabels'  => OrderModel::STATUS_LABELS,
            'pgLabels'            => self::PG_LABELS,
        ]));
    }

    /** GET /admin/order-attempts/attempt/:id — 주문 시도 상세 */
    public function detailAttempt(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $attempt = $this->attemptModel->adminFind($id);

        if (! $attempt) {
            return redirect()->to('/admin/order-attempts')->with('error', '주문 시도를 찾을 수 없습니다.');
        }

        return $this->render('admin/order_attempts/detail_attempt', ['attempt' => $attempt, 'pgLabels' => self::PG_LABELS]);
    }

    /** GET /admin/order-attempts/legacy/:id — 레거시 pending/expired 주문 상세 */
    public function detailLegacy(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $order = $this->orderModel->adminGetWithItems($id);

        if (! $order || ! in_array($order['status'], ['pending', 'expired'], true)) {
            return redirect()->to('/admin/order-attempts')->with('error', '주문을 찾을 수 없습니다.');
        }

        return $this->render('admin/order_attempts/detail_legacy', [
            'order'        => $order,
            'statusLabels' => OrderModel::STATUS_LABELS,
            'pgLabels'     => self::PG_LABELS,
        ]);
    }
}
