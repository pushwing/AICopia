<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문 상태 일괄 변경 검증 (#97)
 *
 * - paid → preparing / preparing → shipped 전환
 * - 허용되지 않는 전환 실패
 * - 복수 ID 일괄 처리 (updated / failed 카운트)
 * - 타겟 외 주문 영향 없음
 */
final class OrderBulkStatusTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    private array $cleanup = [
        'orders'            => [],
        'order_items'       => [],
        'order_status_logs' => [],
        'products'          => [],
        'users'             => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'order_items'] as $t) {
                $db->table($t)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        foreach (['products', 'users'] as $t) {
            if ($this->cleanup[$t] !== []) {
                $db->table($t)->whereIn('id', $this->cleanup[$t])->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'bulk_' . $uid,
            'email'      => 'bulk_' . $uid . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'BulkUser',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'           => 'BulkProd_' . $uid,
            'slug'           => 'bulk-prod-' . $uid,
            'price'          => 10000,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(string $status): int
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $db        = db_connect();

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'BULK-' . uniqid(),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'total_amount'           => 10000,
            'payable_amount'         => 10000,
            'shipping_fee'           => 0,
            'coupon_discount_amount' => 0,
            'point_used_amount'      => 0,
            'point_earned_amount'    => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => 'BulkProd',
            'product_price' => 10000,
            'cost_price'    => 0,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
        return $orderId;
    }

    /**
     * 컨트롤러 bulkUpdateStatus() 로직을 직접 구현
     *
     * @param int[]  $orderIds
     * @return array{updated:int, failed:int}
     */
    private function bulkUpdate(array $orderIds, string $status): array
    {
        $orderIds = array_slice(array_map('intval', $orderIds), 0, 100);
        $updated  = 0;
        $failed   = 0;
        foreach ($orderIds as $id) {
            if ($id <= 0) { $failed++; continue; }
            $this->model->updateStatus($id, $status) ? $updated++ : $failed++;
        }
        return compact('updated', 'failed');
    }

    // ── 단건 전환 ─────────────────────────────────────────────────────────────

    public function testPaidToPreparingSucceeds(): void
    {
        $id     = $this->insertOrder('paid');
        $result = $this->bulkUpdate([$id], 'preparing');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('preparing', $this->model->find($id)['status']);
    }

    public function testPreparingToShippedSucceeds(): void
    {
        $id     = $this->insertOrder('preparing');
        $result = $this->bulkUpdate([$id], 'shipped');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('shipped', $this->model->find($id)['status']);
    }

    // ── 허용되지 않는 전환 ────────────────────────────────────────────────────

    public function testPaidToShippedDirectlyFails(): void
    {
        $id     = $this->insertOrder('paid');
        $result = $this->bulkUpdate([$id], 'shipped');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('paid', $this->model->find($id)['status'], '상태가 그대로여야 함');
    }

    public function testInvalidTargetStatusFails(): void
    {
        $id     = $this->insertOrder('paid');
        $result = $this->bulkUpdate([$id], 'invalid_status');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
    }

    public function testNonExistentOrderIdFails(): void
    {
        $result = $this->bulkUpdate([999999999], 'preparing');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
    }

    // ── 복수 ID 일괄 처리 ─────────────────────────────────────────────────────

    public function testBulkUpdateMultipleOrdersAllValid(): void
    {
        $id1    = $this->insertOrder('paid');
        $id2    = $this->insertOrder('paid');
        $id3    = $this->insertOrder('paid');
        $result = $this->bulkUpdate([$id1, $id2, $id3], 'preparing');

        $this->assertSame(3, $result['updated']);
        $this->assertSame(0, $result['failed']);
        foreach ([$id1, $id2, $id3] as $id) {
            $this->assertSame('preparing', $this->model->find($id)['status']);
        }
    }

    public function testBulkUpdateMixedValidAndInvalid(): void
    {
        $validId   = $this->insertOrder('paid');
        $invalidId = $this->insertOrder('shipped'); // shipped→preparing 불가

        $result = $this->bulkUpdate([$validId, $invalidId], 'preparing');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('preparing', $this->model->find($validId)['status']);
        $this->assertSame('shipped',   $this->model->find($invalidId)['status']);
    }

    // ── 타겟 외 주문 영향 없음 ────────────────────────────────────────────────

    public function testBulkUpdateOnlyAffectsTargetOrders(): void
    {
        $target  = $this->insertOrder('paid');
        $bystander = $this->insertOrder('paid'); // 선택 안 됨

        $this->bulkUpdate([$target], 'preparing');

        $this->assertSame('preparing', $this->model->find($target)['status']);
        $this->assertSame('paid',      $this->model->find($bystander)['status'], '미선택 주문 상태 불변');
    }

    // ── 경계값 ────────────────────────────────────────────────────────────────

    public function testZeroIdIsCountedAsFailed(): void
    {
        $result = $this->bulkUpdate([0], 'preparing');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
    }

    public function testMaxHundredOrdersEnforced(): void
    {
        // 101개를 넘겨도 100개만 처리됨 (array_slice)
        $ids    = array_fill(0, 101, 999999998); // 존재하지 않는 ID
        $result = $this->bulkUpdate($ids, 'preparing');

        $this->assertSame(100, $result['updated'] + $result['failed'], '최대 100건만 처리돼야 함');
    }
}
