<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문 확정이 재고 부족으로 실패했을 때의 보상 처리 (이슈 #113)
 *
 * 레거시 pending 주문(생성 시점에 쿠폰을 소진하고 포인트를 차감)을 대상으로 한다.
 * 확정(confirmPaid / confirmFree / confirmBankTransfer)이 재고 부족으로 롤백되면
 * 그 소진분이 그대로 묶인 채 남는다. 특히 무통장(awaiting_payment)은
 * expirePending() 대상이 아니라 영구히 복구되지 않았다.
 *
 * 확정 실패 시 같은 자리에서 쿠폰·포인트를 되돌리고 주문을 종료 상태로 전이한다.
 *
 * @internal
 */
final class OrderConfirmCompensationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private const PRODUCT_PRICE = 20000;
    private const POINT_BALANCE = 10000;
    private const POINT_USED    = 3000;
    private const COUPON_VALUE  = 5000;

    private OrderModel $model;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'order_status_logs' => [],
        'point_logs'        => [],
        'payments'          => [],
        'order_items'       => [],
        'orders'            => [],
        'user_coupons'      => [],
        'coupons'           => [],
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
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 픽스처 ────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'occ_' . $uid,
            'email'         => 'occ-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OccUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => self::POINT_BALANCE,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function insertProduct(int $stock): array
    {
        $db   = db_connect();
        $data = [
            'name'           => 'OccProduct_' . uniqid(),
            'slug'           => 'occ-prod-' . uniqid(),
            'price'          => self::PRODUCT_PRICE,
            'cost_price'     => 0,
            'stock'          => $stock,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return array_merge(['id' => $id], $data);
    }

    private function insertCoupon(int $discount): int
    {
        $db = db_connect();
        $db->table('coupons')->insert([
            'code'                => 'OCC-' . strtoupper(uniqid()),
            'name'                => 'OccCoupon',
            'type'                => 'fixed',
            'discount_value'      => $discount,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;
        return $id;
    }

    private function insertUserCoupon(int $userId, int $couponId): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => null,
            'source'     => 'admin',
            'status'     => 'issued',
            'issued_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;
        return $id;
    }

    /**
     * 쿠폰·포인트를 실제로 소진하는 정상 경로로 pending 주문을 만든다.
     *
     * 이 파일은 확정(confirmPaid/confirmFree/confirmBankTransfer)이 재고 부족으로
     * 실패했을 때의 보상을 검증한다 — 이 메서드들은 레거시 orders.status='pending'
     * 주문에만 동작하므로(이슈 #214 이후 신규 결제는 order_attempts 를 거쳐 바로
     * paid/awaiting_payment 로 전환되고 pending 을 거치지 않는다), 구 버전 주문
     * 생성 로직이 하던 쿠폰·포인트 선점을 이 헬퍼가 orders 에 직접 재현한다.
     *
     * @param  array<string, mixed> $product
     * @return array{order_id: int, user_id: int, coupon_id: int, user_coupon_id: int}
     */
    private function createConsumedOrder(array $product, int $qty, int $couponDiscount = self::COUPON_VALUE): array
    {
        $userId       = $this->insertUser();
        $couponId     = $this->insertCoupon($couponDiscount);
        $userCouponId = $this->insertUserCoupon($userId, $couponId);

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $totalProduct = (int) $product['price'] * $qty;
        $payable      = max(0, $totalProduct - $couponDiscount - self::POINT_USED);

        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'OCC-' . uniqid(),
            'status'                 => 'pending',
            'total_product_price'    => $totalProduct,
            'shipping_fee'           => 0,
            'total_amount'           => $totalProduct,
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => $couponDiscount,
            'point_used_amount'      => self::POINT_USED,
            'point_earned_amount'    => 0,
            'payable_amount'         => $payable,
            'receiver_name'          => '테스트 수령인',
            'receiver_phone'         => '010-0000-0000',
            'zipcode'                => '12345',
            'address1'               => '서울시 테스트구',
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        $orderId = (int) $db->insertID();

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => (int) $product['id'],
            'product_name'  => $product['name'],
            'product_price' => (int) $product['price'],
            'cost_price'    => 0,
            'qty'           => $qty,
            'subtotal'      => $totalProduct,
            'created_at'    => $now,
        ]);

        // 구 버전 주문 생성 로직이 주문 생성 시점에 하던 쿠폰·포인트 선점을 재현한다.
        $db->query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$couponId]);
        $db->table('user_coupons')->where('id', $userCouponId)->update([
            'status'     => 'used',
            'order_id'   => $orderId,
            'used_at'    => $now,
            'updated_at' => $now,
        ]);

        $db->query('UPDATE users SET point_balance = point_balance - ? WHERE id = ?', [self::POINT_USED, $userId]);
        $db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'use',
            'amount'     => -self::POINT_USED,
            'order_id'   => $orderId,
            'note'       => '주문 포인트 사용',
            'created_at' => $now,
        ]);

        $this->assertGreaterThan(0, $orderId, '픽스처 주문 생성 실패');
        $this->cleanup['orders'][] = $orderId;
        $this->track($orderId, $userId);

        return [
            'order_id'       => $orderId,
            'user_id'        => $userId,
            'coupon_id'      => $couponId,
            'user_coupon_id' => $userCouponId,
        ];
    }

    private function track(int $orderId, int $userId): void
    {
        $db = db_connect();
        foreach (['order_items', 'payments', 'order_status_logs'] as $table) {
            $ids = array_column(
                $db->table($table)->select('id')->where('order_id', $orderId)->get()->getResultArray(),
                'id'
            );
            $this->cleanup[$table] = array_merge($this->cleanup[$table], $ids);
        }
        $ids = array_column(
            $db->table('point_logs')->select('id')->where('user_id', $userId)->get()->getResultArray(),
            'id'
        );
        $this->cleanup['point_logs'] = array_merge($this->cleanup['point_logs'], $ids);
    }

    /** 무통장입금 주문으로 만든다 — awaiting_payment + pending 결제행. */
    private function toBankTransfer(int $orderId): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('orders')->where('id', $orderId)->update(['status' => 'awaiting_payment']);
        $db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => 'bank_transfer',
            'pg_tid'       => null,
            'method'       => '무통장입금',
            'amount'       => 12000,
            'status'       => 'pending',
            'raw_response' => '{}',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $this->cleanup['payments'][] = (int) $db->insertID();
    }

    // ── PG 결제 경로 ──────────────────────────────────────────────────────────

    /** 재고가 모자라면 주문을 pending 에 방치하지 않고 취소로 확정한다. */
    public function testConfirmPaidCancelsOrderWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->assertFalse($this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $order['status'], '재고 부족 주문이 pending 으로 방치됐습니다.');
        $this->assertNotNull($order['cancelled_at']);
    }

    /** 소진했던 쿠폰이 즉시 되돌아온다. */
    public function testConfirmPaidRestoresCouponWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $coupon = $db->table('coupons')->where('id', $ctx['coupon_id'])->get()->getRowArray();
        $this->assertSame(0, (int) $coupon['used_count'], '쿠폰 사용 횟수가 되돌아오지 않았습니다.');

        $userCoupon = $db->table('user_coupons')->where('id', $ctx['user_coupon_id'])->get()->getRowArray();
        $this->assertSame('issued', $userCoupon['status'], '쿠폰이 다시 사용 가능해지지 않았습니다.');
        $this->assertNull($userCoupon['order_id']);
    }

    /** 차감했던 포인트가 즉시 환급된다. */
    public function testConfirmPaidRestoresPointsWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE, $balance, '포인트가 환급되지 않았습니다.');
    }

    /**
     * 보상은 한 번만 일어나야 한다.
     *
     * 주문을 pending 으로 남겨두면 30분 뒤 expirePending() 이 같은 쿠폰·포인트를
     * 또 복구한다. restorePoints() 에는 중복 방어가 없어 포인트가 두 배로 환급된다.
     */
    public function testExpirePendingDoesNotDoubleRestoreCompensatedOrder(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        // 만료 배치가 훑고 지나가도록 생성 시각을 과거로 밀어 둔다.
        $db->table('orders')->where('id', $ctx['order_id'])
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))]);

        $this->model->expirePending(30);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE, $balance, '포인트가 이중으로 환급됐습니다.');

        $coupon = $db->table('coupons')->where('id', $ctx['coupon_id'])->get()->getRowArray();
        $this->assertSame(0, (int) $coupon['used_count']);
    }

    /** 왜 취소됐는지 추적할 수 있어야 한다. */
    public function testConfirmPaidWritesStatusLogWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);

        $this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $log = $db->table('order_status_logs')
            ->where('order_id', $ctx['order_id'])
            ->where('to_status', 'cancelled')
            ->get()->getRowArray();

        $this->assertNotNull($log, '취소 상태 로그가 없습니다.');
        $this->assertStringContainsString('재고', (string) $log['note']);
    }

    /** 재고가 충분하면 보상이 끼어들지 않는다. */
    public function testConfirmPaidStillSucceedsWhenStockIsEnough(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(10);
        $ctx     = $this->createConsumedOrder($product, 2);

        $this->assertTrue($this->model->confirmPaid($ctx['order_id'], 'toss', 'tid_' . uniqid(), 'card', []));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('paid', $order['status']);

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE - self::POINT_USED, $balance, '정상 주문인데 포인트가 환급됐습니다.');
    }

    // ── 무료 주문 경로 ────────────────────────────────────────────────────────

    /** 무료 주문도 같은 보상을 받는다. */
    public function testConfirmFreeCompensatesWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5, self::PRODUCT_PRICE * 5);

        $this->assertFalse($this->model->confirmFree($ctx['order_id']));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE, $balance);
    }

    // ── 무통장입금 경로 (영구 잠김이던 곳) ────────────────────────────────────

    /**
     * awaiting_payment 는 expirePending() 대상이 아니라 복구 경로가 아예 없었다.
     * 확정 실패 시 그 자리에서 되돌려야 한다.
     */
    public function testConfirmBankTransferCompensatesWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $this->toBankTransfer($ctx['order_id']);

        $this->assertFalse($this->model->confirmBankTransfer($ctx['order_id']));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $order['status'], '무통장 주문이 awaiting_payment 에 갇혔습니다.');

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE, $balance);

        $coupon = $db->table('coupons')->where('id', $ctx['coupon_id'])->get()->getRowArray();
        $this->assertSame(0, (int) $coupon['used_count']);
    }

    /** 취소된 주문에 입금대기 결제행이 남아 있으면 관리자 화면이 어긋난다. */
    public function testConfirmBankTransferMarksPaymentCancelledWhenStockInsufficient(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(1);
        $ctx     = $this->createConsumedOrder($product, 5);
        $this->toBankTransfer($ctx['order_id']);

        $this->model->confirmBankTransfer($ctx['order_id']);
        $this->track($ctx['order_id'], $ctx['user_id']);

        $payment = $db->table('payments')->where('order_id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $payment['status']);
    }

    /** 이미 처리된 주문(입금대기가 아님)은 보상 대상이 아니다. */
    public function testConfirmBankTransferDoesNotCompensateAlreadyHandledOrder(): void
    {
        $db      = db_connect();
        $product = $this->insertProduct(10);
        $ctx     = $this->createConsumedOrder($product, 1);

        // pending 그대로 — awaiting_payment 가 아니라 조회 자체가 실패한다.
        $this->assertFalse($this->model->confirmBankTransfer($ctx['order_id']));
        $this->track($ctx['order_id'], $ctx['user_id']);

        $order = $db->table('orders')->where('id', $ctx['order_id'])->get()->getRowArray();
        $this->assertSame('pending', $order['status'], '관계없는 주문이 취소됐습니다.');

        $balance = (int) $db->table('users')->select('point_balance')
            ->where('id', $ctx['user_id'])->get()->getRow()->point_balance;
        $this->assertSame(self::POINT_BALANCE - self::POINT_USED, $balance, '관계없는 주문의 포인트가 환급됐습니다.');
    }
}
