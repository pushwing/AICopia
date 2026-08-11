<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * SKU 옵션 추가금(price_diff)이 청구 금액에도 반영되는지 검증 (이슈 #124)
 *
 * 장바구니 표시가(display_price)와 order_items 는 base + price_diff 로 계산하는데
 * 주문 총액·payable_amount 는 base 만 더하고 있었다. 그 결과 옵션 추가금이
 * 청구되지 않은 채 상품이 출고되고, orders.total_amount 와
 * SUM(order_items.subtotal) 이 어긋나 매출 집계까지 오염됐다.
 */
final class SkuPriceDiffChargeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel $model;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['order_attempts' => [], 'order_items' => [], 'orders' => [], 'products' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['order_attempts', 'order_items', 'orders', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['order_attempts' => [], 'order_items' => [], 'orders' => [], 'products' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $uid = 'SPD' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function insertProduct(int $price = 100000): array
    {
        $name = 'SPD상품' . substr(uniqid(), -6);
        $db   = db_connect();
        $db->table('products')->insert([
            'name'          => $name,
            'slug'          => strtolower($name),
            'price'         => $price,
            'stock'         => 100,
            'status'        => 'on_sale',
            'shipping_type' => 'free',
            'shipping_fee'  => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return ['id' => $id, 'name' => $name, 'price' => $price];
    }

    /**
     * @param  array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function cartItem(array $product, ?int $priceDiff = null, int $qty = 1): array
    {
        $item = [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => $qty,
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
        ];
        if ($priceDiff !== null) {
            $item['price_diff'] = $priceDiff;
            $item['sku_label']  = '옵션/대형';
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트 수령인',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function trackOrder(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
            $ids = array_column(
                db_connect()->table('order_items')->select('id')->where('order_id', $orderId)->get()->getResultArray(),
                'id',
            );
            $this->cleanup['order_items'] = array_merge($this->cleanup['order_items'], $ids);
        }

        return $orderId;
    }

    /**
     * 결제 확정된 주문을 만든다.
     *
     * 주문 생성은 order_attempts 를 거치도록 바뀌었다(이슈 #214). 이 파일은
     * 가격 계산(옵션 추가금)이 orders/order_items 에 그대로 반영되는지 보므로,
     * 시도를 만든 뒤 즉시 결제 확정까지 진행해 검증 대상 행을 만든다.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function createPaidOrder(int $userId, array $items): int
    {
        $attemptId = (new OrderAttemptModel())->createAttempt(
            $userId,
            $this->shippingData(),
            $items,
            null,
            null,
            0,
            0,
            0,
            'toss'
        );
        if ($attemptId > 0) {
            $this->cleanup['order_attempts'][] = $attemptId;
        }

        if ($attemptId === 0) {
            return 0;
        }

        return $this->trackOrder(
            $this->model->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', [])
        );
    }

    /** @return array<string, mixed> */
    private function order(int $orderId): array
    {
        return db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray();
    }

    private function lineItemSum(int $orderId): int
    {
        $rows = db_connect()->table('order_items')->select('subtotal')
            ->where('order_id', $orderId)->get()->getResultArray();

        return (int) array_sum(array_column($rows, 'subtotal'));
    }

    // ── 청구 금액에 추가금이 반영되어야 한다 ────────────────────────────────────

    public function testPositiveSkuSurchargeIsCharged(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(100000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($product, 50000)]);

        $order = $this->order($orderId);
        $this->assertSame(150000, (int) $order['total_product_price'], '옵션 추가금이 주문 총액에서 빠졌다');
        $this->assertSame(150000, (int) $order['total_amount']);
        $this->assertSame(150000, (int) $order['payable_amount'], '옵션 추가금이 청구 금액에서 빠졌다');
    }

    public function testNegativeSkuDiffIsCharged(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(100000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($product, -30000)]);

        $order = $this->order($orderId);
        $this->assertSame(70000, (int) $order['payable_amount'], '음수 추가금이 청구 금액에 반영되지 않았다');
    }

    public function testSurchargeIsMultipliedByQuantity(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(10000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($product, 5000, 3)]);

        // (10000 + 5000) * 3
        $this->assertSame(45000, (int) $this->order($orderId)['payable_amount']);
    }

    // ── 총액과 라인 아이템 합계가 어긋나면 안 된다 ──────────────────────────────

    public function testOrderTotalMatchesSumOfLineItems(): void
    {
        $userId = $this->insertUser();
        $a      = $this->insertProduct(100000);
        $b      = $this->insertProduct(20000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($a, 50000), $this->cartItem($b, 0, 2), $this->cartItem($b)]);

        $order = $this->order($orderId);
        $this->assertSame(
            $this->lineItemSum($orderId),
            (int) $order['total_product_price'],
            'orders.total_product_price 와 SUM(order_items.subtotal) 이 어긋난다',
        );
    }

    public function testLineItemPriceIncludesSurcharge(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(100000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($product, 50000)]);

        $row = db_connect()->table('order_items')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame(150000, (int) $row['product_price']);
        $this->assertSame(150000, (int) $row['subtotal']);
    }

    // ── 회귀 방지 ─────────────────────────────────────────────────────────────

    public function testItemWithoutSkuIsUnaffected(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(100000);

        $orderId = $this->createPaidOrder($userId, [$this->cartItem($product)]);

        $order = $this->order($orderId);
        $this->assertSame(100000, (int) $order['total_product_price']);
        $this->assertSame($this->lineItemSum($orderId), (int) $order['total_product_price']);
    }

    public function testDiscountPriceTakesPrecedenceOverPriceAndStillAddsSurcharge(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct(100000);

        $item                   = $this->cartItem($product, 5000);
        $item['discount_price'] = 80000;

        $orderId = $this->createPaidOrder($userId, [$item]);

        // 80000 (할인가) + 5000 (옵션)
        $this->assertSame(85000, (int) $this->order($orderId)['payable_amount']);
    }
}
