<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ReviewController::json() 데이터 레이어 검증
 *
 * - 반환 필드 구조
 * - image_count = 0 (이미지 없음)
 * - image_count = 이미지 수
 * - author = nickname 우선, username fallback
 * - is_rewarded int 캐스팅
 * - id DESC 정렬
 */
final class ReviewJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = [
        'review_images' => [],
        'reviews'       => [],
        'products'      => [],
        'users'         => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'RJT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['review_images'] !== []) {
            $db->table('product_review_images')->whereIn('review_id', $this->cleanup['reviews'])->delete();
        }
        if ($this->cleanup['reviews'] !== []) {
            $db->table('product_reviews')->whereIn('id', $this->cleanup['reviews'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['users'] !== []) {
            $db->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'username'   => 'rjt_' . $uid,
            'email'      => $this->prefix . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix . $uid,
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('users')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    private function insertProduct(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'name'       => $this->prefix . $uid,
            'slug'       => $this->prefix . $uid,
            'price'      => 10000,
            'stock'      => 10,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('products')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertReview(int $productId, int $userId, array $overrides = []): int
    {
        $data = array_merge([
            'product_id'  => $productId,
            'user_id'     => $userId,
            'content'     => '테스트 리뷰 내용',
            'order_id'    => 0,
            'is_rewarded' => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $overrides);
        $db  = db_connect();
        $db->table('product_reviews')->insert($data);
        $id  = (int) $db->insertID();
        $this->cleanup['reviews'][] = $id;
        return $id;
    }

    private function insertReviewImage(int $reviewId, string $path = '/uploads/reviews/test.jpg'): void
    {
        db_connect()->table('product_review_images')->insert([
            'review_id'  => $reviewId,
            'image_path' => $path,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup['review_images'][] = $reviewId;
    }

    /** ReviewController::json() 와 동일한 쿼리 + 변환 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $db      = db_connect();
        $builder = $db->table('product_reviews r')
            ->select('r.id, r.content, r.is_rewarded, r.created_at,
                      p.name AS product_name, p.slug AS product_slug,
                      u.nickname, u.username')
            ->join('products p', 'p.id = r.product_id')
            ->join('users u', 'u.id = r.user_id')
            ->orderBy('r.id', 'DESC');

        if ($whereIn !== []) {
            $builder->whereIn('r.id', $whereIn);
        }

        $rows = $builder->get()->getResultArray();

        $byReview = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach ($db->table('product_review_images')->whereIn('review_id', $ids)->get()->getResultArray() as $img) {
                $byReview[(int) $img['review_id']][] = $img['image_path'];
            }
        }

        return array_map(fn($r) => [
            'id'           => (int) $r['id'],
            'product_name' => $r['product_name'],
            'product_slug' => $r['product_slug'],
            'author'       => $r['nickname'] ?: $r['username'],
            'content'      => $r['content'],
            'image_count'  => count($byReview[(int) $r['id']] ?? []),
            'is_rewarded'  => (int) $r['is_rewarded'],
            'created_at'   => $r['created_at'],
        ], $rows);
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id]);

        $expected = ['id', 'product_name', 'product_slug', 'author',
                     'content', 'image_count', 'is_rewarded', 'created_at'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── image_count ────────────────────────────────────────────────────────────

    public function testImageCountIsZeroWhenNoImages(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame(0, $rows[0]['image_count'],
            '이미지 없는 리뷰의 image_count 는 0 이어야 한다');
    }

    public function testImageCountReflectsActualImages(): void
    {
        $uid = $this->insertUser();
        $pid = $this->insertProduct();
        $id  = $this->insertReview($pid, $uid);
        $this->insertReviewImage($id, '/uploads/reviews/a.jpg');
        $this->insertReviewImage($id, '/uploads/reviews/b.jpg');

        $rows = $this->fetchJsonData([$id]);
        $this->assertSame(2, $rows[0]['image_count'],
            '이미지 2장인 리뷰의 image_count 는 2 여야 한다');
    }

    public function testImageCountIsolatedPerReview(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id1  = $this->insertReview($pid, $uid);
        $id2  = $this->insertReview($pid, $uid);
        $this->insertReviewImage($id1, '/uploads/reviews/x.jpg');

        $rows   = $this->fetchJsonData([$id1, $id2]);
        $byId   = array_column($rows, null, 'id');

        $this->assertSame(1, $byId[$id1]['image_count'], 'id1 이미지 1장');
        $this->assertSame(0, $byId[$id2]['image_count'], 'id2 이미지 없음');
    }

    // ── author 필드 ───────────────────────────────────────────────────────────

    public function testAuthorUsesNickname(): void
    {
        $uid  = $this->insertUser(['nickname' => $this->prefix . 'alice']);
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame($this->prefix . 'alice', $rows[0]['author'],
            'nickname 이 있으면 author 로 사용해야 한다');
    }

    public function testAuthorFallsBackToUsernameWhenNicknameEmpty(): void
    {
        $uid  = $this->insertUser(['nickname' => '', 'username' => 'rjt_fallback_' . uniqid()]);
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertStringStartsWith('rjt_fallback_', $rows[0]['author'],
            'nickname 이 비어 있으면 username 을 author 로 사용해야 한다');
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIdIsInteger(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['id']);
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testIsRewardedIsInteger(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id   = $this->insertReview($pid, $uid, ['is_rewarded' => 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_rewarded']);
        $this->assertSame(1, $rows[0]['is_rewarded']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $uid  = $this->insertUser();
        $pid  = $this->insertProduct();
        $id1  = $this->insertReview($pid, $uid);
        $id2  = $this->insertReview($pid, $uid);
        $id3  = $this->insertReview($pid, $uid);
        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }
}
