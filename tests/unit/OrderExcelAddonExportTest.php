<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\OrderController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 관리자 주문 목록 엑셀 내보내기 — '상품명' 칸에 애드온 그룹핑 전체가 보이는지 검증한다.
 *
 * 고친 버그: $nameMap[$order['id']] 는 AddonGrouping::labels() 의 전체 결과(예:
 * ['Patient Plate x1', '+ Gender x2'])를 갖고 있었지만, 셀에는 $names[0] 만 반영됐다.
 * order()가 본품을 항상 맨 앞에 두므로 '+' 접두어가 붙는 애드온 라벨은 절대 셀에
 * 도달할 수 없는 사실상 no-op이었다. 이 테스트는 로직을 재구현하지 않고
 * exportExcel() 을 실제로 호출해 나온 xlsx 바이트를 PhpSpreadsheet 로 다시 읽어
 * 셀 값을 검증한다.
 */
final class OrderExcelAddonExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['order_items' => [], 'orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'EXA' . substr(uniqid(), -6) . '_';
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

        service('request')->setGlobal('get', []);

        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $uid = uniqid();
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => 'exa_u_' . $uid,
            'email'      => 'exa_' . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => 'ExaUser_' . $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(string $orderNumber): int
    {
        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('orders')->insert([
            'user_id'             => $this->insertUser(),
            'order_number'        => $orderNumber,
            'status'              => 'paid',
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

    private function insertOrderItem(int $orderId, int $productId, ?int $parentProductId, string $productName, int $qty): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'          => $orderId,
            'product_id'        => $productId,
            'parent_product_id' => $parentProductId,
            'product_name'      => $productName,
            'qty'               => $qty,
            'product_price'     => 10000,
            'subtotal'          => 10000 * $qty,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
    }

    /**
     * OrderController::exportExcel() 을 실제로 호출해 xlsx 바이트를 받고,
     * PhpSpreadsheet 로 다시 읽어 주어진 주문번호 행의 '상품명' 칸(H열, 0-based idx 7)을 돌려준다.
     */
    private function productSummaryFor(string $orderNumber): string
    {
        service('request')->setGlobal('get', ['q' => $this->prefix]);

        $controller = new OrderController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $response = $controller->exportExcel();

        service('request')->setGlobal('get', []);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, (string) $response->getBody());
        $rows = IOFactory::load($tmp)->getActiveSheet()->toArray();
        unlink($tmp);

        foreach ($rows as $row) {
            if (($row[0] ?? null) === $orderNumber) {
                return (string) $row[7];
            }
        }

        $this->fail("주문 {$orderNumber} 을 엑셀에서 찾지 못했다");
    }

    public function testAddonOrderShowsFullGroupedLabelsWithPlusPrefix(): void
    {
        $orderNumber = $this->prefix . 'ADDON';
        $orderId     = $this->insertOrder($orderNumber);
        $this->insertOrderItem($orderId, 100001, null, 'Patient Plate', 1);
        $this->insertOrderItem($orderId, 100002, 100001, 'Gender', 2);

        $summary = $this->productSummaryFor($orderNumber);

        $this->assertSame('Patient Plate x1, + Gender x2', $summary, '애드온 라벨까지 전부 comma-join 되어야 한다');
        $this->assertStringContainsString('+ ', $summary, '애드온 라벨의 + 접두어가 셀에 도달해야 한다');
    }

    public function testPlainOrderShowsCommaJoinedLabelsWithoutPlusPrefix(): void
    {
        $orderNumber = $this->prefix . 'PLAIN';
        $orderId     = $this->insertOrder($orderNumber);
        $this->insertOrderItem($orderId, 200001, null, '상품A', 2);
        $this->insertOrderItem($orderId, 200002, null, '상품B', 1);

        $summary = $this->productSummaryFor($orderNumber);

        $this->assertSame('상품A x2, 상품B x1', $summary, '애드온이 없어도 전체 라벨이 comma-join 되어야 한다');
        $this->assertStringNotContainsString('+ ', $summary, '애드온이 없으면 + 접두어가 없어야 한다');
    }
}
