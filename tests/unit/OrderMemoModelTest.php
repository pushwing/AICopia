<?php

namespace Tests\Unit;

use App\Models\OrderMemoModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderMemoModel — 주문 내부 메모 CRUD 검증
 * 이슈 #64
 */
final class OrderMemoModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderMemoModel $model;

    private array $cleanup = [
        'order_memos' => [],
        'orders'      => [],
        'users'       => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderMemoModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['order_memos'] !== []) {
            $db->table('order_memos')->whereIn('id', $this->cleanup['order_memos'])->delete();
        }
        if ($this->cleanup['orders'] !== []) {
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertAdmin(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'memo_adm_' . $uid,
            'email'      => 'admin_memo_' . $uid . '@test.com',
            'password'   => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'   => 'TestAdmin_' . $uid,
            'role'       => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId = 0): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        if ($userId === 0) {
            $userId = $this->insertAdmin();
        }
        $db->table('orders')->insert([
            'user_id'             => $userId,
            'order_number'        => 'MEMO' . uniqid(),
            'status'              => 'paid',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'receiver_name'       => '테스트수취인',
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

    private function insertMemo(int $orderId, int $adminId, string $content = '테스트 메모'): int
    {
        $id = (int) $this->model->insert([
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'content'  => $content,
        ]);
        $this->cleanup['order_memos'][] = $id;
        return $id;
    }

    // ── getByOrder ────────────────────────────────────────────────────────────

    public function testGetByOrderReturnsEmptyArrayWhenNoMemos(): void
    {
        $orderId = $this->insertOrder();

        $result = $this->model->getByOrder($orderId);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetByOrderReturnsMemo(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $this->insertMemo($orderId, $adminId, '첫 번째 메모');

        $result = $this->model->getByOrder($orderId);

        $this->assertCount(1, $result);
        $this->assertEquals($orderId, (int) $result[0]['order_id']);
        $this->assertEquals('첫 번째 메모', $result[0]['content']);
    }

    public function testGetByOrderIncludesAdminName(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $db      = db_connect();
        $adminNickname = $db->table('users')->where('id', $adminId)->get()->getRowArray()['nickname'];
        $this->insertMemo($orderId, $adminId, '관리자명 테스트');

        $result = $this->model->getByOrder($orderId);

        $this->assertArrayHasKey('admin_name', $result[0]);
        $this->assertEquals($adminNickname, $result[0]['admin_name']);
    }

    public function testGetByOrderReturnsOnlyThisOrderMemos(): void
    {
        $order1  = $this->insertOrder();
        $order2  = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $this->insertMemo($order1, $adminId, '주문1 메모');
        $this->insertMemo($order2, $adminId, '주문2 메모');

        $result = $this->model->getByOrder($order1);

        $this->assertCount(1, $result);
        $this->assertEquals('주문1 메모', $result[0]['content']);
    }

    public function testGetByOrderReturnsMultipleMemosInAscendingOrder(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $this->insertMemo($orderId, $adminId, 'first');
        $this->insertMemo($orderId, $adminId, 'second');
        $this->insertMemo($orderId, $adminId, 'third');

        $result = $this->model->getByOrder($orderId);

        $this->assertCount(3, $result);
        $this->assertEquals('first',  $result[0]['content']);
        $this->assertEquals('second', $result[1]['content']);
        $this->assertEquals('third',  $result[2]['content']);
        // ID 오름차순 확인: result[2]['id'] > result[1]['id']
        $this->assertGreaterThan((int) $result[1]['id'], (int) $result[2]['id']);
    }

    // ── insert ────────────────────────────────────────────────────────────────

    public function testInsertMemoReturnsId(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();

        $id = (int) $this->model->insert([
            'order_id' => $orderId,
            'admin_id' => $adminId,
            'content'  => '삽입 테스트',
        ]);
        $this->cleanup['order_memos'][] = $id;

        $this->assertGreaterThan(0, $id);
    }

    public function testInsertedMemoIsPersisted(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $id      = $this->insertMemo($orderId, $adminId, '영속화 확인');

        $row = $this->model->find($id);

        $this->assertNotNull($row);
        $this->assertEquals('영속화 확인', $row['content']);
        $this->assertEquals($orderId, (int) $row['order_id']);
        $this->assertEquals($adminId, (int) $row['admin_id']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteMemoRemovesRow(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $id      = $this->insertMemo($orderId, $adminId, '삭제 대상');

        $this->model->delete($id);

        $this->assertNull($this->model->find($id));
    }

    public function testDeleteOneMemoDoesNotAffectOthers(): void
    {
        $orderId = $this->insertOrder();
        $adminId = $this->insertAdmin();
        $keep    = $this->insertMemo($orderId, $adminId, '유지할 메모');
        $del     = $this->insertMemo($orderId, $adminId, '삭제할 메모');

        $this->model->delete($del);

        $result = $this->model->getByOrder($orderId);
        $ids    = array_map('intval', array_column($result, 'id'));
        $this->assertContains($keep, $ids, '유지할 메모는 남아있어야 한다');
        $this->assertNotContains($del, $ids, '삭제한 메모는 사라져야 한다');
    }

    // ── 소유자 검증 패턴 (컨트롤러 로직 보완) ─────────────────────────────────

    public function testMemoOwnershipCanBeValidated(): void
    {
        $orderId  = $this->insertOrder();
        $admin1   = $this->insertAdmin();
        $admin2   = $this->insertAdmin();
        $memoId   = $this->insertMemo($orderId, $admin1, 'admin1 메모');

        $row = $this->model->find($memoId);

        $this->assertEquals($admin1, (int) $row['admin_id'], 'admin1 소유');
        $this->assertNotEquals($admin2, (int) $row['admin_id'], 'admin2는 소유자 아님');
    }
}
