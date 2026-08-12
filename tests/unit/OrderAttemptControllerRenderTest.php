<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\OrderAttemptController;
use App\Models\OrderAttemptModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 관리자 "주문 시도 로그" 화면 3종(목록/시도 상세/레거시 상세)이 실제로
 * 렌더링되는지 검증한다. (이슈 #214 PR2)
 *
 * php -l 은 뷰가 참조하는 변수를 컨트롤러가 실제로 넘기는지까지는 잡아주지
 * 않는다 — 관리자가 페이지를 처음 열었을 때 undefined array key 로 500 이
 * 나는 종류의 버그는 이렇게 컨트롤러를 실제로 호출해 HTML 을 만들어봐야만
 * 드러난다.
 *
 * @internal
 */
final class OrderAttemptControllerRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'orders'         => [],
        'users'          => [],
        'order_attempts' => [],
        'products'       => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix       = 'OAR' . substr(uniqid(), -6);
        $this->attemptModel = new OrderAttemptModel();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('user_coupons')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(string $role = 'member'): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'       => $this->prefix . substr(uniqid(), -4),
            'email'          => $this->prefix . substr(uniqid(), -4) . '@example.test',
            'password'       => password_hash('pw', PASSWORD_DEFAULT),
            'nickname'       => $this->prefix,
            'role'           => $role,
            'point_balance'  => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OARProd_' . uniqid(),
            'slug'           => 'oar-prod-' . uniqid(),
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
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function createAttempt(int $userId, array $product): int
    {
        $id = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트수령인',
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
            null,
            null,
            0,
            0,
            0,
            'naverpay'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    private function insertLegacyOrder(int $userId, string $status): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'ORD-' . $this->prefix . '-' . substr(uniqid(), -4),
            'status'                 => $status,
            'total_product_price'    => 10000,
            'shipping_fee'           => 3000,
            'total_amount'           => 13000,
            'payable_amount'         => 13000,
            'point_used_amount'      => 0,
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

    /** @param array<string, mixed> $getParams */
    private function requestWithGet(string $path, array $getParams): IncomingRequest
    {
        $appConfig = config('App');
        $request   = new IncomingRequest(
            $appConfig,
            new SiteURI($appConfig, $path),
            null,
            new UserAgent(),
        );
        $request->setGlobal('get', $getParams);

        return $request;
    }

    /** @param array<string, mixed> $getParams */
    private function controller(array $getParams = []): OrderAttemptController
    {
        $controller = new OrderAttemptController();
        $controller->initController(
            $this->requestWithGet('admin/order-attempts', $getParams),
            service('response'),
            service('logger'),
        );

        return $controller;
    }

    // ── 목록 ─────────────────────────────────────────────────────────────────

    public function testIndexRendersAndShowsCreatedAttemptOrderNumber(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);
        $this->assertGreaterThan(0, $attemptId, '시도 생성이 실패해 테스트 전제가 깨졌다');
        $attempt = db_connect()->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->index();

        $this->assertIsString($result, '목록이 HTML 대신 리다이렉트/기타 값을 반환했다');
        $this->assertStringContainsString(
            (string) $attempt['order_number'],
            $result,
            '내가 만든 주문 시도의 주문번호가 목록 HTML 에 없다'
        );
    }

    /** 상태·키워드 필터를 넣어도 500 없이 렌더링돼야 한다. */
    public function testIndexWithStatusAndKeywordFiltersStillRenders(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);
        $this->assertGreaterThan(0, $attemptId);
        $attempt = db_connect()->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller([
            'status' => 'pending',
            'q'      => (string) $attempt['order_number'],
            'from'   => date('Y-m-d', strtotime('-1 day')),
            'to'     => date('Y-m-d', strtotime('+1 day')),
        ])->index();

        $this->assertIsString($result, '필터를 걸었더니 렌더링이 깨졌다');
        $this->assertStringContainsString(
            (string) $attempt['order_number'],
            $result,
            '필터 조건에 맞는 주문번호가 결과 HTML 에 없다'
        );
    }

    // ── 시도 상세 ────────────────────────────────────────────────────────────

    public function testDetailAttemptRendersAndShowsProductName(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $product = $this->insertProduct(['name' => 'OAR상세렌더상품' . substr(uniqid(), -4)]);

        $attemptId = $this->createAttempt($userId, $product);
        $this->assertGreaterThan(0, $attemptId);

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailAttempt($attemptId);

        $this->assertIsString($result, '시도 상세가 HTML 대신 리다이렉트를 반환했다');
        $this->assertStringContainsString(
            $product['name'],
            $result,
            'items_snapshot 의 상품명이 상세 HTML 에 없다 — 스냅샷 렌더링 확인'
        );
    }

    public function testDetailAttemptWithMissingIdRedirectsToIndex(): void
    {
        $adminId = $this->insertUser('admin');
        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailAttempt(999_999_999);

        $this->assertInstanceOf(RedirectResponse::class, $result, '존재하지 않는 시도 id 인데 목록으로 리다이렉트되지 않았다');
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringContainsString('/admin/order-attempts', $location);
    }

    // ── 레거시 상세 ──────────────────────────────────────────────────────────

    public function testDetailLegacyRendersForPendingOrder(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $orderId = $this->insertLegacyOrder($userId, 'pending');

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailLegacy($orderId);

        $this->assertIsString($result, 'pending 레거시 주문 상세가 HTML 대신 리다이렉트를 반환했다');
        $this->assertStringContainsString('레거시 주문 상세', $result);
    }

    public function testDetailLegacyRendersForExpiredOrder(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $orderId = $this->insertLegacyOrder($userId, 'expired');

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailLegacy($orderId);

        $this->assertIsString($result, 'expired 레거시 주문 상세가 HTML 대신 리다이렉트를 반환했다');
        $this->assertStringContainsString('레거시 주문 상세', $result);
    }

    /** paid 처럼 pending/expired 가 아닌 주문은 레거시 상세 대상이 아니다. */
    public function testDetailLegacyWithPaidOrderRedirectsToIndex(): void
    {
        $adminId = $this->insertUser('admin');
        $userId  = $this->insertUser();
        $orderId = $this->insertLegacyOrder($userId, 'paid');

        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailLegacy($orderId);

        $this->assertInstanceOf(RedirectResponse::class, $result, 'paid 주문인데 레거시 상세가 렌더링됐다');
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringContainsString('/admin/order-attempts', $location);
    }

    public function testDetailLegacyWithMissingIdRedirectsToIndex(): void
    {
        $adminId = $this->insertUser('admin');
        session()->set(['user_id' => $adminId, 'user_role' => 'admin']);

        $result = $this->controller()->detailLegacy(999_999_999);

        $this->assertInstanceOf(RedirectResponse::class, $result, '존재하지 않는 주문 id 인데 목록으로 리다이렉트되지 않았다');
        $location = (string) $result->header('Location')->getValue();
        $this->assertStringContainsString('/admin/order-attempts', $location);
    }
}
