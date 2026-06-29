<?php

namespace Tests\Unit;

use App\Libraries\OrderAnomalyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 이상 주문 탐지 검증 (#10)
 *
 * 규칙: 고액 / 단시간 다건 / 동일 연락처·다계정
 */
final class OrderAnomalyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderAnomalyService $svc;

    private array $cleanup = ['orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OrderAnomalyService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['orders'] !== []) {
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username' => 'oa_' . $uid, 'email' => 'oa_' . $uid . '@test.com',
            'password' => password_hash('x', PASSWORD_DEFAULT), 'nickname' => 'OA_' . $uid,
            'role' => 'member', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, int $amount, string $phone, int $minutesAgo = 0, string $status = 'paid'): int
    {
        $db   = db_connect();
        $when = date('Y-m-d H:i:s', strtotime("-{$minutesAgo} minutes"));
        $db->table('orders')->insert([
            'user_id' => $userId, 'order_number' => 'OA' . uniqid(), 'status' => $status,
            'total_product_price' => $amount, 'total_amount' => $amount, 'payable_amount' => $amount,
            'receiver_name' => '홍길동', 'receiver_phone' => $phone, 'zipcode' => '12345', 'address1' => '서울',
            'created_at' => $when, 'updated_at' => $when,
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function findById(array $list, int $id): ?array
    {
        foreach ($list as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    private function hasReason(array $row, string $needle): bool
    {
        foreach ($row['reasons'] as $r) {
            if (str_contains($r, $needle)) {
                return true;
            }
        }
        return false;
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testFlagsHighAmountOrder(): void
    {
        $user = $this->insertUser();
        $oid  = $this->insertOrder($user, 2000000, '010-1111-2222'); // 고액(>=100만)

        $row = $this->findById($this->svc->flagged(7), $oid);
        $this->assertNotNull($row);
        $this->assertTrue($this->hasReason($row, '고액'));
    }

    public function testDoesNotFlagNormalOrder(): void
    {
        $user = $this->insertUser();
        $oid  = $this->insertOrder($user, 30000, '010-3333-4444'); // 정상

        $this->assertNull($this->findById($this->svc->flagged(7), $oid));
    }

    public function testFlagsBurstOrders(): void
    {
        $user = $this->insertUser();
        // 같은 회원이 30분 내 3건 (소액 → 고액 규칙엔 안 걸림)
        $o1 = $this->insertOrder($user, 10000, '010-5555-6666', 20);
        $o2 = $this->insertOrder($user, 10000, '010-5555-6666', 10);
        $o3 = $this->insertOrder($user, 10000, '010-5555-6666', 5);

        $flagged = $this->svc->flagged(7);
        foreach ([$o1, $o2, $o3] as $oid) {
            $row = $this->findById($flagged, $oid);
            $this->assertNotNull($row, "주문 {$oid}이 단시간 다건으로 탐지돼야 한다");
            $this->assertTrue($this->hasReason($row, '단시간'));
        }
    }

    public function testFlagsMultiAccountPhone(): void
    {
        $phone = '010-7777-' . random_int(1000, 9999);
        $u1 = $this->insertUser();
        $u2 = $this->insertUser();
        $o1 = $this->insertOrder($u1, 10000, $phone);
        $o2 = $this->insertOrder($u2, 10000, $phone); // 같은 연락처, 다른 계정

        $flagged = $this->svc->flagged(7);
        $this->assertTrue($this->hasReason($this->findById($flagged, $o1) ?? ['reasons' => []], '다계정'));
        $this->assertTrue($this->hasReason($this->findById($flagged, $o2) ?? ['reasons' => []], '다계정'));
    }

    public function testRiskScoreCountsMultipleReasons(): void
    {
        $phone = '010-8888-' . random_int(1000, 9999);
        $u1 = $this->insertUser();
        $u2 = $this->insertUser();
        // 고액 + 동일 연락처 다계정 → risk 2
        $o1 = $this->insertOrder($u1, 3000000, $phone);
        $this->insertOrder($u2, 10000, $phone);

        $row = $this->findById($this->svc->flagged(7), $o1);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(2, $row['risk']);
    }
}
