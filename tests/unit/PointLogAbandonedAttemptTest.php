<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use App\Models\PointLogModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 결제창을 닫아 버려진 주문 시도의 포인트 선점·환급 쌍이
 * 포인트 내역에서 보이지 않는지 검증한다.
 *
 * 선점(use)과 환급(refund)은 합이 0이라 잔액에 영향이 없는데도 내역에는
 * 두 줄씩 쌓여, 결제창을 여닫기만 한 회원의 내역이 허수로 도배된다.
 *
 * 숨김 기준은 "그 시도가 끝내 주문이 되지 못했다"이다 — 승인은 됐는데 전환에
 * 실패해 보상 주문이 남은 시도(order_attempts.order_id 존재)는 실제 청구가
 * 있었던 건이라 그대로 보여야 한다.
 */
final class PointLogAbandonedAttemptTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['point_logs' => [], 'order_attempts' => [], 'orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PLAA' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['point_logs', 'order_attempts', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['point_logs' => [], 'order_attempts' => [], 'orders' => [], 'users' => []];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $this->prefix,
            'email'         => $this->prefix . '@example.test',
            'password'      => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'      => $this->prefix,
            'role'          => 'member',
            'point_balance' => 5000,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $orderNumber, string $status = 'cancelled'): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => $status,
            'total_product_price'    => 10000,
            'shipping_fee'           => 0,
            'total_amount'           => 10000,
            'payable_amount'         => 8620,
            'point_used_amount'      => 1380,
            'point_earned_amount'    => 0,
            'coupon_id'              => null,
            'coupon_discount_amount' => 0,
            'receiver_name'          => '홍길동',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트로 1',
            'address2'               => '',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
        $id                        = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    private function insertAttempt(int $userId, string $status, ?int $orderId = null): int
    {
        $db = db_connect();
        $db->table('order_attempts')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-' . $this->prefix . '-' . random_int(1000, 9999),
            'status'              => $status,
            'total_product_price' => 10000,
            'shipping_fee'        => 0,
            'total_amount'        => 10000,
            'point_used_amount'   => 1380,
            'payable_amount'      => 8620,
            'receiver_name'       => '홍길동',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트로 1',
            'items_snapshot'      => '[]',
            'order_id'            => $orderId,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id                                = (int) $db->insertID();
        $this->cleanup['order_attempts'][] = $id;

        return $id;
    }

    private function insertPointLog(
        int $userId,
        string $type,
        int $amount,
        ?int $orderId,
        ?int $attemptId,
        string $note
    ): int {
        $db = db_connect();
        $db->table('point_logs')->insert([
            'user_id'          => $userId,
            'type'             => $type,
            'amount'           => $amount,
            'order_id'         => $orderId,
            'order_attempt_id' => $attemptId,
            'note'             => $note,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
        $id                            = (int) $db->insertID();
        $this->cleanup['point_logs'][] = $id;

        return $id;
    }

    /** 결제창을 닫아 버려진 시도 1건 — 선점 + 환급 쌍을 만든다. */
    private function insertAbandonedPair(int $userId, string $status = 'failed'): int
    {
        $attemptId = $this->insertAttempt($userId, $status);
        $this->insertPointLog($userId, 'use', -1380, null, $attemptId, '주문 포인트 사용');
        $this->insertPointLog(
            $userId,
            'refund',
            1380,
            null,
            $attemptId,
            $status === 'expired' ? '주문 만료 포인트 환급' : '결제 미완료 포인트 환급'
        );

        return $attemptId;
    }

    private function controller(): MyPageController
    {
        $controller = new MyPageController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    public function testHidesPointPairOfAbandonedAttempt(): void
    {
        $userId = $this->insertUser();
        $this->insertAbandonedPair($userId);

        $result = new PointLogModel()->getByUser($userId);

        $this->assertSame([], $result['items'], '결제창을 닫은 시도의 포인트 선점·환급이 내역에 남았다');
        $this->assertSame(0, $result['total'], '숨긴 로그가 총 건수(페이지네이션)에는 그대로 잡힌다');
    }

    public function testHidesPointPairOfExpiredAttempt(): void
    {
        $userId = $this->insertUser();
        $this->insertAbandonedPair($userId, 'expired');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertSame([], $result['items'], '만료된 시도의 포인트 선점·환급이 내역에 남았다');
    }

    /**
     * 결제창이 아직 떠 있는 동안(pending)은 잔액이 이미 차감된 상태다 —
     * 이때 선점 로그까지 숨기면 "잔액은 줄었는데 내역이 없다"가 된다.
     */
    public function testKeepsPointUseWhileAttemptIsPending(): void
    {
        $userId    = $this->insertUser();
        $attemptId = $this->insertAttempt($userId, 'pending');
        $this->insertPointLog($userId, 'use', -1380, null, $attemptId, '주문 포인트 사용');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(1, $result['items'], '진행 중인 결제의 포인트 사용 내역이 사라졌다');
        $this->assertSame(1, $result['total']);
    }

    /** 전환에 성공한 시도의 로그는 order_id 가 채워진 채 그대로 보인다. */
    public function testKeepsPointUseOfConvertedAttempt(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix . '-OK';
        $orderId     = $this->insertOrder($userId, $orderNumber, 'paid');
        $attemptId   = $this->insertAttempt($userId, 'converted', $orderId);
        $this->insertPointLog($userId, 'use', -1380, $orderId, $attemptId, '주문 포인트 사용');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(1, $result['items'], '정상 주문의 포인트 사용 내역까지 숨겨졌다');
        $this->assertSame($orderNumber, $result['items'][0]['order_number'] ?? null);
    }

    /**
     * 승인은 됐는데 전환에 실패해 보상 주문이 남은 시도 — 실제 청구가 있었던
     * 건이므로 선점·환급이 모두 보여야 한다. 한쪽만 숨기면 근거 없는 환급
     * 한 줄만 남아 잔액과 내역 합계가 어긋난다.
     */
    public function testKeepsPointPairWhenCompensationOrderExists(): void
    {
        $userId    = $this->insertUser();
        $orderId   = $this->insertOrder($userId, 'ORD-' . $this->prefix . '-CMP');
        $attemptId = $this->insertAttempt($userId, 'failed', $orderId);
        $this->insertPointLog($userId, 'use', -1380, null, $attemptId, '주문 포인트 사용');
        $this->insertPointLog($userId, 'refund', 1380, $orderId, $attemptId, '재고 부족 주문 취소 포인트 환급');

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(2, $result['items'], '보상 주문이 남은 청구 건의 포인트 내역이 숨겨졌다');
    }

    /** 시도와 무관한 로그(가입 보너스 등)는 영향을 받지 않는다. */
    public function testKeepsLogsWithoutAttempt(): void
    {
        $userId = $this->insertUser();
        $this->insertPointLog($userId, 'admin', 1000, null, null, '신규 가입 보너스');
        $this->insertAbandonedPair($userId);

        $result = new PointLogModel()->getByUser($userId);

        $this->assertCount(1, $result['items']);
        $this->assertSame('신규 가입 보너스', $result['items'][0]['note']);
    }

    public function testPointsPageDoesNotRenderAbandonedPair(): void
    {
        $userId = $this->insertUser();
        $this->insertPointLog($userId, 'admin', 1000, null, null, '신규 가입 보너스');
        $this->insertAbandonedPair($userId);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $html = $this->controller()->points();

        $this->assertStringNotContainsString('결제 미완료 포인트 환급', $html, '결제창을 닫은 흔적이 화면에 남았다');
        $this->assertStringNotContainsString('주문 포인트 사용', $html, '결제창을 닫은 흔적이 화면에 남았다');
        $this->assertStringContainsString('신규 가입 보너스', $html, '정상 내역까지 사라졌다');
    }

    /** 관리자 회원 상세 포인트 탭도 같은 기준으로 걸러야 화면끼리 어긋나지 않는다. */
    public function testAdminRecentListHidesAbandonedPair(): void
    {
        $userId = $this->insertUser();
        $this->insertPointLog($userId, 'admin', 1000, null, null, '신규 가입 보너스');
        $this->insertAbandonedPair($userId);

        $rows = new PointLogModel()->getRecentByUser($userId);

        $this->assertCount(1, $rows, '관리자 화면에는 허수 내역이 그대로 남았다');
        $this->assertSame('신규 가입 보너스', $rows[0]['note']);
    }
}
