<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderController::json() 데이터 레이어 검증
 *
 * - 반환 필드 구조
 * - status_label 매핑 (STATUS_LABELS)
 * - total_amount, memo_count int 캐스팅
 * - user_email/user_nickname null → '' 처리
 * - pg_provider, payment_method null → '' 처리
 * - id DESC 정렬
 */
final class OrderJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private const STATUS_LABELS = [
        'pending'          => '결제 대기',
        'awaiting_payment' => '입금 대기',
        'paid'             => '결제 완료',
        'preparing'        => '배송 준비',
        'shipped'          => '배송 중',
        'delivered'        => '배송 완료',
        'cancelled'        => '취소',
        'expired'          => '만료',
        'refund_requested' => '환불 요청',
        'refunded'         => '환불 완료',
    ];

    private string $prefix;
    private array  $cleanup = ['orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'OJT' . substr(uniqid(), -6) . '_';
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
        $this->cleanup = ['orders' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'username'   => 'ojt_' . $uid,
            'email'      => $this->prefix . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix . $uid,
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('users')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'user_id'              => $userId,
            'order_number'         => 'ORD-OJT-' . $uid,
            'status'               => 'pending',
            'total_product_price'  => 10000,
            'shipping_fee'         => 0,
            'total_amount'         => 10000,
            'payable_amount'       => 10000,
            'receiver_name'        => '테스트수취인',
            'receiver_phone'       => '010-0000-0000',
            'zipcode'              => '12345',
            'address1'             => '서울시 테스트구',
            'address2'             => '',
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('orders')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    /** OrderController::json() 와 동일한 쿼리 + 변환 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $db      = db_connect();
        $builder = $db->table('orders o')
            ->select('o.id, o.order_number, o.created_at, o.receiver_name, o.total_amount, o.status,
                      u.email AS user_email, u.nickname AS user_nickname,
                      lp.pg_provider, lp.method AS payment_method,
                      (SELECT COUNT(*) FROM order_memos WHERE order_id = o.id) AS memo_count')
            ->join('users u', 'u.id = o.user_id', 'left')
            ->join('payments lp', 'lp.id = (SELECT MAX(id) FROM payments WHERE order_id = o.id)', 'left')
            ->orderBy('o.id', 'DESC');

        if ($whereIn !== []) {
            $builder->whereIn('o.id', $whereIn);
        }

        $rows = $builder->get()->getResultArray();
        return array_map(fn($r) => [
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
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id]);

        $expected = ['id', 'order_number', 'created_at', 'user_email', 'user_nickname',
                     'receiver_name', 'pg_provider', 'payment_method',
                     'total_amount', 'status', 'status_label', 'memo_count'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── status_label 매핑 ─────────────────────────────────────────────────────

    public function testStatusLabelPending(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid, ['status' => 'pending']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('결제 대기', $rows[0]['status_label']);
    }

    public function testStatusLabelPaid(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid, ['status' => 'paid']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('결제 완료', $rows[0]['status_label']);
    }

    public function testStatusLabelCancelled(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid, ['status' => 'cancelled']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('취소', $rows[0]['status_label']);
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIdIsInteger(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['id']);
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testTotalAmountIsInteger(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid, ['total_amount' => 29800, 'payable_amount' => 29800, 'total_product_price' => 29800]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['total_amount']);
        $this->assertSame(29800, $rows[0]['total_amount']);
    }

    public function testMemoCountIsInteger(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['memo_count']);
        $this->assertSame(0, $rows[0]['memo_count'], '메모 없는 주문의 memo_count 는 0 이어야 한다');
    }

    // ── null 기본값 처리 ──────────────────────────────────────────────────────

    public function testPgProviderEmptyStringWhenNoPayment(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['pg_provider'], '결제 내역 없는 주문의 pg_provider 는 빈 문자열이어야 한다');
    }

    public function testPaymentMethodEmptyStringWhenNoPayment(): void
    {
        $uid  = $this->insertUser();
        $id   = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['payment_method']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $uid  = $this->insertUser();
        $id1  = $this->insertOrder($uid);
        $id2  = $this->insertOrder($uid);
        $id3  = $this->insertOrder($uid);
        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }
}
