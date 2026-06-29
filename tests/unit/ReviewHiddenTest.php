<?php

namespace Tests\Unit;

use App\Models\ProductReviewModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 리뷰 숨김/노출 기능 검증
 *
 * - toggleHidden() — 0→1, 1→0 전환
 * - getByProduct() — is_hidden=1 리뷰 프론트에서 제외
 * - 존재하지 않는 리뷰 ID 처리
 */
final class ReviewHiddenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = [
        'product_reviews'       => [],
        'product_review_images' => [],
        'products'              => [],
        'users'                 => [],
        'orders'                => [],
        'order_items'           => [],
    ];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach ($this->cleanup as $table => $ids) {
            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'   => 'rev_' . $uid,
            'email'      => 'rev_' . $uid . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'RevUser',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'           => 'Test Product ' . $uid,
            'slug'           => 'test-product-' . $uid,
            'price'          => 10000,
            'stock'          => 100,
            'status'         => 'on_sale',
            'shipping_fee'   => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, int $productId): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                 => $userId,
            'order_number'            => 'REV-' . uniqid(),
            'status'                  => 'delivered',
            'total_product_price'     => 10000,
            'total_amount'            => 10000,
            'payable_amount'          => 10000,
            'shipping_fee'            => 0,
            'coupon_discount_amount'  => 0,
            'point_used_amount'       => 0,
            'point_earned_amount'     => 0,
            'receiver_name'           => '테스트',
            'receiver_phone'          => '010-0000-0000',
            'zipcode'                 => '12345',
            'address1'                => '서울시',
            'created_at'              => date('Y-m-d H:i:s'),
            'updated_at'              => date('Y-m-d H:i:s'),
        ]);
        $orderId = (int) $db->insertID();
        $this->cleanup['orders'][] = $orderId;

        $db->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => 'Test',
            'product_price' => 10000,
            'cost_price'    => 5000,
            'qty'           => 1,
            'subtotal'      => 10000,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['order_items'][] = (int) $db->insertID();
        return $orderId;
    }

    private function insertReview(int $userId, int $productId, int $orderId, int $isHidden = 0): int
    {
        $db = db_connect();
        $db->table('product_reviews')->insert([
            'product_id'  => $productId,
            'order_id'    => $orderId,
            'user_id'     => $userId,
            'content'     => '테스트 리뷰입니다.',
            'is_rewarded' => 0,
            'is_hidden'   => $isHidden,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['product_reviews'][] = $id;
        return $id;
    }

    // ── toggleHidden() ────────────────────────────────────────────────────────

    public function testToggleHiddenFrom0To1(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, $productId);
        $reviewId  = $this->insertReview($userId, $productId, $orderId, 0);

        $model  = new ProductReviewModel();
        $result = $model->toggleHidden($reviewId);

        $this->assertSame(1, $result, '0 → 1로 전환돼야 함');
        $review = $model->find($reviewId);
        $this->assertSame('1', (string) $review['is_hidden']);
    }

    public function testToggleHiddenFrom1To0(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, $productId);
        $reviewId  = $this->insertReview($userId, $productId, $orderId, 1);

        $model  = new ProductReviewModel();
        $result = $model->toggleHidden($reviewId);

        $this->assertSame(0, $result, '1 → 0으로 전환돼야 함');
        $review = $model->find($reviewId);
        $this->assertSame('0', (string) $review['is_hidden']);
    }

    public function testToggleHiddenReturnsMinusOneForMissingId(): void
    {
        $model  = new ProductReviewModel();
        $result = $model->toggleHidden(999999999);
        $this->assertSame(-1, $result, '존재하지 않는 ID는 -1을 반환해야 함');
    }

    // ── getByProduct() 프론트 필터 ────────────────────────────────────────────

    public function testGetByProductExcludesHiddenReviews(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId1  = $this->insertOrder($userId, $productId);
        $orderId2  = $this->insertOrder($userId, $productId);

        $visibleId = $this->insertReview($userId, $productId, $orderId1, 0);
        $hiddenId  = $this->insertReview($userId, $productId, $orderId2, 1);

        $model  = new ProductReviewModel();
        $result = $model->getByProduct($productId);

        $ids = array_column($result['items'], 'id');

        $this->assertContains((string) $visibleId, $ids, '노출 리뷰가 포함돼야 함');
        $this->assertNotContains((string) $hiddenId, $ids, '숨김 리뷰가 제외돼야 함');
    }

    public function testGetByProductTotalExcludesHiddenReviews(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId1  = $this->insertOrder($userId, $productId);
        $orderId2  = $this->insertOrder($userId, $productId);

        $this->insertReview($userId, $productId, $orderId1, 0);
        $this->insertReview($userId, $productId, $orderId2, 1);

        $model  = new ProductReviewModel();
        $result = $model->getByProduct($productId);

        $this->assertSame(1, $result['total'], '숨김 리뷰는 total 카운트에서도 제외돼야 함');
    }

    // ── adminGetAll() — 관리자는 숨김 리뷰도 조회 ─────────────────────────────

    public function testAdminGetAllIncludesHiddenReviews(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId1  = $this->insertOrder($userId, $productId);
        $orderId2  = $this->insertOrder($userId, $productId);

        $visibleId = $this->insertReview($userId, $productId, $orderId1, 0);
        $hiddenId  = $this->insertReview($userId, $productId, $orderId2, 1);

        $model  = new ProductReviewModel();
        $result = $model->adminGetAll();
        $ids    = array_column($result['items'], 'id');

        $this->assertContains((string) $visibleId, $ids, '관리자 목록에 노출 리뷰가 있어야 함');
        $this->assertContains((string) $hiddenId,  $ids, '관리자 목록에 숨김 리뷰도 있어야 함');
    }
}
