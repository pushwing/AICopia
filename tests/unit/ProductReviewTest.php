<?php

namespace Tests\Unit;

use App\Models\ProductReviewModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 리뷰 작성 자격 검사 / 포인트 지급·회수 / 삭제 흐름 테스트
 */
final class ProductReviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private ProductReviewModel $model;

    private array $cleanup = [
        'users'    => [],
        'products' => [],
        'orders'   => [],
        'reviews'  => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ProductReviewModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['reviews'] !== []) {
            $db->table('product_review_images')->whereIn('review_id', $this->cleanup['reviews'])->delete();
            $db->table('product_reviews')->whereIn('id', $this->cleanup['reviews'])->delete();
        }
        if ($this->cleanup['orders'] !== []) {
            $db->table('order_items')->whereIn('order_id', $this->cleanup['orders'])->delete();
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('point_logs')->whereIn('user_id', $this->cleanup['users'])->delete();
        }

        foreach (['products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(int $pointBalance = 0): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'email'         => 'rv_' . uniqid() . '@test.local',
            'username'      => 'rv_' . uniqid(),
            'password'      => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'      => '리뷰테스터',
            'role'          => 'member',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => '리뷰테스트상품',
            'slug'           => 'rv-prod-' . uniqid(),
            'price'          => 10000,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'fixed',
            'shipping_fee'   => 3000,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertOrder(int $userId, string $status = 'delivered'): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'                => $userId,
            'order_number'           => 'RV-' . uniqid(),
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
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;
        return $id;
    }

    private function insertOrderItem(int $orderId, int $productId, int $qty = 1): void
    {
        db_connect()->table('order_items')->insert([
            'order_id'      => $orderId,
            'product_id'    => $productId,
            'product_name'  => '리뷰테스트상품',
            'product_price' => 10000,
            'qty'           => $qty,
            'subtotal'      => 10000 * $qty,
        ]);
    }

    private function insertReview(int $productId, int $orderId, int $userId, string $content = '테스트 리뷰입니다.', int $isRewarded = 0): int
    {
        $db = db_connect();
        $db->table('product_reviews')->insert([
            'product_id'  => $productId,
            'order_id'    => $orderId,
            'user_id'     => $userId,
            'content'     => $content,
            'is_rewarded' => $isRewarded,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['reviews'][] = $id;
        return $id;
    }

    // ── canWriteReview ────────────────────────────────────────────────────────

    public function testCanWriteReview_noOrder_returnsNull(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $this->assertNull($this->model->canWriteReview($userId, $productId));
    }

    public function testCanWriteReview_orderNotDelivered_returnsNull(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'paid');
        $this->insertOrderItem($orderId, $productId);

        $this->assertNull($this->model->canWriteReview($userId, $productId));
    }

    public function testCanWriteReview_alreadyReviewed_returnsNull(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $this->insertOrderItem($orderId, $productId);
        $this->insertReview($productId, $orderId, $userId);

        $this->assertNull($this->model->canWriteReview($userId, $productId));
    }

    public function testCanWriteReview_eligible_returnsOrderId(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $this->insertOrderItem($orderId, $productId);

        $this->assertSame($orderId, $this->model->canWriteReview($userId, $productId));
    }

    // ── grantPoints ───────────────────────────────────────────────────────────

    public function testGrantPoints_updatesBalanceAndLogs(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser(500);
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId);

        $this->model->grantPoints($reviewId, $userId);

        $balance = (int) $db->table('users')->select('point_balance')->where('id', $userId)->get()->getRow()->point_balance;
        $this->assertSame(650, $balance);

        $review = $db->table('product_reviews')->where('id', $reviewId)->get()->getRow();
        $this->assertSame(1, (int) $review->is_rewarded);

        $log = $db->table('point_logs')->where('user_id', $userId)->where('type', 'earn')->get()->getRow();
        $this->assertNotNull($log);
        $this->assertSame(150, (int) $log->amount);
    }

    // ── deleteReview ──────────────────────────────────────────────────────────

    public function testDeleteReview_wrongUser_returnsFalse(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $otherId   = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId);

        $result = $this->model->deleteReview($reviewId, $otherId);

        $this->assertFalse($result);
        $this->assertSame(1, $db->table('product_reviews')->where('id', $reviewId)->countAllResults());
    }

    public function testDeleteReview_noPoints_deletesReviewOnly(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser(500);
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId, '포인트 없는 리뷰', 0);

        $result = $this->model->deleteReview($reviewId, $userId);

        $this->assertTrue($result);
        $this->assertSame(0, $db->table('product_reviews')->where('id', $reviewId)->countAllResults());

        $balance = (int) $db->table('users')->select('point_balance')->where('id', $userId)->get()->getRow()->point_balance;
        $this->assertSame(500, $balance);

        $cancelLog = $db->table('point_logs')->where('user_id', $userId)->where('type', 'cancel')->countAllResults();
        $this->assertSame(0, $cancelLog);
    }

    public function testDeleteReview_withPoints_revokesPoints(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser(650);
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId, '포인트 지급된 리뷰입니다.', 1);

        $result = $this->model->deleteReview($reviewId, $userId);

        $this->assertTrue($result);
        $this->assertSame(0, $db->table('product_reviews')->where('id', $reviewId)->countAllResults());

        $balance = (int) $db->table('users')->select('point_balance')->where('id', $userId)->get()->getRow()->point_balance;
        $this->assertSame(500, $balance);

        $log = $db->table('point_logs')->where('user_id', $userId)->where('type', 'cancel')->get()->getRow();
        $this->assertNotNull($log);
        $this->assertSame(-150, (int) $log->amount);
    }

    public function testDeleteReview_adminDelete_ignoresUserId(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId);

        $result = $this->model->deleteReview($reviewId, null);

        $this->assertTrue($result);
        $this->assertSame(0, $db->table('product_reviews')->where('id', $reviewId)->countAllResults());
    }

    public function testDeleteReview_withImages_deletesImageRecords(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();
        $orderId   = $this->insertOrder($userId, 'delivered');
        $reviewId  = $this->insertReview($productId, $orderId, $userId);

        $db->table('product_review_images')->insert([
            'review_id'  => $reviewId,
            'image_path' => '/uploads/reviews/fake_test_' . uniqid() . '.jpg',
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('product_review_images')->insert([
            'review_id'  => $reviewId,
            'image_path' => '/uploads/reviews/fake_test_' . uniqid() . '.jpg',
            'sort_order' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->model->deleteReview($reviewId, $userId);

        $this->assertTrue($result);
        $this->assertSame(0, $db->table('product_reviews')->where('id', $reviewId)->countAllResults());
        $this->assertSame(0, $db->table('product_review_images')->where('review_id', $reviewId)->countAllResults());
    }
}
