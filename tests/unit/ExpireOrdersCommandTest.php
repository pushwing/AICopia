<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * orders:expire — 신규 시도와 레거시 주문을 모두 걷어가는지
 * 이슈 #214
 *
 * 모델 메서드를 직접 부르지 않고 커맨드를 실제로 구동한다. 커맨드가 둘 중
 * 하나만 호출하도록 퇴행하면 이 테스트가 잡아야 하기 때문이다.
 */
final class ExpireOrdersCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $attemptIds = [];
    /** @var array<int, int> */
    private array $orderIds = [];
    private int $userId = 0;

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->orderIds !== []) {
            $db->table('order_status_logs')->whereIn('order_id', $this->orderIds)->delete();
            $db->table('orders')->whereIn('id', $this->orderIds)->delete();
        }
        if ($this->attemptIds !== []) {
            $db->table('order_attempts')->whereIn('id', $this->attemptIds)->delete();
        }
        if ($this->userId > 0) {
            $db->table('users')->where('id', $this->userId)->delete();
        }

        $this->attemptIds = [];
        $this->orderIds   = [];
        $this->userId     = 0;
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'exptest_' . $uid,
            'email'         => 'exp-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'ExpUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->userId = (int) $db->insertID();

        return $this->userId;
    }

    /** E-01: 신규 시도와 레거시 pending 주문이 모두 만료된다 */
    public function testExpireHandlesAttemptsAndLegacyOrders(): void
    {
        $db     = db_connect();
        $userId = $this->insertUser();
        $old    = date('Y-m-d H:i:s', strtotime('-40 minutes'));

        // 신규 경로 — 주문 시도
        $db->table('order_attempts')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-EXPA-' . random_int(10000, 99999),
            'status'              => 'pending',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '테스트',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트구',
            'items_snapshot'      => '[]',
            'created_at'          => $old,
            'updated_at'          => $old,
        ]);
        $attemptId          = (int) $db->insertID();
        $this->attemptIds[] = $attemptId;

        // 레거시 경로 — 배포 전에 만들어진 pending 주문
        $db->table('orders')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-EXPO-' . random_int(10000, 99999),
            'status'              => 'pending',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '테스트',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트구',
            'created_at'          => $old,
            'updated_at'          => $old,
        ]);
        $orderId          = (int) $db->insertID();
        $this->orderIds[] = $orderId;

        // 커맨드를 실제로 구동한다. 모델을 직접 부르면 커맨드의 퇴행을 못 잡는다.
        command('orders:expire 30');

        $this->assertSame(
            'expired',
            $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status'],
            '커맨드가 order_attempts 를 만료시키지 않았다'
        );
        $this->assertSame(
            'expired',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status'],
            '커맨드가 레거시 orders.pending 을 만료시키지 않았다 — 배포 호환 경로가 빠졌다'
        );
    }
}
