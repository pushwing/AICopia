<?php

namespace Tests\Unit;

use App\Libraries\RestockSuggestionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 판매 추세 기반 발주 제안 검증 (#9)
 *
 * windowDays=30, coverDays=30 기준:
 *   일평균 = 판매수량/30, 목표재고 = ceil(일평균*30), 권장발주 = max(0, 목표-현재고)
 */
final class RestockSuggestionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private RestockSuggestionService $svc;

    private array $cleanup = ['products' => [], 'orders' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RestockSuggestionService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['orders'] !== []) {
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
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
            'username' => 'rs_' . $uid, 'email' => 'rs_' . $uid . '@test.com',
            'password' => password_hash('x', PASSWORD_DEFAULT), 'nickname' => 'RS_' . $uid,
            'role' => 'member', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(int $stock): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name' => 'RS_Product_' . $uid, 'slug' => 'rs-product-' . $uid,
            'price' => 10000, 'cost_price' => 5000, 'stock' => $stock, 'status' => 'on_sale',
            'is_featured' => 0, 'shipping_type' => 'free', 'shipping_fee' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertSale(int $userId, int $productId, int $qty, int $daysAgo = 0): void
    {
        $db   = db_connect();
        $when = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $db->table('orders')->insert([
            'user_id' => $userId, 'order_number' => 'RS' . uniqid(), 'status' => 'paid',
            'total_product_price' => 10000 * $qty, 'total_amount' => 10000 * $qty,
            'receiver_name' => 'T', 'receiver_phone' => '010-0000-0000', 'zipcode' => '12345', 'address1' => '서울',
            'created_at' => $when, 'updated_at' => $when,
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId,
            'product_name' => 'RS', 'product_price' => 10000, 'cost_price' => 5000,
            'qty' => $qty, 'subtotal' => 10000 * $qty, 'created_at' => $when,
        ]);
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

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testSuggestsReorderQtyFromVelocity(): void
    {
        $user = $this->insertUser();
        $pid  = $this->insertProduct(5);       // 현재고 5
        $this->insertSale($user, $pid, 30, 1); // 최근 30개 판매 → 일평균 1, 목표 30

        $row = $this->findById($this->svc->suggestions(30, 30), $pid);

        $this->assertNotNull($row, '판매 이력이 있고 재고 부족이면 제안돼야 한다');
        $this->assertSame(25, $row['suggested_qty'], '목표 30 - 현재고 5 = 25');
        $this->assertSame(5, $row['stock']);
        $this->assertSame(30, $row['sold']);
    }

    public function testExcludesWellStockedProduct(): void
    {
        $user = $this->insertUser();
        $pid  = $this->insertProduct(100);     // 충분한 재고
        $this->insertSale($user, $pid, 30, 1); // 목표 30 < 재고 100 → 발주 불필요

        $this->assertNull($this->findById($this->svc->suggestions(30, 30), $pid));
    }

    public function testExcludesProductWithoutRecentSales(): void
    {
        $pid = $this->insertProduct(0);        // 재고 0이지만 판매 이력 없음
        $this->assertNull($this->findById($this->svc->suggestions(30, 30), $pid));
    }

    public function testIgnoresSalesOutsideWindow(): void
    {
        $user = $this->insertUser();
        $pid  = $this->insertProduct(2);
        $this->insertSale($user, $pid, 50, 60); // 60일 전 판매 → 30일 창 밖

        $this->assertNull($this->findById($this->svc->suggestions(30, 30), $pid));
    }

    public function testComputesDaysRemaining(): void
    {
        $user = $this->insertUser();
        $pid  = $this->insertProduct(10);
        $this->insertSale($user, $pid, 60, 1); // 일평균 2 → 소진 예상 5일

        $row = $this->findById($this->svc->suggestions(30, 30), $pid);
        $this->assertNotNull($row);
        $this->assertSame(5.0, $row['days_remaining']);
    }
}
