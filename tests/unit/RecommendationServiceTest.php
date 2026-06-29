<?php

namespace Tests\Unit;

use App\Libraries\RecommendationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 개인화 상품 추천 검증 (#6 / 휴리스틱 카테고리 선호)
 *
 * 새 카테고리로 격리해 공유 DB 데이터와 충돌 없이 검증.
 */
final class RecommendationServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private RecommendationService $svc;

    private array $cleanup = ['products' => [], 'categories' => [], 'users' => [], 'product_categories_pids' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RecommendationService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['product_categories_pids'] !== []) {
            $db->table('product_categories')->whereIn('product_id', $this->cleanup['product_categories_pids'])->delete();
            $db->table('wishlists')->whereIn('product_id', $this->cleanup['product_categories_pids'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['categories'] !== []) {
            $db->table('categories')->whereIn('id', $this->cleanup['categories'])->delete();
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
            'username'   => 'reco_' . $uid,
            'email'      => 'reco_' . $uid . '@test.com',
            'password'   => password_hash('test1234!', PASSWORD_DEFAULT),
            'nickname'   => 'Reco_' . $uid,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertCategory(): int
    {
        $db = db_connect();
        $db->table('categories')->insert([
            'name'       => 'RecoCat_' . uniqid(),
            'slug'       => 'reco-cat-' . uniqid(),
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['categories'][] = $id;
        return $id;
    }

    private function insertProduct(?int $categoryId = null, bool $featured = false): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('products')->insert([
            'name'          => 'RecoProduct_' . $uid,
            'slug'          => 'reco-product-' . $uid,
            'price'         => 10000,
            'cost_price'    => 5000,
            'stock'         => 10,
            'status'        => 'on_sale',
            'is_featured'   => $featured ? 1 : 0,
            'shipping_type' => 'free',
            'shipping_fee'  => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        $this->cleanup['product_categories_pids'][] = $id;

        if ($categoryId !== null) {
            $db->table('product_categories')->insert(['product_id' => $id, 'category_id' => $categoryId]);
        }
        return $id;
    }

    private function wishlist(int $userId, int $productId): void
    {
        db_connect()->table('wishlists')->insert([
            'user_id'    => $userId,
            'product_id' => $productId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── 테스트 ────────────────────────────────────────────────────────────────

    public function testRecommendsFromPreferredCategoryExcludingOwned(): void
    {
        $cat     = $this->insertCategory();
        $wished  = $this->insertProduct($cat, false);
        $reco    = $this->insertProduct($cat, true); // 추천 노출 대상 (featured + 최신)
        $user    = $this->insertUser();
        $this->wishlist($user, $wished);
        RecommendationService::forget($user);

        $ids = array_map('intval', array_column($this->svc->forUser($user, 8), 'id'));

        $this->assertContains($reco, $ids, '선호 카테고리의 미보유 상품이 추천돼야 한다');
        $this->assertNotContains($wished, $ids, '이미 찜한 상품은 제외돼야 한다');
    }

    public function testNewUserGetsFallbackProducts(): void
    {
        $this->insertProduct(null, true); // 폴백 후보 보장
        $user = $this->insertUser();
        RecommendationService::forget($user);

        $result = $this->svc->forUser($user, 8);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, '이력 없는 회원도 추천(폴백) 상품을 받아야 한다');
        $this->assertArrayHasKey('primary_image', $result[0], 'primary_image가 부착돼야 한다');
    }

    public function testReturnsEmptyForGuest(): void
    {
        $this->assertSame([], $this->svc->forUser(0));
    }

    public function testResultRespectsLimit(): void
    {
        $cat = $this->insertCategory();
        for ($i = 0; $i < 6; $i++) {
            $this->insertProduct($cat, false);
        }
        $user = $this->insertUser();
        $this->wishlist($user, $this->insertProduct($cat, false));
        RecommendationService::forget($user);

        $this->assertLessThanOrEqual(4, count($this->svc->forUser($user, 4)));
    }

    public function testForgetDoesNotError(): void
    {
        RecommendationService::forget(999999);
        $this->assertTrue(true);
    }
}
