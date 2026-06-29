<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\OrderMemoModel;
use App\Models\OrderModel;

class OrderController extends BaseController
{
    private OrderModel     $orderModel;
    private OrderMemoModel $memoModel;

    private const STATUS_LABELS = [
        'pending'           => '결제 대기',
        'awaiting_payment'  => '입금 대기',
        'paid'              => '결제 완료',
        'preparing'         => '배송 준비',
        'shipped'           => '배송 중',
        'delivered'         => '배송 완료',
        'cancelled'         => '취소',
        'expired'           => '만료',
        'refund_requested'  => '환불 요청',
        'refunded'          => '환불 완료',
        'return_requested'   => '반품 요청',
        'return_approved'    => '반품 승인',
        'exchange_requested' => '교환 요청',
        'exchange_approved'  => '교환 승인',
        'exchange_completed' => '교환 완료',
    ];

    private const NEXT_STATUS = [
        'paid'             => 'preparing',
        'preparing'        => 'shipped',
        'shipped'          => 'delivered',
        'refund_requested' => 'refunded',
    ];

    public function __construct()
    {
        $this->orderModel = new OrderModel();
        $this->memoModel  = new OrderMemoModel();
    }

    /** GET /admin/orders */
    public function index(): string
    {
        $keyword = trim($this->request->getGet('q') ?? '');
        $status  = $this->request->getGet('status') ?? '';
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        if (! array_key_exists($status, self::STATUS_LABELS) && $status !== '') {
            $status = '';
        }

        $result = $this->orderModel->adminGetAll(compact('keyword', 'status', 'page'));

        return $this->render('admin/orders/list', array_merge($result, [
            'keyword'      => $keyword,
            'status'       => $status,
            'statusLabels' => self::STATUS_LABELS,
        ]));
    }

