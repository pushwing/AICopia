<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderModel::getByUser() 상태 매크로 그룹 필터 회귀 테스트
 *
 * 주문 목록 탭은 세분화된 내부 상태를 고객 인지 기준 5단계(전체·준비중·배송중·
 * 배송완료·취소/반품/교환)로 묶는다. 각 그룹 키가 OrderModel::STATUS_GROUPS 의
 * 상태 집합으로 정확히 확장되는지 검증한다.
 */
final class OrderStatusGroupFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private int $userId;

    /** @var array<int, int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'OSGF' . substr(uniqid(), -6);
        $this->userId = $this->insertUser();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->orderIds !== []) {
            $db->table('orders')->whereIn('id', $this->orderIds)->delete();
        }
        $db->table('users')->where('id', $this->userId)->delete();
        $this->orderIds = [];
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $this->prefix,
            'email'      => $this->prefix . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function insertOrder(string $status): void
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'             => $this->userId,
            'order_number'        => 'ORD-' . $this->prefix . '-' . count($this->orderIds),
            'status'              => $status,
            'total_product_price' => 10000,
            'shipping_fee'        => 0,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '홍길동',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트로 1',
            'address2'            => '',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $this->orderIds[] = (int) $db->insertID();
    }

    /** @return array<int, string> */
    private function fetchStatuses(string $group): array
    {
        $result = model(OrderModel::class)->getByUser($this->userId, ['status' => $group]);

        return array_column($result['items'], 'status');
    }

    public function testReadyGroupCoversPaymentAndPreparation(): void
    {
        foreach (['awaiting_payment', 'paid', 'preparing', 'shipped', 'delivered'] as $s) {
            $this->insertOrder($s);
        }

        $statuses = $this->fetchStatuses('ready');

        sort($statuses);
        $this->assertSame(['awaiting_payment', 'paid', 'preparing'], $statuses, '준비중 그룹이 결제~배송준비 상태만 담아야 한다');
    }

    public function testShippedAndDeliveredGroupsAreSingleStatus(): void
    {
        foreach (['paid', 'shipped', 'delivered'] as $s) {
            $this->insertOrder($s);
        }

        $this->assertSame(['shipped'], $this->fetchStatuses('shipped'), '배송중 그룹은 shipped 만');
        $this->assertSame(['delivered'], $this->fetchStatuses('delivered'), '배송완료 그룹은 delivered 만');
    }

    public function testClosedGroupCoversCancelReturnExchange(): void
    {
        foreach (['delivered', 'cancelled', 'expired', 'refunded', 'return_requested', 'exchange_completed'] as $s) {
            $this->insertOrder($s);
        }

        $statuses = $this->fetchStatuses('closed');

        sort($statuses);
        $this->assertSame(
            ['cancelled', 'exchange_completed', 'expired', 'refunded', 'return_requested'],
            $statuses,
            '취소·반품·교환 그룹이 종료/사후처리 상태를 모두 담아야 한다',
        );
    }

    public function testAllTabExcludesPendingAndExpired(): void
    {
        foreach (['pending', 'expired', 'paid', 'delivered'] as $s) {
            $this->insertOrder($s);
        }

        $statuses = $this->fetchStatuses('');

        sort($statuses);
        $this->assertSame(['delivered', 'paid'], $statuses, '전체 탭은 pending·expired 를 제외해야 한다');
    }
}
