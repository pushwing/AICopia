<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문 시도 → 주문 전환
 * 이슈 #214
 */
final class OrderAttemptConversionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel        $orderModel;
    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'orders'         => [],
        'products'       => [],
        'users'          => [],
        'coupons'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'point_logs', 'payments', 'order_items'] as $table) {
                $db->table($table)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('user_coupons')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        if ($this->cleanup['coupons'] !== []) {
            $db->table('user_coupons')->whereIn('coupon_id', $this->cleanup['coupons'])->delete();
            $db->table('coupons')->whereIn('id', $this->cleanup['coupons'])->delete();
        }
        foreach (['products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'octest_' . $uid,
            'email'         => 'oc-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OCUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OCProd_' . uniqid(),
            'slug'           => 'oc-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /** @param array<string, mixed> $product */
    private function createAttempt(int $userId, array $product, int $qty = 1): int
    {
        $id = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
                'address2'       => null,
                'delivery_memo'  => null,
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => $qty,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            null,
            null,
            0,
            0,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    private function track(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
        }

        return $orderId;
    }

    /** @param array<string, mixed> $overrides */
    private function insertCoupon(array $overrides = []): int
    {
        $db   = db_connect();
        $data = array_merge([
            'code'                => 'OCT' . uniqid(),
            'name'                => 'OC쿠폰',
            'type'                => 'fixed',
            'discount_value'      => 1000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => 10,
            'used_count'          => 5,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $overrides);
        $db->table('coupons')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return $id;
    }

    /** C-01: 전환하면 orders + order_items 가 생기고 재고가 차감된다 */
    public function testConvertAttempt_createsOrderAndDeductsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 3);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $orderId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $order['status']);
        $this->assertSame(30000, (int) $order['total_amount']);
        $this->assertNotNull($order['paid_at']);

        $items = $db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
        $this->assertCount(1, $items);
        $this->assertSame(3, (int) $items[0]['qty']);
        $this->assertSame(30000, (int) $items[0]['subtotal']);

        $this->assertSame(7, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('converted', $attempt['status']);
        $this->assertSame($orderId, (int) $attempt['order_id']);
    }

    /** C-02: 같은 시도를 두 번 전환해도 주문은 1건만 생긴다 (결제 멱등성) */
    public function testConvertAttempt_twice_createsSingleOrder(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product);

        $first  = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-A-' . uniqid(), 'card', []));
        $second = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-B-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '두 번째 전환은 반드시 거부돼야 한다');
        $this->assertSame(1, $db->table('orders')->where('user_id', $userId)->countAllResults());
        // 재고도 한 번만 차감돼야 한다.
        $this->assertSame(9, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
    }

    /** C-03: 무통장은 awaiting_payment 로 전환되고 재고는 아직 차감하지 않는다 */
    public function testConvertAttempt_bankTransfer_keepsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 2);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'awaiting_payment', 'bank_transfer', null, '무통장입금', []));

        $this->assertGreaterThan(0, $orderId);
        $this->assertSame('awaiting_payment', $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']);
        $this->assertSame(10, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $payment['status']);
    }

    /** C-04: 재고가 모자라면 주문을 취소 상태로 남겨 환불 추적이 가능하게 한다 */
    public function testConvertAttempt_insufficientStock_leavesCancelledOrderWithCharge(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 1]);
        $attemptId = $this->createAttempt($userId, $product, 5);
        $tid       = 'TID-FAIL-' . uniqid();

        $orderId = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', $tid, 'card', []);

        $this->assertSame(0, $orderId, '전환은 실패로 보고돼야 한다');

        // 청구는 이미 일어났으므로 환불 추적용 흔적이 남아야 한다.
        // track()을 단언보다 먼저 실행해, 단언이 깨져도 보상 주문·payments·
        // order_items·order_status_logs 가 테스트 DB 에 남지 않게 한다.
        $order = $db->table('orders')->where('user_id', $userId)->get()->getRowArray();
        $this->track((int) ($order['id'] ?? 0));
        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order['status']);

        $payment = $db->table('payments')->where('pg_tid', $tid)->get()->getRowArray();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment['status']);

        $this->assertSame(1, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
    }

    /**
     * C-05 (회귀 — Critical 리뷰): compensateFailedConversion() 이 이미 다른 주체가
     * 걷어간 시도에 대해 쿠폰·포인트를 또 복구하면 안 된다 (이중 환급 금지).
     *
     * 실제 경합 창은 convertAttempt() 내부에서 전환 트랜잭션이 롤백된 직후(시도가
     * DB 상 다시 'pending' 으로 보이는 순간)부터 compensateFailedConversion() 이
     * order_attempts 를 조건부로 확정하는 순간까지다. 단일 스레드 PHPUnit 프로세스로는
     * 그 찰나의 인터리빙을 그대로 재현할 수 없으므로, compensateFailedConversion() 이
     * 호출되는 시점에 실제로 관찰하게 되는 사전 상태 — "시도가 이미 다른 주체
     * (markFailed)에 의해 failed 로 확정되고 쿠폰·포인트가 이미 복구된 상태" —
     * 를 직접 만든 뒤, 그 상태에서 compensateFailedConversion() 을 (Reflection 으로)
     * 호출해 "복구가 두 번 일어나지 않는다"는 동작만 정밀하게 검증한다.
     */
    public function testCompensateFailedConversion_skipsRestore_whenAttemptAlreadyReapedElsewhere(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['stock' => 10]);

        // used_count=5/total_qty=10 로 시작해, "선점으로 6 → 정상 복구 후 5" 를
        // 코딩으로 관찰 가능하게 한다. GREATEST(0, ...) 클램프 때문에 0 근방에서는
        // 이중 복구 여부가 값으로 드러나지 않는다.
        $couponId = $this->insertCoupon(['used_count' => 5, 'total_qty' => 10]);

        $db->table('users')->where('id', $userId)->update(['point_balance' => 5000]);

        $attemptId = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
                'address2'       => null,
                'delivery_memo'  => null,
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => 1,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            $couponId,
            null,
            1000,
            5000,
            0,
            'toss'
        );
        $this->assertGreaterThan(0, $attemptId, '시도 생성(쿠폰·포인트 선점)은 성공해야 한다');
        $this->cleanup['order_attempts'][] = $attemptId;

        $this->assertSame(0, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'], '선점 직후 포인트는 전액 차감돼야 한다');
        $this->assertSame(6, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'], '선점 직후 쿠폰 used_count 는 +1 이어야 한다');

        // 경합 스윕 — 다른 주체(만료 스윕 등)가 compensateFailedConversion() 보다
        // 먼저 이 시도를 걷어간다. 이 시점에 시도는 여전히 'pending' 이므로 성공한다.
        $reaped = $this->attemptModel->markFailed($attemptId, '경쟁 스윕 — 다른 주체가 선점');
        $this->assertTrue($reaped, '경쟁 스윕은 pending 상태에서 성공해야 한다');
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'], '경쟁 스윕이 포인트를 정확히 1회 환급해야 한다');
        $this->assertSame(5, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'], '경쟁 스윕이 쿠폰 used_count 를 정확히 1회 되돌려야 한다');
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);

        // convertAttempt() 이 재고 부족 등으로 실패한 뒤 compensateFailedConversion()
        // 에 실제로 넘기는 것과 동일한 형태의 attempt 배열을 준비한다.
        $row     = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $attempt = $this->attemptModel->withItems($row);
        $charge  = [
            'pg_provider' => 'toss',
            'pg_tid'      => 'TID-RACE-' . uniqid(),
            'method'      => 'card',
            'amount'      => 4000,
            'raw'         => [],
        ];

        $method = new \ReflectionMethod(OrderModel::class, 'compensateFailedConversion');
        $method->setAccessible(true);
        $method->invoke($this->orderModel, $attempt, '테스트 보상 — 재고 부족(경합 재현)', $charge);

        $compensated = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertNotNull($compensated['order_id'], '경합에서 졌더라도 보상 주문은 order_attempts 에 연결돼야 한다');
        $this->track((int) $compensated['order_id']);

        // 핵심 단언 — 이미 한 번 복구된 것을 또 복구하면 안 된다.
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance'], '이미 복구된 포인트를 compensateFailedConversion() 이 또 환급하면 안 된다');
        $this->assertSame(5, (int) $db->table('coupons')->where('id', $couponId)->get()->getRowArray()['used_count'], '이미 복구된 쿠폰 used_count 를 compensateFailedConversion() 이 또 되돌리면 안 된다');
        // OrderModel::restorePoints() 는 refund 로그에 order_id 만 남기고
        // order_attempt_id 는 채우지 않으므로, order_attempt_id 로 스코프하면
        // compensateFailedConversion() 이 이중 환급을 해도 markFailed() 쪽
        // 1건만 잡혀 항상 참이 되는 단언이 된다. user_id 로 스코프해야 이
        // 테스트 전용 사용자에 대한 refund 로그 총량(이중 환급 시 2건)이 잡힌다.
        $this->assertSame(
            1,
            $db->table('point_logs')->where('user_id', $userId)->where('type', 'refund')->countAllResults(),
            'refund 로그는 정확히 1건이어야 한다(이중 환급 금지)'
        );

        // 청구 흔적(취소 주문 + paid 결제행)은 경합에서 졌더라도 반드시 남아야 한다.
        $order = $db->table('orders')->where('id', $compensated['order_id'])->get()->getRowArray();
        $this->assertSame('cancelled', $order['status']);
        $payment = $db->table('payments')->where('pg_tid', $charge['pg_tid'])->get()->getRowArray();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment['status']);
    }
}