    /** GET /admin/orders/anomalies — 이상 주문(사기·어뷰징 의심) 탐지 */
    public function anomalies(): string
    {
        $days    = min(\App\Libraries\OrderAnomalyService::MAX_DAYS, max(1, (int) ($this->request->getGet('days') ?: 7)));
        $flagged = (new \App\Libraries\OrderAnomalyService())->flagged($days);

        return $this->render('admin/orders/anomalies', [
            'flagged'      => $flagged,
            'days'         => $days,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    /** GET /admin/orders/json */
    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('orders o')
            ->select('o.id, o.order_number, o.created_at, o.receiver_name, o.total_amount, o.status,
                      u.email AS user_email, u.nickname AS user_nickname,
                      lp.pg_provider, lp.method AS payment_method,
                      (SELECT COUNT(*) FROM order_memos WHERE order_id = o.id) AS memo_count')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->join('payments lp', 'lp.id = (SELECT MAX(id) FROM payments WHERE order_id = o.id)', 'left')
            ->orderBy('o.id', 'DESC')
            ->get()->getResultArray();

        $data = array_map(fn($r) => [
            'id'             => (int) $r['id'],
            'order_number'   => $r['order_number'],
            'created_at'     => $r['created_at'],
            'user_email'     => $r['user_email'] ?? '',
            'user_nickname'  => $r['user_nickname'] ?? '',
            'receiver_name'  => $r['receiver_name'],
            'pg_provider'    => $r['pg_provider'] ?? '',
            'payment_method' => $r['payment_method'] ?? '',
            'total_amount'   => (int) $r['total_amount'],
            'status'         => $r['status'],
            'status_label'   => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'memo_count'     => (int) $r['memo_count'],
        ], $rows);

        return $this->response->setJSON(['data' => $data]);
    }

    /** GET /admin/orders/export — 엑셀 다운로드 */
    public function exportExcel(): \CodeIgniter\HTTP\ResponseInterface
    {
        $keyword = trim($this->request->getGet('q') ?? '');
        $status  = $this->request->getGet('status') ?? '';

        if (! array_key_exists($status, self::STATUS_LABELS) && $status !== '') {
            $status = '';
        }

        $orders   = $this->orderModel->adminGetAll([
            'keyword' => $keyword,
            'status'  => $status,
            'page'    => 1,
            'perPage' => 5000,
        ])['items'];

        $orderIds = array_column($orders, 'id');
        $nameMap  = [];
        if ($orderIds) {
            $rows = \Config\Database::connect()->table('order_items')
                ->select('order_id, product_name, qty')
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id')->orderBy('id')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $nameMap[(int) $row['order_id']][] = $row['product_name'] . ' x' . $row['qty'];
            }
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $col = fn(int $c, int $r): string =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;

        $headers = ['주문번호', '주문일시', '수취인', '연락처', '우편번호', '주소', '상세주소', '상품명', '결제금액', '상태'];
        foreach ($headers as $idx => $header) {
            $sheet->setCellValue($col($idx + 1, 1), $header);
        }

        // 헤더 스타일
        $headerStyle = [
            'font'      => ['bold' => true],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFE9ECEF']],
            'borders'   => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ];
        $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

        foreach ($orders as $i => $order) {
            $names          = $nameMap[$order['id']] ?? [];
            $extra          = count($names) - 1;
            $productSummary = ($names[0] ?? '') . ($extra > 0 ? ' 외 ' . $extra . '건' : '');
            $rowNum         = $i + 2;

            $sheet->setCellValue($col(1,  $rowNum), $order['order_number']);
            $sheet->setCellValue($col(2,  $rowNum), $order['created_at']);
            $sheet->setCellValue($col(3,  $rowNum), $order['receiver_name']);
            $sheet->setCellValue($col(4,  $rowNum), $order['receiver_phone']);
            $sheet->setCellValue($col(5,  $rowNum), $order['zipcode'] ?? '');
            $sheet->setCellValue($col(6,  $rowNum), $order['address1'] ?? '');
            $sheet->setCellValue($col(7,  $rowNum), $order['address2'] ?? '');
            $sheet->setCellValue($col(8,  $rowNum), $productSummary);
            $sheet->setCellValue($col(9,  $rowNum), (int) $order['total_amount']);
            $sheet->setCellValue($col(10, $rowNum), self::STATUS_LABELS[$order['status']] ?? $order['status']);
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = '주문목록_' . date('Ymd_His') . '.xlsx';

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($content);
    }

    /** POST /admin/orders/bulk-status — 주문 상태 일괄 변경 */
    public function bulkUpdateStatus(): \CodeIgniter\HTTP\ResponseInterface
    {
        $status   = $this->request->getPost('status');
        $orderIds = $this->request->getPost('order_ids');

        if (! $status || ! array_key_exists($status, self::STATUS_LABELS)) {
            return $this->response->setJSON(['success' => false, 'message' => '잘못된 상태값입니다.']);
        }

        if (! is_array($orderIds) || empty($orderIds)) {
            return $this->response->setJSON(['success' => false, 'message' => '주문을 선택해주세요.']);
        }

        $orderIds = array_slice(array_map('intval', $orderIds), 0, 100);

        $updated = 0;
        $failed  = 0;
        foreach ($orderIds as $id) {
            if ($id <= 0) { $failed++; continue; }
            $this->orderModel->updateStatus($id, $status) ? $updated++ : $failed++;
        }

        return $this->response->setJSON([
            'success' => true,
            'updated' => $updated,
            'failed'  => $failed,
            'message' => "{$updated}건 변경 완료" . ($failed > 0 ? ", {$failed}건 실패 (허용되지 않는 전환)" : ''),
        ]);
    }

    /** GET /admin/orders/:id */
    public function detail(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $order = $this->orderModel->adminGetWithItems($id);
        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', '주문을 찾을 수 없습니다.');
        }

        $carriersRaw = $this->viewData['settings']['shipping_carriers'] ?? '[]';
        $carriers    = json_decode($carriersRaw, true) ?: [];

        $rCode             = $order['return_reason_code'] ?? null;
        $returnReasonPayer = $rCode ? (\App\Models\OrderModel::RETURN_REASON_CODES[$rCode]['payer'] ?? null) : null;

        $eCode               = $order['exchange_reason_code'] ?? null;
        $exchangeReasonPayer = $eCode ? (\App\Models\OrderModel::EXCHANGE_REASON_CODES[$eCode]['payer'] ?? null) : null;

        return $this->render('admin/orders/detail', [
            'order'               => $order,
            'statusLabels'        => self::STATUS_LABELS,
            'nextStatus'          => self::NEXT_STATUS,
            'returnReasonPayer'   => $returnReasonPayer,
            'exchangeReasonPayer' => $exchangeReasonPayer,
            'memos'               => $this->memoModel->getByOrder($id),
            'carriers'            => $carriers,
        ]);
    }

    /** POST /admin/orders/:id/status */
    public function updateStatus(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $newStatus = $this->request->getPost('status');

        if (! $newStatus || ! array_key_exists($newStatus, self::STATUS_LABELS)) {
            return redirect()->back()->with('error', '잘못된 상태값입니다.');
        }

        if ($newStatus === 'shipped') {
            $order = $this->orderModel->find($id);
            if (! $order || trim($order['tracking_number'] ?? '') === '') {
                return redirect()->back()->with('error', '송장번호를 먼저 입력해주세요.');
            }
        }

        $ok = $this->orderModel->updateStatus($id, $newStatus);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '주문 상태가 변경되었습니다.' : '상태 변경에 실패했습니다.');
    }

    /** POST /admin/orders/:id/tracking */
    public function updateTracking(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $company = trim($this->request->getPost('tracking_company') ?? '');
        $number  = trim($this->request->getPost('tracking_number')  ?? '');

        if ($number === '') {
            return redirect()->back()->with('error', '운송장 번호를 입력해주세요.');
        }

        $ok = $this->orderModel->updateTracking($id, $company, $number);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '송장번호가 저장되었습니다.' : '저장에 실패했습니다.');
    }

    /** POST /admin/orders/:id/cancel */
    public function cancel(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->adminCancel($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '주문이 취소되었습니다.' : '취소할 수 없는 주문입니다.');
    }

    /** POST /admin/orders/:id/bank_confirm — 무통장 입금 확인 */
    public function confirmBankTransfer(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->confirmBankTransfer($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '입금 확인 처리가 완료되었습니다.' : '입금 확인에 실패했습니다. (재고 부족 또는 이미 처리된 주문)');
    }

    /** POST /admin/orders/:id/refund */
    public function refund(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->markRefunded($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '환불 완료 처리되었습니다.' : '환불 처리에 실패했습니다. (PG 콘솔에서 취소 후 다시 시도하세요)');
    }

    /** POST /admin/orders/:id/return-approve — 반품 승인 (재고·쿠폰·포인트 복구) */
    public function approveReturn(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->approveReturn($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '반품이 승인되었습니다. PG 콘솔에서 환불 후 환불 완료 버튼을 눌러주세요.' : '반품 승인에 실패했습니다.');
    }

    /** POST /admin/orders/:id/return-reject — 반품 거부 */
    public function rejectReturn(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->rejectReturn($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '반품이 거부되었습니다.' : '반품 거부에 실패했습니다.');
    }

    /** POST /admin/orders/:id/return-refund — 반품 환불 완료 확인 */
    public function confirmReturnRefund(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->confirmReturnRefund($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '환불 완료 처리되었습니다.' : '환불 완료 처리에 실패했습니다.');
    }

    /** POST /admin/orders/:id/exchange-approve — 교환 승인 (재고 복구) */
    public function approveExchange(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->approveExchange($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '교환이 승인되었습니다. 대체품 발송 후 완료 처리하세요.' : '교환 승인에 실패했습니다.');
    }

    /** POST /admin/orders/:id/exchange-reject — 교환 거부 */
    public function rejectExchange(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $ok = $this->orderModel->rejectExchange($id);

        return redirect()->to("/admin/orders/{$id}")
            ->with($ok ? 'success' : 'error', $ok ? '교환이 거부되었습니다.' : '교환 거부에 실패했습니다.');
    }

    /** POST /admin/orders/:id/exchange-complete — 교환 완료 (대체품 지정 + 재고 차감 + 송장) */
    public function completeExchange(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $json  = $this->request->getPost('exchange_items_json') ?? '[]';
        $items = json_decode($json, true);

        if (empty($items) || ! is_array($items)) {
            return redirect()->to("/admin/orders/{$id}")->with('error', '대체품을 1개 이상 선택해주세요.');
        }

        $exchangeTrackingNumber = trim($this->request->getPost('exchange_tracking_number') ?? '');
        if ($exchangeTrackingNumber === '') {
            return redirect()->to("/admin/orders/{$id}")->with('error', '교환품 발송 송장번호를 입력해주세요.');
        }

        $tracking = [
            'exchange_tracking_company'        => trim($this->request->getPost('exchange_tracking_company')        ?? ''),
            'exchange_tracking_number'         => $exchangeTrackingNumber,
            'exchange_return_tracking_company' => trim($this->request->getPost('exchange_return_tracking_company') ?? ''),
            'exchange_return_tracking_number'  => trim($this->request->getPost('exchange_return_tracking_number')  ?? ''),
            'exchange_seller_shipping_fee'     => (int) ($this->request->getPost('exchange_seller_shipping_fee')   ?? 0),
        ];

        $ok = $this->orderModel->completeExchange($id, $items, $tracking);

        $msg = $ok ? '교환 완료 처리되었습니다.' : '대체품 합계가 원주문 금액과 다르거나 재고가 부족합니다.';
        return redirect()->to("/admin/orders/{$id}")->with($ok ? 'success' : 'error', $msg);
    }

    /** GET /admin/orders/exchange-product-search — 대체품 상품+SKU 검색 AJAX */
    public function exchangeProductSearch(): \CodeIgniter\HTTP\ResponseInterface
    {
        $q  = trim($this->request->getGet('q') ?? '');
        $db = db_connect();

        $builder = $db->table('products')
            ->select('products.id, products.name, products.price, products.discount_price, products.stock, media.file_path AS primary_image')
            ->join('product_images', 'product_images.product_id = products.id AND product_images.is_primary = 1', 'left')
            ->join('media', 'media.id = product_images.media_id', 'left')
            ->where('products.deleted_at IS NULL', null, false)
            ->where('products.status', 'on_sale');

        if ($q !== '') {
            $builder->like('products.name', $q);
        }

        $products = $builder->orderBy('products.id', 'DESC')->limit(20)->get()->getResultArray();

        foreach ($products as &$p) {
            $p['primary_image'] = $p['primary_image'] ? base_url($p['primary_image']) : null;
            $p['sell_price']    = $p['discount_price'] > 0 ? (int) $p['discount_price'] : (int) $p['price'];

            $skus = $db->table('product_skus')
                ->select('product_skus.id AS sku_id, product_skus.price_diff, product_skus.stock AS sku_stock, GROUP_CONCAT(product_option_values.label ORDER BY product_option_values.id SEPARATOR " / ") AS option_label')
                ->join('product_sku_values', 'product_sku_values.sku_id = product_skus.id')
                ->join('product_option_values', 'product_option_values.id = product_sku_values.option_value_id')
                ->where('product_skus.product_id', (int) $p['id'])
                ->groupBy('product_skus.id')
                ->orderBy('product_skus.id')
                ->get()->getResultArray();

            foreach ($skus as &$s) {
                $s['sell_price'] = $p['sell_price'] + (int) $s['price_diff'];
            }
            $p['skus'] = $skus;
        }

        return $this->response->setJSON(['products' => $products]);
    }

    /** GET /admin/orders/tracking-export — 송장 입력용 주문 CSV 다운로드 */
    public function trackingExport(): \CodeIgniter\HTTP\ResponseInterface
    {
        $status = $this->request->getGet('status') ?: 'all';

        $db     = \Config\Database::connect();
        $builder = $db->table('orders o')
            ->select('o.order_number, o.receiver_name, o.status, o.tracking_company, o.tracking_number')
            ->orderBy('o.id', 'DESC');

        if ($status !== 'all') {
            $builder->where('o.status', $status);
        } else {
            $builder->whereIn('o.status', ['paid', 'preparing', 'shipped']);
        }

        // 송장 미입력 주문만 포함
        $builder->groupStart()
                ->where('o.tracking_number IS NULL')
                ->orWhere('o.tracking_number', '')
                ->groupEnd();

        $orders = $builder->get()->getResultArray();

        $statusLabels = self::STATUS_LABELS;

        $lines   = [];
        $lines[] = "\xEF\xBB\xBF" . implode(',', ['주문번호', '수취인', '상태', '배송업체', '송장번호']);

        foreach ($orders as $order) {
            $lines[] = implode(',', [
                $order['order_number'],
                $order['receiver_name'],
                $statusLabels[$order['status']] ?? $order['status'],
                $order['tracking_company'] ?? '',
                $order['tracking_number']  ?? '',
            ]);
        }

        $filename = '송장입력_' . date('Ymd_His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
            ->setBody(implode("\n", $lines));
    }

    /** GET /admin/orders/tracking-template — CSV 양식 다운로드 */
    public function trackingTemplate(): \CodeIgniter\HTTP\ResponseInterface
    {
        $csv = "\xEF\xBB\xBF" . implode(',', ['주문번호', '배송업체', '송장번호']) . "\n"
             . "ORD-20240101-0001,CJ대한통운,123456789012\n"
             . "ORD-20240101-0002,한진택배,987654321098\n";

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="tracking_upload_template.csv"')
            ->setBody($csv);
    }

    /** GET /admin/orders/tracking-upload — 일괄 송장 등록 폼 */
    public function trackingUploadForm(): string
    {
        return $this->render('admin/orders/tracking_upload', []);
    }

    /** POST /admin/orders/tracking-upload — CSV 파싱 및 일괄 업데이트 */
    public function trackingUploadProcess(): string
    {
        $file = $this->request->getFile('csv_file');

        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return $this->render('admin/orders/tracking_upload', [
                'uploadError' => 'CSV 파일을 선택해주세요.',
            ]);
        }

        $ext = strtolower($file->getClientExtension());
        if ($ext !== 'csv') {
            return $this->render('admin/orders/tracking_upload', [
                'uploadError' => 'CSV 파일(.csv)만 업로드 가능합니다.',
            ]);
        }

        $raw     = file_get_contents($file->getTempName());
        $content = ltrim($raw, "\xEF\xBB\xBF");  // BOM 제거
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        $lines   = array_values(array_filter(array_map('trim', explode("\n", $content))));

        $successCount = 0;
        $skippedCount = 0;
        $errorRows     = [];

        foreach ($lines as $idx => $line) {
            $lineNum = $idx + 1;

            // 헤더 행 건너뜀
            if ($idx === 0 && preg_match('/^주문번호/u', $line)) {
                continue;
            }

            $cols = str_getcsv($line);
            if (count($cols) < 3) {
                $errorRows[] = ['line' => $lineNum, 'raw' => $line, 'reason' => '컬럼 수 부족 (최소 3개 필요)'];
                continue;
            }

            [$orderNumber, $carrier, $trackingNumber] = array_map('trim', array_slice($cols, 0, 3));

            if ($orderNumber === '' || $trackingNumber === '') {
                $skippedCount++;
                continue;
            }

            $order = $this->orderModel->where('order_number', $orderNumber)->first();
            if (! $order) {
                $errorRows[] = ['line' => $lineNum, 'raw' => $line, 'reason' => "주문번호 '{$orderNumber}' 없음"];
                continue;
            }

            $ok = $this->orderModel->updateTracking((int) $order['id'], $carrier, $trackingNumber);
            if ($ok) {
                $successCount++;
            } else {
                $errorRows[] = ['line' => $lineNum, 'raw' => $line, 'reason' => '업데이트 실패'];
            }
        }

        return $this->render('admin/orders/tracking_upload', [
            'results' => [
                'success' => $successCount,
                'skipped' => $skippedCount,
                'errors'  => $errorRows,
            ],
        ]);
    }

    /** POST /admin/orders/:id/memos — 내부 메모 추가 */
    public function memoStore(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        if (! $this->orderModel->find($id)) {
            return $this->response->setJSON(['success' => false, 'message' => '주문을 찾을 수 없습니다.']);
        }

        $content = trim($this->request->getPost('content') ?? '');
        if ($content === '') {
            return $this->response->setJSON(['success' => false, 'message' => '메모 내용을 입력해주세요.']);
        }
        if (mb_strlen($content) > 1000) {
            return $this->response->setJSON(['success' => false, 'message' => '메모는 1,000자 이내로 입력해주세요.']);
        }

        $adminId  = (int) session()->get('user_id');
        $memoId   = $this->memoModel->insert([
            'order_id' => $id,
            'admin_id' => $adminId,
            'content'  => $content,
        ]);

        $memo = $this->memoModel->db
            ->table('order_memos om')
            ->select('om.*, u.nickname AS admin_name')
            ->join('users u', 'u.id = om.admin_id', 'left')
            ->where('om.id', $memoId)
            ->get()->getRowArray();

        return $this->response->setJSON(['success' => true, 'memo' => $memo]);
    }

    /** POST /admin/orders/:id/memos/:memoId/delete — 내부 메모 삭제 */
    public function memoDelete(int $id, int $memoId): \CodeIgniter\HTTP\ResponseInterface
    {
        $memo = $this->memoModel->where('id', $memoId)->where('order_id', $id)->first();
        if (! $memo) {
            return $this->response->setJSON(['success' => false, 'message' => '메모를 찾을 수 없습니다.']);
        }

        $this->memoModel->delete($memoId);
        return $this->response->setJSON(['success' => true]);
    }
}
