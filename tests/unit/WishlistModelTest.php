<?php

namespace Tests\Unit;

use App\Models\WishlistModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * WishlistModel — toggle / isWished / getByUser
 * 이슈 #54
 */
final class WishlistModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private WishlistModel $model;

    private array $cleanup = [
        'wishlists' => [],
        'products'  => [],
        'users'     => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new WishlistModel();
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

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'wl_' . $uid,
            'email'         => 'wl-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'WLTestUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id                     = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => 'WLProduct_' . uniqid(),
            'slug'           => 'wl-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 5,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id                       = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    // ── toggle ────────────────────────────────────────────────────────────────

    public function testToggleAddsWishlistEntry(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $wished = $this->model->toggle($userId, $productId);

        $this->assertTrue($wished, '첫 번째 toggle은 true(추가)를 반환해야 한다');

        $row = db_connect()->table('wishlists')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->get()->getRowArray();

        $this->assertNotNull($row, 'DB에 찜 행이 존재해야 한다');
        $this->cleanup['wishlists'][] = (int) $row['id'];
    }

    public function testToggleRemovesExistingEntry(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        // 첫 번째: 추가
        $this->model->toggle($userId, $productId);

        $row = db_connect()->table('wishlists')
            ->where('user_id', $userId)->where('product_id', $productId)
            ->get()->getRowArray();
        if ($row) $this->cleanup['wishlists'][] = (int) $row['id'];

        // 두 번째: 제거
        $wished = $this->model->toggle($userId, $productId);

        $this->assertFalse($wished, '두 번째 toggle은 false(제거)를 반환해야 한다');

        $count = db_connect()->table('wishlists')
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->countAllResults();

        $this->assertSame(0, $count, 'DB에서 찜 행이 제거되어야 한다');
    }

    public function testToggleIsolatedPerUser(): void
    {
        $userId1   = $this->insertUser();
        $userId2   = $this->insertUser();
        $productId = $this->insertProduct();

        $this->model->toggle($userId1, $productId);
        $this->model->toggle($userId2, $productId);

        foreach (['user_id' => $userId1, 'user_id' => $userId2] as $col => $uid) {
            $row = db_connect()->table('wishlists')
                ->where($col, $uid)->where('product_id', $productId)
                ->get()->getRowArray();
            if ($row) $this->cleanup['wishlists'][] = (int) $row['id'];
        }

        // userId2 제거해도 userId1 영향 없음
        $this->model->toggle($userId2, $productId);

        $this->assertTrue(
            $this->model->isWished($userId1, $productId),
            '다른 유저의 찜 제거가 현재 유저에 영향을 주면 안 된다'
        );
    }

    // ── isWished ──────────────────────────────────────────────────────────────

    public function testIsWishedReturnsFalseWhenNotWished(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $this->assertFalse($this->model->isWished($userId, $productId));
    }

    public function testIsWishedReturnsTrueAfterToggle(): void
    {
        $userId    = $this->insertUser();
        $productId = $this->insertProduct();

        $this->model->toggle($userId, $productId);

        $row = db_connect()->table('wishlists')
            ->where('user_id', $userId)->where('product_id', $productId)
            ->get()->getRowArray();
        if ($row) $this->cleanup['wishlists'][] = (int) $row['id'];

        $this->assertTrue($this->model->isWished($userId, $productId));
    }

    // ── getByUser ─────────────────────────────────────────────────────────────

    public function testGetByUserReturnsWishedProducts(): void
    {
        $userId     = $this->insertUser();
        $productId1 = $this->insertProduct();
        $productId2 = $this->insertProduct();

        $this->model->toggle($userId, $productId1);
        $this->model->toggle($userId, $productId2);

        foreach ([$productId1, $productId2] as $pid) {
            $row = db_connect()->table('wishlists')
                ->where('user_id', $userId)->where('product_id', $pid)
                ->get()->getRowArray();
            if ($row) $this->cleanup['wishlists'][] = (int) $row['id'];
        }

        $result = $this->model->getByUser($userId);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
    }

    public function testGetByUserReturnsEmptyForNoWishes(): void
    {
        $userId = $this->insertUser();
        $result = $this->model->getByUser($userId);

        $this->assertSame(0, $result['total']);
        $this->assertCount(0, $result['items']);
    }

    public function testGetByUserDoesNotReturnOtherUsersWishes(): void
    {
        $userId1   = $this->insertUser();
        $userId2   = $this->insertUser();
        $productId = $this->insertProduct();

        $this->model->toggle($userId1, $productId);

        $row = db_connect()->table('wishlists')
            ->where('user_id', $userId1)->where('product_id', $productId)
            ->get()->getRowArray();
        if ($row) $this->cleanup['wishlists'][] = (int) $row['id'];

        $result = $this->model->getByUser($userId2);

        $this->assertSame(0, $result['total'], '다른 유저의 찜 목록이 노출되면 안 된다');
    }
}
