<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderAttemptModel::adminGetAll() / adminFind() — 관리자 "주문 시도 로그" 화면 조회.
 * 이슈 #214 PR2
 */
final class OrderAttemptAdminQueryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderAttemptModel $model;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'orders'         => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        foreach (['orders', 'products', 'users'] as $table) {
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
            'username'      => 'oaq_' . $uid,
            'email'         => 'oaq-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OAQUser',
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

    /** @return array<string, mixed> */
    private function insertProduct(): array
    {
        $db   = db_connect();
        $data = [
            'name'           => 'OAQProd_' . uniqid(),
            'slug'           => 'oaq-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
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

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function makeCartItem(array $product): array
    {
        return [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => 1,
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
        ];
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function createAttempt(int $userId, array $product, ?string $pgProvider = 'toss'): int
    {
        $id = $this->model->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product)],
            null,
            null,
            0,
            0,
            0,
            $pgProvider
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    /** 레거시 orders 행(pending/expired)을 직접 심는다. PR1(#214)이 목록에서 감춘 그 행들이다. */
    private function insertLegacyOrder(int $userId, string $status, string $orderNumber): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'        => $userId,
            'order_number'   => $orderNumber,
            'status'         => $status,
            'payable_amount' => 10000,
            'total_amount'   => 10000,
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    /** B-01: 상태 필터가 attempt 쪽을 실제로 거른다 */
    public function testAdminGetAll_statusFilter_filtersAttempts(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);
        $this->model->markFailed($attemptId, '테스트 실패');

        $pendingResult = $this->model->adminGetAll(['status' => 'pending', 'perPage' => 1000]);
        $failedResult  = $this->model->adminGetAll(['status' => 'failed', 'perPage' => 1000]);

        $pendingIds = array_map('intval', array_column($pendingResult['items'], 'id'));
        $failedIds  = array_map('intval', array_column($failedResult['items'], 'id'));

        $this->assertNotContains($attemptId, $pendingIds, 'failed 로 전이된 시도는 pending 필터에 잡히면 안 된다');
        $this->assertContains($attemptId, $failedIds, 'failed 필터에는 잡혀야 한다');
    }

    /** B-02: 키워드가 주문번호로 매칭된다 */
    public function testAdminGetAll_keyword_matchesOrderNumber(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $attempt = db_connect()->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        $result = $this->model->adminGetAll(['keyword' => $attempt['order_number'], 'perPage' => 1000]);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertContains($attemptId, $ids);
        $this->assertSame(1, $result['total'], '고유 주문번호로 검색하면 정확히 1건만 나와야 한다');
    }

    /** B-03: 키워드가 회원 이메일로도 매칭된다 */
    public function testAdminGetAll_keyword_matchesUserEmail(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $email  = db_connect()->table('users')->where('id', $userId)->get()->getRowArray()['email'];
        $result = $this->model->adminGetAll(['keyword' => $email, 'perPage' => 1000]);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertContains($attemptId, $ids);
    }

    /** B-04: 레거시 orders 의 pending/expired 행이 결과에 함께 포함된다 (PR1이 목록에서 감춘 행) */
    public function testAdminGetAll_includesLegacyPendingAndExpiredOrders(): void
    {
        $userId       = $this->insertUser();
        $marker       = 'ORD-LEGACY-' . uniqid();
        $pendingOrder = $this->insertLegacyOrder($userId, 'pending', $marker . '-A');
        $expiredOrder = $this->insertLegacyOrder($userId, 'expired', $marker . '-B');

        $result = $this->model->adminGetAll(['keyword' => $marker, 'perPage' => 1000]);

        $this->assertSame(2, $result['total']);

        $bySource = [];
        foreach ($result['items'] as $row) {
            $bySource[(int) $row['id']] = $row;
        }

        $this->assertSame('legacy', $bySource[$pendingOrder]['source']);
        $this->assertSame('pending', $bySource[$pendingOrder]['status']);
        $this->assertSame('legacy', $bySource[$expiredOrder]['source']);
        $this->assertSame('expired', $bySource[$expiredOrder]['status']);
    }

    /** B-05: 레거시 orders 는 paid 등 확정 상태는 절대 섞이지 않는다(주문 목록과 중복 노출 방지) */
    public function testAdminGetAll_doesNotIncludeConfirmedLegacyOrders(): void
    {
        $userId = $this->insertUser();
        $paid   = $this->insertLegacyOrder($userId, 'pending', 'ORD-LGCPD-' . uniqid());
        db_connect()->table('orders')->where('id', $paid)->update(['status' => 'paid']);

        $result = $this->model->adminGetAll(['keyword' => 'ORD-LGCPD', 'perPage' => 1000]);

        $this->assertSame(0, $result['total'], 'paid 로 확정된 레거시 주문은 이 화면에 노출되면 안 된다');
    }

    /** B-06: 페이지네이션 — perPage=1 이면 매 페이지 1건씩, total/​totalPages 가 정확하다 */
    public function testAdminGetAll_pagination_splitsAcrossPages(): void
    {
        $userId   = $this->insertUser();
        $product  = $this->insertProduct();
        $marker   = 'PGTEST-' . uniqid();
        $attempt1 = $this->createAttempt($userId, $product);
        $attempt2 = $this->createAttempt($userId, $product);
        // 키워드로 이 테스트가 만든 두 건만 골라내기 위해 order_number 에 마커를 심는다.
        $db = db_connect();
        $db->table('order_attempts')->where('id', $attempt1)->update(['order_number' => $marker . '-A']);
        $db->table('order_attempts')->where('id', $attempt2)->update(['order_number' => $marker . '-B']);

        $page1 = $this->model->adminGetAll(['keyword' => $marker, 'perPage' => 1, 'page' => 1]);
        $page2 = $this->model->adminGetAll(['keyword' => $marker, 'perPage' => 1, 'page' => 2]);

        $this->assertSame(2, $page1['total']);
        $this->assertSame(2, $page1['totalPages']);
        $this->assertCount(1, $page1['items']);
        $this->assertCount(1, $page2['items']);
        $this->assertNotSame(
            $page1['items'][0]['id'],
            $page2['items'][0]['id'],
            '서로 다른 페이지는 서로 다른 행을 반환해야 한다'
        );
    }

    /** B-07: adminFind() — items·회원 정보가 함께 붙어 반환된다 */
    public function testAdminFind_returnsAttemptWithItemsAndUserInfo(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $result = $this->model->adminFind($attemptId);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
        $this->assertArrayHasKey('user_email', $result);
        $this->assertNotEmpty($result['user_email']);
    }

    /** B-08: adminFind() — 존재하지 않는 id 는 null */
    public function testAdminFind_missingId_returnsNull(): void
    {
        $this->assertNull($this->model->adminFind(999999999));
    }
}
