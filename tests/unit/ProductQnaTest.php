<?php

namespace Tests\Unit;

use App\Models\ProductQnaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductQnaModel 검증
 *
 * - getByProduct(): 상품별 문의 조회
 * - getUnansweredCount(): 미답변 수
 * - adminGetAll(): 키워드·답변 필터, 페이지네이션
 */
final class ProductQnaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = false;
    protected $refresh     = false;

    private ProductQnaModel $model;
    private int $productId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new ProductQnaModel();
        $db          = db_connect();

        // 테스트용 상품 생성
        $db->table('products')->insert([
            'name'        => 'QNA테스트상품',
            'slug'        => 'qna-test-product-' . uniqid(),
            'price'       => 10000,
            'stock'       => 10,
            'status'      => 'on_sale',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->productId = (int) $db->insertID();

        // 테스트용 회원 생성
        $db->table('users')->insert([
            'username'   => 'qnauser_' . uniqid(),
            'email'      => 'qnauser_' . uniqid() . '@test.com',
            'password'   => password_hash('pass', PASSWORD_DEFAULT),
            'nickname'   => 'QNA회원',
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->userId = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        $db->table('product_qnas')->where('product_id', $this->productId)->delete();
        $db->table('products')->where('id', $this->productId)->delete();
        $db->table('users')->where('id', $this->userId)->delete();
        parent::tearDown();
    }

    private function insertQna(array $override = []): int
    {
        $db = db_connect();
        $db->table('product_qnas')->insert(array_merge([
            'product_id'  => $this->productId,
            'user_id'     => $this->userId,
            'title'       => '기본 문의 제목',
            'content'     => '기본 문의 내용입니다.',
            'is_secret'   => 0,
            'is_answered' => 0,
            'answer'      => null,
            'answered_at' => null,
            'answered_by' => null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $override));
        return (int) $db->insertID();
    }

    // ── getByProduct ──────────────────────────────────────────────────────────

    public function testGetByProductReturnsQnasForProduct(): void
    {
        $this->insertQna(['title' => '사이즈 문의']);
        $result = $this->model->getByProduct($this->productId);
        $this->assertGreaterThanOrEqual(1, $result['total']);
        $titles = array_column($result['items'], 'title');
        $this->assertContains('사이즈 문의', $titles);
    }

    public function testGetByProductDoesNotReturnOtherProductQnas(): void
    {
        $this->insertQna(['product_id' => $this->productId, 'title' => '이 상품 문의']);

        $db = db_connect();
        $db->table('products')->insert([
            'name' => '다른상품', 'slug' => 'other-' . uniqid(),
            'price' => 5000, 'stock' => 5, 'status' => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $otherId = (int) $db->insertID();
        $this->insertQna(['product_id' => $otherId, 'title' => '다른 상품 문의']);

        $result = $this->model->getByProduct($this->productId);
        foreach ($result['items'] as $item) {
            $this->assertSame($this->productId, (int) $item['product_id']);
        }

        $db->table('product_qnas')->where('product_id', $otherId)->delete();
        $db->table('products')->where('id', $otherId)->delete();
    }

    public function testGetByProductReturnsEmptyForUnknownProduct(): void
    {
        $result = $this->model->getByProduct(999999);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    public function testGetByProductPaginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertQna(['title' => "문의{$i}"]);
        }
        $page1 = $this->model->getByProduct($this->productId, 1, 3);
        $page2 = $this->model->getByProduct($this->productId, 2, 3);

        $this->assertCount(3, $page1['items']);
        $this->assertGreaterThanOrEqual(2, count($page2['items']));
        $this->assertSame(5, $page1['total']);
    }

    // ── getUnansweredCount ────────────────────────────────────────────────────

    public function testGetUnansweredCountIncludesUnanswered(): void
    {
        $before = $this->model->getUnansweredCount();
        $this->insertQna(['is_answered' => 0]);
        $after = $this->model->getUnansweredCount();
        $this->assertSame($before + 1, $after);
    }

    public function testGetUnansweredCountExcludesAnswered(): void
    {
        $before = $this->model->getUnansweredCount();
        $this->insertQna(['is_answered' => 1, 'answer' => '답변입니다.']);
        $after = $this->model->getUnansweredCount();
        $this->assertSame($before, $after);
    }

    public function testGetUnansweredCountDecrementsAfterAnswer(): void
    {
        $id = $this->insertQna(['is_answered' => 0]);
        $before = $this->model->getUnansweredCount();
        $this->model->update($id, ['is_answered' => 1, 'answer' => '답변', 'answered_at' => date('Y-m-d H:i:s')]);
        $after = $this->model->getUnansweredCount();
        $this->assertSame($before - 1, $after);
    }

    // ── adminGetAll ───────────────────────────────────────────────────────────

    public function testAdminGetAllReturnsAllQnas(): void
    {
        $this->insertQna(['title' => '관리자조회문의A']);
        $this->insertQna(['title' => '관리자조회문의B']);
        $result = $this->model->adminGetAll([]);
        $titles = array_column($result['items'], 'title');
        $this->assertContains('관리자조회문의A', $titles);
        $this->assertContains('관리자조회문의B', $titles);
    }

    public function testAdminGetAllFiltersByKeyword(): void
    {
        $this->insertQna(['title' => '색상문의유니크키워드']);
        $this->insertQna(['title' => '재고문의다른내용']);
        $result = $this->model->adminGetAll(['keyword' => '색상문의유니크키워드']);
        $titles = array_column($result['items'], 'title');
        $this->assertContains('색상문의유니크키워드', $titles);
        $this->assertNotContains('재고문의다른내용', $titles);
    }

    public function testAdminGetAllFiltersByAnsweredZero(): void
    {
        $this->insertQna(['title' => '미답변문의', 'is_answered' => 0]);
        $this->insertQna(['title' => '답변완료문의', 'is_answered' => 1, 'answer' => '답변']);
        $result = $this->model->adminGetAll(['answered' => '0']);
        foreach ($result['items'] as $item) {
            $this->assertSame(0, (int) $item['is_answered']);
        }
    }

    public function testAdminGetAllFiltersByAnsweredOne(): void
    {
        $this->insertQna(['title' => '미답변필터테스트', 'is_answered' => 0]);
        $this->insertQna(['title' => '답변완료필터테스트', 'is_answered' => 1, 'answer' => '답변']);
        $result = $this->model->adminGetAll(['answered' => '1']);
        foreach ($result['items'] as $item) {
            $this->assertSame(1, (int) $item['is_answered']);
        }
    }

    public function testAdminGetAllIncludesProductName(): void
    {
        $this->insertQna(['title' => '상품명포함검증문의']);
        $result = $this->model->adminGetAll(['keyword' => '상품명포함검증문의']);
        $this->assertNotEmpty($result['items']);
        $this->assertArrayHasKey('product_name', $result['items'][0]);
        $this->assertSame('QNA테스트상품', $result['items'][0]['product_name']);
    }

    public function testAdminGetAllPaginationDefaultPerPage(): void
    {
        $result = $this->model->adminGetAll([]);
        $this->assertSame(20, $result['perPage']);
        $this->assertSame(1, $result['page']);
    }
}
