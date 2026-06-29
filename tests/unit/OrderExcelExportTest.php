<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * 주문 엑셀 다운로드 및 송장 CSV 내보내기 검증
 *
 * 과거 발생한 버그:
 *  - order_items.quantity → qty (컬럼명 불일치)
 *  - PhpSpreadsheet 5.x에서 setCellValueByColumnAndRow() 제거
 */
final class OrderExcelExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    private array $cleanup = [
        'order_items'       => [],
        'order_status_logs' => [],
        'orders'            => [],
        'users'             => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'EXP' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['order_items'] !== []) {
            $db->table('order_items')->whereIn('id', $this->cleanup['order_items'])->delete();
        }
        if ($this->cleanup['orders'] !== []) {
            $db->table('order_status_logs')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $uid = uniqid();
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => 'exp_u_' . $uid,
            'email'      => 'exp_' . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => 'ExpUser_' . $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(string $status = 'paid', string $orderNumber = ''): int
    {
        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('orders')->insert([
            'user_id'             => $this->insertUser(),
            'order_number'        => $orderNumber ?: ($this->prefix . uniqid()),
            'status'              => $status,
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'receiver_name'       => '수취인',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertOrderItem(int $orderId, string $productName, int $qty): int
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'     => $orderId,
            'product_id'   => 0,
            'product_name' => $productName,
            'qty'          => $qty,
            'product_price'=> 10000,
            'subtotal'     => 10000 * $qty,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['order_items'][] = $id;
        return $id;
    }

    // ── order_items 컬럼명 검증 ────────────────────────────────────────────────

    public function testOrderItemsHasQtyNotQuantityColumn(): void
    {
        $cols = db_connect()->query('SHOW COLUMNS FROM order_items')->getResultArray();
        $names = array_column($cols, 'Field');

        $this->assertContains('qty', $names, 'order_items에 qty 컬럼이 있어야 한다');
        $this->assertNotContains('quantity', $names, 'quantity 컬럼은 존재하지 않아야 한다');
    }

    public function testOrderItemsQtySelectWorks(): void
    {
        $orderId = $this->insertOrder();
        $this->insertOrderItem($orderId, '테스트상품', 3);

        $rows = db_connect()->table('order_items')
            ->select('order_id, product_name, qty')
            ->where('order_id', $orderId)
            ->get()->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertEquals(3, (int) $rows[0]['qty']);
        $this->assertEquals('테스트상품', $rows[0]['product_name']);
    }

    public function testNameMapBuildingWithQtyColumn(): void
    {
        $orderId = $this->insertOrder();
        $this->insertOrderItem($orderId, '상품A', 2);
        $this->insertOrderItem($orderId, '상품B', 1);

        $rows = db_connect()->table('order_items')
            ->select('order_id, product_name, qty')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()->getResultArray();

        $nameMap = [];
        foreach ($rows as $row) {
            $nameMap[(int) $row['order_id']][] = $row['product_name'] . ' x' . $row['qty'];
        }

        $this->assertArrayHasKey($orderId, $nameMap);
        $this->assertEquals('상품A x2', $nameMap[$orderId][0]);
        $this->assertEquals('상품B x1', $nameMap[$orderId][1]);
    }

    // ── PhpSpreadsheet 5.x API 호환성 ─────────────────────────────────────────

    public function testSpreadsheetCoordinateStringFromColumnIndex(): void
    {
        $this->assertEquals('A', Coordinate::stringFromColumnIndex(1));
        $this->assertEquals('B', Coordinate::stringFromColumnIndex(2));
        $this->assertEquals('J', Coordinate::stringFromColumnIndex(10));
        $this->assertEquals('Z', Coordinate::stringFromColumnIndex(26));
    }

    public function testSpreadsheetSetCellValueWithCoordinate(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $col = fn(int $c, int $r): string => Coordinate::stringFromColumnIndex($c) . $r;

        $headers = ['주문번호', '주문일시', '수취인', '연락처', '우편번호', '주소', '상세주소', '상품명', '결제금액', '상태'];
        foreach ($headers as $idx => $header) {
            $sheet->setCellValue($col($idx + 1, 1), $header);
        }

        $this->assertEquals('주문번호', $sheet->getCell('A1')->getValue());
        $this->assertEquals('상태',    $sheet->getCell('J1')->getValue());
    }

    public function testSpreadsheetDataRowWriting(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $col         = fn(int $c, int $r): string => Coordinate::stringFromColumnIndex($c) . $r;

        $sheet->setCellValue($col(1, 2), 'ORD-20240101-0001');
        $sheet->setCellValue($col(9, 2), 50000);

        $this->assertEquals('ORD-20240101-0001', $sheet->getCell('A2')->getValue());
        $this->assertEquals(50000,               $sheet->getCell('I2')->getValue());
    }

    public function testSpreadsheetWriterGeneratesOutput(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $col         = fn(int $c, int $r): string => Coordinate::stringFromColumnIndex($c) . $r;
        $sheet->setCellValue($col(1, 1), '테스트');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $this->assertNotEmpty($content, 'XLSX 출력이 비어있지 않아야 한다');
        // XLSX는 ZIP 포맷 — PK 시그니처로 확인
        $this->assertStringStartsWith('PK', $content, 'XLSX 파일은 ZIP(PK) 시그니처로 시작해야 한다');
    }

    // ── 송장 CSV 내보내기 (trackingExport) ────────────────────────────────────

    public function testTrackingExportIncludesPaidOrders(): void
    {
        $orderNumber = $this->prefix . 'PAID01';
        $orderId     = $this->insertOrder('paid', $orderNumber);

        $rows = db_connect()->table('orders o')
            ->select('o.order_number, o.receiver_name, o.status, o.tracking_company, o.tracking_number')
            ->whereIn('o.status', ['paid', 'preparing', 'shipped'])
            ->get()->getResultArray();

        $found = array_filter($rows, fn($r) => $r['order_number'] === $orderNumber);
        $this->assertNotEmpty($found, 'paid 상태 주문이 내보내기 목록에 포함돼야 한다');
    }

    public function testTrackingExportExcludesDeliveredOrders(): void
    {
        $orderNumber = $this->prefix . 'DEL01';
        $this->insertOrder('delivered', $orderNumber);

        $rows = db_connect()->table('orders o')
            ->select('o.order_number')
            ->whereIn('o.status', ['paid', 'preparing', 'shipped'])
            ->get()->getResultArray();

        $found = array_filter($rows, fn($r) => $r['order_number'] === $orderNumber);
        $this->assertEmpty($found, 'delivered 주문은 내보내기 목록에서 제외돼야 한다');
    }

    public function testTrackingExportStatusFilterWorks(): void
    {
        $paidNum     = $this->prefix . 'P01';
        $preparingNum = $this->prefix . 'PR01';
        $this->insertOrder('paid',      $paidNum);
        $this->insertOrder('preparing', $preparingNum);

        $paidOnly = db_connect()->table('orders')
            ->select('order_number')
            ->where('status', 'paid')
            ->get()->getResultArray();

        $numbers = array_column($paidOnly, 'order_number');
        $this->assertContains($paidNum,      $numbers, 'paid 필터에 paid 주문 포함');
        $this->assertNotContains($preparingNum, $numbers, 'paid 필터에 preparing 주문 미포함');
    }

    public function testTrackingExportExcludesOrdersWithTrackingNumber(): void
    {
        $db = db_connect();

        // 송장 입력 완료된 주문
        $withTracking = $this->prefix . 'TRK01';
        $id = $this->insertOrder('preparing', $withTracking);
        $db->table('orders')->where('id', $id)->update([
            'tracking_company' => 'CJ대한통운',
            'tracking_number'  => '1234567890',
        ]);

        // 송장 미입력 주문
        $noTracking = $this->prefix . 'TRK02';
        $this->insertOrder('preparing', $noTracking);

        $rows = $db->table('orders o')
            ->select('o.order_number')
            ->whereIn('o.status', ['paid', 'preparing', 'shipped'])
            ->groupStart()
                ->where('o.tracking_number IS NULL')
                ->orWhere('o.tracking_number', '')
            ->groupEnd()
            ->get()->getResultArray();

        $numbers = array_column($rows, 'order_number');
        $this->assertNotContains($withTracking, $numbers, '송장 입력 완료 주문은 제외돼야 한다');
        $this->assertContains($noTracking, $numbers, '송장 미입력 주문은 포함돼야 한다');
    }

    public function testTrackingExportExcludesOrdersWithEmptyStringTracking(): void
    {
        // tracking_number = '' (빈 문자열)도 미입력으로 간주해 포함돼야 한다
        $db = db_connect();
        $orderNumber = $this->prefix . 'TRK03';
        $id = $this->insertOrder('paid', $orderNumber);
        $db->table('orders')->where('id', $id)->update(['tracking_number' => '']);

        $rows = $db->table('orders o')
            ->select('o.order_number')
            ->whereIn('o.status', ['paid', 'preparing', 'shipped'])
            ->groupStart()
                ->where('o.tracking_number IS NULL')
                ->orWhere('o.tracking_number', '')
            ->groupEnd()
            ->get()->getResultArray();

        $numbers = array_column($rows, 'order_number');
        $this->assertContains($orderNumber, $numbers, 'tracking_number 빈 문자열은 미입력으로 간주해 포함');
    }

    public function testTrackingExportCsvLineFormat(): void
    {
        $orderId = $this->insertOrder('preparing');
        $order   = (new OrderModel())->find($orderId);

        $statusLabels = [
            'paid'      => '결제 완료',
            'preparing' => '배송 준비',
            'shipped'   => '배송 중',
        ];

        $line = implode(',', [
            $order['order_number'],
            $order['receiver_name'],
            $statusLabels[$order['status']] ?? $order['status'],
            $order['tracking_company'] ?? '',
            $order['tracking_number']  ?? '',
        ]);

        $this->assertStringContainsString($order['order_number'], $line);
        $this->assertStringContainsString('배송 준비', $line);
    }
}
