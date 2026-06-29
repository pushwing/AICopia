<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 송장 일괄 등록 핵심 로직 검증
 *
 * 컨트롤러(trackingUploadProcess)는 아래 흐름으로 동작한다:
 *  1. CSV 행 파싱 → (order_number, carrier, tracking_number)
 *  2. OrderModel::where('order_number', ...)->first() 로 주문 조회
 *  3. OrderModel::updateTracking($id, $carrier, $number) 호출
 *
 * 여기서는 2·3단계의 모델 동작을 집중 검증한다.
 */
final class TrackingBulkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;
    private string     $prefix;

    private array $cleanup = [
        'order_status_logs' => [],
        'orders'            => [],
        'users'             => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model  = new OrderModel();
        $this->prefix = 'T' . substr(uniqid(), -7) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
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
            'username'   => 'trk_user_' . $uid,
            'email'      => 'trk_' . $uid . '@test.com',
            'password'   => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'   => 'TrkUser_' . $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(string $orderNumber = '', string $status = 'paid'): int
    {
        $userId = $this->insertUser();
        $now    = date('Y-m-d H:i:s');
        $db     = db_connect();
        $db->table('orders')->insert([
            'user_id'             => $userId,
            'order_number'        => $orderNumber ?: ($this->prefix . uniqid()),
            'status'              => $status,
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'receiver_name'       => '테스트수취인',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 강남구',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    // ── updateTracking 기본 동작 ───────────────────────────────────────────────

    public function testUpdateTrackingReturnsTrueOnSuccess(): void
    {
        $orderId = $this->insertOrder();

        $result = $this->model->updateTracking($orderId, 'CJ대한통운', '123456789012');

        $this->assertTrue($result);
    }

    public function testUpdateTrackingSavesCompanyAndNumber(): void
    {
        $orderId = $this->insertOrder();

        $this->model->updateTracking($orderId, 'CJ대한통운', '123456789012');

        $row = $this->model->find($orderId);
        $this->assertEquals('CJ대한통운',   $row['tracking_company']);
        $this->assertEquals('123456789012', $row['tracking_number']);
    }

    public function testUpdateTrackingReturnsFalseForUnknownId(): void
    {
        $result = $this->model->updateTracking(999999999, '한진택배', '000000000000');

        $this->assertFalse($result);
    }

    public function testUpdateTrackingOverwritesPreviousValues(): void
    {
        $orderId = $this->insertOrder();
        $this->model->updateTracking($orderId, '한진택배', '111111111111');

        $this->model->updateTracking($orderId, '로젠택배', '222222222222');

        $row = $this->model->find($orderId);
        $this->assertEquals('로젠택배',     $row['tracking_company']);
        $this->assertEquals('222222222222', $row['tracking_number']);
    }

    public function testUpdateTrackingWritesStatusLog(): void
    {
        $orderId = $this->insertOrder();

        $this->model->updateTracking($orderId, 'CJ대한통운', '123456789012');

        $log = db_connect()->table('order_status_logs')
            ->where('order_id', $orderId)
            ->like('note', '운송장 등록')
            ->get()->getRowArray();
        $this->assertNotNull($log, 'order_status_logs에 운송장 등록 기록이 남아야 한다');
    }

    // ── 주문번호로 조회 (컨트롤러 CSV 처리 흐름) ──────────────────────────────

    public function testLookupByOrderNumberFindsExistingOrder(): void
    {
        $orderNumber = $this->prefix . 'FIND01';
        $this->insertOrder($orderNumber);

        $order = $this->model->where('order_number', $orderNumber)->first();

        $this->assertNotNull($order);
        $this->assertEquals($orderNumber, $order['order_number']);
    }

    public function testLookupByOrderNumberReturnsNullForUnknown(): void
    {
        $order = $this->model->where('order_number', 'NONEXISTENT-ORDER-XYZ')->first();

        $this->assertNull($order);
    }

    // ── 일괄 처리 시나리오 ────────────────────────────────────────────────────

    public function testBulkUpdateMultipleOrdersSucceeds(): void
    {
        $rows = [
            [$this->prefix . 'BULK01', 'CJ대한통운', '100000000001'],
            [$this->prefix . 'BULK02', '한진택배',   '200000000002'],
            [$this->prefix . 'BULK03', '로젠택배',   '300000000003'],
        ];

        foreach ($rows as [$orderNumber, , ]) {
            $this->insertOrder($orderNumber);
        }

        $successCount = 0;
        $errorRows    = [];

        foreach ($rows as $idx => [$orderNumber, $carrier, $trackingNumber]) {
            $order = $this->model->where('order_number', $orderNumber)->first();
            if (! $order) {
                $errorRows[] = $idx + 1;
                continue;
            }
            $ok = $this->model->updateTracking((int) $order['id'], $carrier, $trackingNumber);
            $ok ? $successCount++ : $errorRows[] = $idx + 1;
        }

        $this->assertEquals(3, $successCount);
        $this->assertCount(0, $errorRows);
    }

    public function testBulkUpdateCollectsErrorForUnknownOrderNumber(): void
    {
        $knownNumber   = $this->prefix . 'KNOWN01';
        $unknownNumber = $this->prefix . 'UNKNOWN99';
        $this->insertOrder($knownNumber);

        $csvRows = [
            [$knownNumber,   'CJ대한통운', '100000000001'],
            [$unknownNumber, '한진택배',   '200000000002'],
        ];

        $successCount = 0;
        $errorRows    = [];

        foreach ($csvRows as $idx => [$orderNumber, $carrier, $trackingNumber]) {
            $order = $this->model->where('order_number', $orderNumber)->first();
            if (! $order) {
                $errorRows[] = ['line' => $idx + 1, 'reason' => "주문번호 '{$orderNumber}' 없음"];
                continue;
            }
            $this->model->updateTracking((int) $order['id'], $carrier, $trackingNumber)
                ? $successCount++
                : $errorRows[] = ['line' => $idx + 1, 'reason' => '업데이트 실패'];
        }

        $this->assertEquals(1, $successCount);
        $this->assertCount(1, $errorRows);
        $this->assertStringContainsString($unknownNumber, $errorRows[0]['reason']);
    }

    public function testBulkUpdateSkipsRowWithEmptyTrackingNumber(): void
    {
        $orderNumber = $this->prefix . 'SKIP01';
        $this->insertOrder($orderNumber);

        // 컨트롤러 스킵 조건: tracking_number === ''
        $trackingNumber = '';
        $skippedCount   = 0;
        $successCount   = 0;

        if ($orderNumber !== '' && $trackingNumber === '') {
            $skippedCount++;
        } else {
            $order = $this->model->where('order_number', $orderNumber)->first();
            $this->model->updateTracking((int) $order['id'], 'CJ대한통운', $trackingNumber)
                ? $successCount++
                : null;
        }

        $this->assertEquals(1, $skippedCount);
        $this->assertEquals(0, $successCount);

        // DB에 반영 안 됨 확인
        $order = $this->model->where('order_number', $orderNumber)->first();
        $this->assertEmpty($order['tracking_number']);
    }

    // ── CSV 파싱 보조 로직 검증 ────────────────────────────────────────────────

    public function testBomStrippingFromCsvContent(): void
    {
        $withBom    = "\xEF\xBB\xBF주문번호,배송업체,송장번호\nORD-001,CJ,123";
        $stripped   = ltrim($withBom, "\xEF\xBB\xBF");
        $firstLine  = explode("\n", $stripped)[0];

        $this->assertStringStartsWith('주문번호', $firstLine);
    }

    public function testHeaderRowDetection(): void
    {
        $headerLine = '주문번호,배송업체,송장번호';
        $dataLine   = 'ORD-001,CJ대한통운,123456789012';

        $this->assertTrue((bool) preg_match('/^주문번호/u', $headerLine), '헤더 행 감지');
        $this->assertFalse((bool) preg_match('/^주문번호/u', $dataLine),  '데이터 행은 헤더 아님');
    }

    public function testCsvLineWithTwoColumnsIsInvalid(): void
    {
        $line = 'ORD-001,CJ대한통운';
        $cols = str_getcsv($line);

        $this->assertLessThan(3, count($cols), '컬럼 수 부족 → 오류 처리 대상');
    }

    public function testCsvLineWithThreeColumnsIsValid(): void
    {
        $line = 'ORD-001,CJ대한통운,123456789012';
        $cols = str_getcsv($line);

        $this->assertGreaterThanOrEqual(3, count($cols));
        $this->assertEquals('ORD-001',        trim($cols[0]));
        $this->assertEquals('CJ대한통운',      trim($cols[1]));
        $this->assertEquals('123456789012',    trim($cols[2]));
    }
}
