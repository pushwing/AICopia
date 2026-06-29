<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class SalesController extends BaseController
{
    public function index(): string
    {
        $db = \Config\Database::connect();

        $period  = $this->request->getGet('period')  ?? 'daily';
        $keyword = trim($this->request->getGet('keyword') ?? '');
        $from    = $this->request->getGet('from') ?? date('Y-m-01');
        $to      = $this->request->getGet('to')   ?? date('Y-m-d');

        if (! in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $period = 'daily';
        }

        $base = $this->baseSalesQuery($db, $from, $to, $keyword);

        $periodRows = $this->salesPeriodRows($base, $period);
        $methodRows = $this->salesMethodRows($base);
        $summary    = $this->salesSummary($base);

        $orders = (clone $base)
            ->select('o.id, o.order_number, o.total_amount, o.payable_amount,
                      o.shipping_fee, o.coupon_discount_amount, o.point_used_amount,
                      o.created_at, o.receiver_name,
                      u.nickname, u.email, p.method AS payment_method, p.pg_provider,
                      COALESCE(oc.cost_total, 0) AS cost_total,
                      o.payable_amount - COALESCE(oc.cost_total, 0) - o.shipping_fee AS profit', false)
            ->orderBy('o.id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $pgLabels = self::PG_LABELS;

        return $this->render('admin/sales/index', compact(
            'period', 'keyword', 'from', 'to',
            'periodRows', 'methodRows', 'summary', 'orders', 'pgLabels'
        ));
    }

    private const PG_LABELS = [
        'bank_transfer' => '무통장입금',
        'toss'          => '토스페이먼츠',
        'inicis'        => 'KG이니시스',
        'nicepay'       => '나이스페이',
        'kakaopay'      => '카카오페이',
        'naverpay'      => '네이버페이',
        'payco'         => 'PAYCO',
    ];

    /** 매출 집계 공통 base 쿼리 (결제완료 주문 + 매입원가 조인). */
    private function baseSalesQuery($db, string $from, string $to, string $keyword = '')
    {
        $paidPaymentSql = "
            SELECT p1.*
            FROM payments p1
            INNER JOIN (
                SELECT order_id, MAX(id) AS id
                FROM payments
                WHERE status = 'paid'
                GROUP BY order_id
            ) latest_paid ON latest_paid.id = p1.id
        ";
        // 주문별 매입원가 집계 서브쿼리 (1:N 중복 합산 방지)
        $costSubSql = "SELECT order_id, SUM(qty * cost_price) AS cost_total FROM order_items GROUP BY order_id";

        $base = $db->table('orders o')
            ->join('users u',    'u.id = o.user_id', 'left')
            ->join("({$paidPaymentSql}) p", 'p.order_id = o.id', 'inner', false)
            ->join("({$costSubSql}) oc", 'oc.order_id = o.id', 'left', false)
            ->whereIn('o.status', ['paid', 'preparing', 'shipped', 'delivered', 'refund_requested', 'return_requested', 'return_approved'])
            ->where('DATE(o.created_at) >=', $from)
            ->where('DATE(o.created_at) <=', $to);

        if ($keyword !== '') {
            $base->groupStart()
                ->like('o.order_number', $keyword)
                ->orLike('o.receiver_name', $keyword)
                ->orLike('u.nickname', $keyword)
                ->orLike('u.email', $keyword)
                ->groupEnd();
        }

        return $base;
    }

    /** 기간별 매출 집계 (GMV = total_amount, 실매출 = payable_amount). */
    private function salesPeriodRows($base, string $period): array
    {
        $groupExpr = match ($period) {
            'weekly'  => "DATE_FORMAT(DATE_SUB(o.created_at, INTERVAL (DAYOFWEEK(o.created_at)-2+7)%7 DAY), '%Y-%m-%d')",
            'monthly' => "DATE_FORMAT(o.created_at, '%Y-%m')",
            default   => "DATE(o.created_at)",
        };

        return (clone $base)
            ->select("{$groupExpr} AS period_key,
                COUNT(o.id)                                                                   AS order_count,
                SUM(o.total_amount)                                                           AS gmv,
                SUM(o.payable_amount)                                                         AS revenue,
                SUM(o.coupon_discount_amount + o.point_used_amount)                           AS total_discount,
                SUM(COALESCE(oc.cost_total, 0))                                               AS total_cost,
                SUM(o.payable_amount) - SUM(COALESCE(oc.cost_total, 0)) - SUM(o.shipping_fee) AS operating_profit", false)
            ->groupBy('period_key')
            ->orderBy('period_key', 'DESC')
            ->get()->getResultArray();
    }

    /** 결제수단별 집계. */
    private function salesMethodRows($base): array
    {
        return (clone $base)
            ->select('p.pg_provider, p.method, COUNT(o.id) AS order_count,
                SUM(o.total_amount) AS gmv, SUM(o.payable_amount) AS revenue', false)
            ->groupBy('p.pg_provider, p.method')
            ->orderBy('revenue', 'DESC')
            ->get()->getResultArray();
    }

    /** 기간 요약 집계. */
    private function salesSummary($base): array
    {
        return (clone $base)
            ->select('COUNT(o.id) AS total_orders,
                SUM(o.total_amount)                                                           AS total_gmv,
                SUM(o.payable_amount)                                                         AS total_revenue,
                SUM(o.coupon_discount_amount + o.point_used_amount)                           AS total_discount,
                AVG(o.payable_amount)                                                         AS avg_order,
                SUM(COALESCE(oc.cost_total, 0))                                               AS total_cost,
                SUM(o.payable_amount) - SUM(COALESCE(oc.cost_total, 0)) - SUM(o.shipping_fee) AS total_profit', false)
            ->get()->getRowArray() ?? [];
    }

    /** POST /admin/sales/ai-report — 매출 AI 분석 리포트 (AJAX) */
    public function aiReport(): \CodeIgniter\HTTP\ResponseInterface
    {
        $period = $this->request->getPost('period') ?? 'daily';
        $from   = $this->request->getPost('from') ?? date('Y-m-01');
        $to     = $this->request->getPost('to')   ?? date('Y-m-d');
        if (! in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $period = 'daily';
        }

        $db   = \Config\Database::connect();
        $base = $this->baseSalesQuery($db, $from, $to);

        $summary = $this->salesSummary($base);
        if ((int) ($summary['total_orders'] ?? 0) === 0) {
            return $this->response->setJSON(['error' => '해당 기간에 매출 데이터가 없습니다.'])->setStatusCode(422);
        }

        $methodRows = array_map(fn ($m) => [
            'label'   => self::PG_LABELS[$m['pg_provider']] ?? $m['pg_provider'],
            'orders'  => (int) $m['order_count'],
            'revenue' => (int) $m['revenue'],
        ], $this->salesMethodRows($base));

        $periods = array_map(fn ($r) => [
            'period'  => $r['period_key'],
            'orders'  => (int) $r['order_count'],
            'revenue' => (int) $r['revenue'],
            'profit'  => (int) $r['operating_profit'],
        ], array_slice($this->salesPeriodRows($base, $period), 0, 31));

        $stats = [
            'period'  => $period,
            'from'    => $from,
            'to'      => $to,
            'summary' => [
                'total_orders'  => (int) $summary['total_orders'],
                'total_revenue' => (int) $summary['total_revenue'],
                'total_gmv'     => (int) $summary['total_gmv'],
                'avg_order'     => (int) $summary['avg_order'],
                'total_discount'=> (int) $summary['total_discount'],
                'total_profit'  => (int) $summary['total_profit'],
            ],
            'periods' => $periods,
            'methods' => $methodRows,
        ];

        try {
            // 동일 데이터 반복 조회 시 캐시 (요약 수치 해시 포함 — 데이터 변하면 자동 무효화)
            $key    = \App\Libraries\AiProvider\AiCache::key('sales_report', $from, $to, $period, md5(json_encode($stats['summary'])));
            $report = \App\Libraries\AiProvider\AiCache::remember(
                $key,
                fn () => \App\Libraries\AiCategoryAdvisor::create()->generateSalesReport($stats),
                3600
            );
            if ($report === '') {
                return $this->response->setJSON(['error' => 'AI 응답이 비어있습니다. 잠시 후 다시 시도해주세요.'])->setStatusCode(500);
            }
            return $this->response->setJSON(['report' => $report]);
        } catch (\App\Exceptions\AiKeyMissingException $e) {
            return $this->response->setJSON([
                'error'     => $e->getMessage(),
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            log_message('error', 'AiSalesReport: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 매출 분석 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    public function exportExcel(): \CodeIgniter\HTTP\ResponseInterface
    {
        $from    = $this->request->getGet('from') ?? date('Y-m-01');
        $to      = $this->request->getGet('to')   ?? date('Y-m-d');
        $keyword = trim($this->request->getGet('keyword') ?? '');

        $db = \Config\Database::connect();

        $paidPaymentSql = "
            SELECT p1.*
            FROM payments p1
            INNER JOIN (
                SELECT order_id, MAX(id) AS id
                FROM payments
                WHERE status = 'paid'
                GROUP BY order_id
            ) latest_paid ON latest_paid.id = p1.id
        ";

        $base = $db->table('orders o')
            ->select('o.id, o.order_number, o.created_at, o.receiver_name,
                      o.total_amount, o.payable_amount, o.shipping_fee,
                      o.coupon_discount_amount, o.point_used_amount, o.status,
                      u.nickname AS user_nickname, p.pg_provider, p.method AS payment_method', false)
            ->join('users u', 'u.id = o.user_id', 'left')
            ->join("({$paidPaymentSql}) p", 'p.order_id = o.id', 'inner', false)
            ->whereIn('o.status', ['paid', 'preparing', 'shipped', 'delivered', 'refund_requested', 'return_requested', 'return_approved'])
            ->where('DATE(o.created_at) >=', $from)
            ->where('DATE(o.created_at) <=', $to);

        if ($keyword !== '') {
            $base->groupStart()
                ->like('o.order_number', $keyword)
                ->orLike('o.receiver_name', $keyword)
                ->orLike('u.nickname', $keyword)
                ->groupEnd();
        }

        $orders = $base->orderBy('o.id', 'DESC')->get()->getResultArray();

        $orderIds = array_column($orders, 'id');
        $nameMap  = [];
        if ($orderIds) {
            $rows = $db->table('order_items')
                ->select('order_id, product_name, qty')
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id')->orderBy('id')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $nameMap[(int) $row['order_id']][] = $row['product_name'] . ' x' . $row['qty'];
            }
        }

        $pgLabels = [
            'bank_transfer' => '무통장입금', 'toss' => '토스페이먼츠',
            'inicis' => 'KG이니시스', 'nicepay' => '나이스페이',
            'kakaopay' => '카카오페이', 'naverpay' => '네이버페이',
        ];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $col         = fn(int $c, int $r): string =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;

        $headers = ['주문일', '주문번호', '회원', '수취인', '상품(요약)', 'GMV', '실매출', '배송비', '할인', '결제수단'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($col($i + 1, 1), $h);
        }
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font'    => ['bold' => true],
            'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                          'startColor' => ['argb' => 'FFE9ECEF']],
            'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ]);

        foreach ($orders as $i => $o) {
            $names   = $nameMap[(int) $o['id']] ?? [];
            $extra   = count($names) - 1;
            $summary = ($names[0] ?? '') . ($extra > 0 ? ' 외 ' . $extra . '건' : '');
            $discount = (int) $o['coupon_discount_amount'] + (int) $o['point_used_amount'];
            $rowNum  = $i + 2;

            $sheet->setCellValue($col(1,  $rowNum), substr((string) $o['created_at'], 0, 10));
            $sheet->setCellValue($col(2,  $rowNum), $o['order_number']);
            $sheet->setCellValue($col(3,  $rowNum), $o['user_nickname'] ?? '');
            $sheet->setCellValue($col(4,  $rowNum), $o['receiver_name']);
            $sheet->setCellValue($col(5,  $rowNum), $summary);
            $sheet->setCellValue($col(6,  $rowNum), (int) $o['total_amount']);
            $sheet->setCellValue($col(7,  $rowNum), (int) $o['payable_amount']);
            $sheet->setCellValue($col(8,  $rowNum), (int) $o['shipping_fee']);
            $sheet->setCellValue($col(9,  $rowNum), $discount);
            $sheet->setCellValue($col(10, $rowNum), $pgLabels[$o['pg_provider']] ?? ($o['pg_provider'] ?? ''));
        }

        foreach (range('A', 'J') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = '매출현황_' . $from . '_' . $to . '.xlsx';
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($content);
    }
}
