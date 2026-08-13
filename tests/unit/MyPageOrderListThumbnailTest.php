<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\MyPageController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * MyPageController::orders() 주문 목록 대표 썸네일 회귀 테스트
 *
 * 목록 카드에 각 주문 첫 상품의 대표 이미지를 붙인다(없으면 플레이스홀더).
 * 썸네일은 상품명 요약과 별개의 배치 쿼리로 모아 N+1 을 피하고,
 * is_primary 중복이 상품 요약 건수를 부풀리지 않게 분리돼 있다.
 */
final class MyPageOrderListThumbnailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'product_images' => [],
        'media'          => [],
        'order_items'    => [],
        'orders'         => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'MPOLT' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['product_images', 'media', 'order_items', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = [
            'product_images' => [],
            'media'          => [],
            'order_items'    => [],
            'orders'         => [],
            'users'          => [],
        ];

        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

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
        $id                       = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $orderNumber): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => 'paid',
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

    private function insertOrderItem(int $orderId, int $productId): void
    {
        $db = db_connect();
        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => '썸네일테스트상품',
            'product_price' => 10000,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
    }

    /**
     * 대표 이미지를 붙인 합성 상품 id 를 만든다.
     * 실제 products 행 없이 order_items ↔ product_images ↔ media 조인만으로 썸네일을 검증한다.
     */
    private function attachPrimaryImage(string $filePath): int
    {
        $db = db_connect();
        $db->table('media')->insert([
            'original_name' => 'thumb.png',
            'stored_name'   => $this->prefix . '.png',
            'file_path'     => $filePath,
            'file_size'     => 1234,
            'mime_type'     => 'image/png',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $mediaId                   = (int) $db->insertID();
        $this->cleanup['media'][]  = $mediaId;

        // 실제 상품 id 와 겹치지 않도록 충분히 큰 합성 id 를 쓴다.
        $productId = 900_000_000 + $mediaId;
        $db->table('product_images')->insert([
            'product_id' => $productId,
            'media_id'   => $mediaId,
            'is_primary' => 1,
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['product_images'][] = (int) $db->insertID();

        return $productId;
    }

    private function controller(): MyPageController
    {
        $controller = new MyPageController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── 테스트 ───────────────────────────────────────────────────────────────

    /** 대표 이미지가 있는 주문은 목록 카드에 그 썸네일이 렌더된다 */
    public function testOrderListRendersProductThumbnail(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $filePath    = 'uploads/media/' . $this->prefix . '-thumb.png';
        $productId   = $this->attachPrimaryImage($filePath);
        $this->insertOrderItem($orderId, $productId);

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->orders();

        $this->assertIsString($result, '주문 목록이 HTML 을 반환하지 않았다');
        $this->assertStringContainsString($orderNumber, $result, '주문번호가 목록에 없다');
        $this->assertStringContainsString($filePath, $result, '대표 썸네일 경로가 렌더되지 않았다');
        // class="... order-thumb-img" 의 닫는 따옴표까지 확인 — 뷰 스크립트의 셀렉터 '.order-thumb-img' 오탐 방지
        $this->assertStringContainsString('order-thumb-img"', $result, '썸네일 img(폴백 대상 클래스)가 없다');
    }

    /** 대표 이미지가 없는 주문은 썸네일 img 없이 플레이스홀더만 렌더된다 */
    public function testOrderListRendersPlaceholderWhenNoImage(): void
    {
        $userId      = $this->insertUser();
        $orderNumber = 'ORD-' . $this->prefix;
        $orderId     = $this->insertOrder($userId, $orderNumber);
        $this->insertOrderItem($orderId, 900_000_000); // 대표 이미지 없는 합성 상품

        session()->set(['user_id' => $userId, 'user_role' => 'member']);

        $result = $this->controller()->orders();

        $this->assertIsString($result);
        $this->assertStringContainsString($orderNumber, $result, '주문번호가 목록에 없다');
        // 스크립트의 '.order-thumb-img' 셀렉터는 늘 있으므로, img class 의 닫는 따옴표 형태로 실제 img 만 검사
        $this->assertStringNotContainsString('order-thumb-img"', $result, '이미지가 없는데 썸네일 img 가 렌더됐다');
    }
}
