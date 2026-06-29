<?php

namespace Tests\Unit;

use App\Models\PromotionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * PromotionModel — 활성 기획전 조회 / 등급 접근 / 상품 동기화 테스트
 */
final class PromotionModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private PromotionModel $model;

    private array $cleanup = [
        'promotions' => [],
        'products'   => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new PromotionModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['promotions'] !== []) {
            $db->table('promotion_products')->whereIn('promotion_id', $this->cleanup['promotions'])->delete();
            $db->table('promotions')->whereIn('id', $this->cleanup['promotions'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertPromotion(array $extra = []): int
    {
        $db = db_connect();
        $db->table('promotions')->insert(array_merge([
            'title'        => '테스트 기획전',
            'slug'         => 'test-promo-' . uniqid(),
            'description'  => '',
            'banner_image' => null,
            'grade_access' => 'all',
            'start_date'   => null,
            'end_date'     => null,
            'is_active'    => 1,
            'sort_order'   => 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['promotions'][] = $id;
        return $id;
    }

    private function insertProduct(): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => '기획전상품_' . uniqid(),
            'slug'           => 'promo-prod-' . uniqid(),
            'price'          => 10000,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    // ── getActiveBySlug ───────────────────────────────────────────────────────

    public function testGetActiveBySlug_active_returnsPromotion(): void
    {
        $slug = 'test-promo-' . uniqid();
        $this->insertPromotion(['slug' => $slug]);

        $result = $this->model->getActiveBySlug($slug);

        $this->assertNotNull($result);
        $this->assertSame($slug, $result['slug']);
    }

    public function testGetActiveBySlug_inactive_returnsNull(): void
    {
        $slug = 'test-promo-' . uniqid();
        $this->insertPromotion(['slug' => $slug, 'is_active' => 0]);

        $this->assertNull($this->model->getActiveBySlug($slug));
    }

    public function testGetActiveBySlug_startDateFuture_returnsNull(): void
    {
        $slug = 'test-promo-' . uniqid();
        $this->insertPromotion([
            'slug'       => $slug,
            'start_date' => date('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertNull($this->model->getActiveBySlug($slug));
    }

    public function testGetActiveBySlug_endDatePast_returnsNull(): void
    {
        $slug = 'test-promo-' . uniqid();
        $this->insertPromotion([
            'slug'     => $slug,
            'end_date' => date('Y-m-d', strtotime('-1 day')),
        ]);

        $this->assertNull($this->model->getActiveBySlug($slug));
    }

    public function testGetActiveBySlug_withinDateRange_returnsPromotion(): void
    {
        $slug = 'test-promo-' . uniqid();
        $this->insertPromotion([
            'slug'       => $slug,
            'start_date' => date('Y-m-d', strtotime('-1 day')),
            'end_date'   => date('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertNotNull($this->model->getActiveBySlug($slug));
    }

    // ── checkGradeAccess (순수 로직, DB 불필요) ──────────────────────────────

    public function testCheckGradeAccess_all_alwaysTrue(): void
    {
        $this->assertTrue($this->model->checkGradeAccess('all', null));
        $this->assertTrue($this->model->checkGradeAccess('all', 'bronze'));
        $this->assertTrue($this->model->checkGradeAccess('all', 'platinum'));
    }

    public function testCheckGradeAccess_noUserForRequiredGrade_returnsFalse(): void
    {
        $this->assertFalse($this->model->checkGradeAccess('bronze', null));
        $this->assertFalse($this->model->checkGradeAccess('platinum', null));
    }

    public function testCheckGradeAccess_exactGrade_returnsTrue(): void
    {
        $this->assertTrue($this->model->checkGradeAccess('gold', 'gold'));
        $this->assertTrue($this->model->checkGradeAccess('bronze', 'bronze'));
    }

    public function testCheckGradeAccess_higherGrade_returnsTrue(): void
    {
        $this->assertTrue($this->model->checkGradeAccess('silver', 'gold'));
        $this->assertTrue($this->model->checkGradeAccess('bronze', 'platinum'));
        $this->assertTrue($this->model->checkGradeAccess('gold', 'platinum'));
    }

    public function testCheckGradeAccess_lowerGrade_returnsFalse(): void
    {
        $this->assertFalse($this->model->checkGradeAccess('gold', 'silver'));
        $this->assertFalse($this->model->checkGradeAccess('platinum', 'gold'));
        $this->assertFalse($this->model->checkGradeAccess('silver', 'bronze'));
    }

    // ── syncProducts ─────────────────────────────────────────────────────────

    public function testSyncProducts_replacesExistingProducts(): void
    {
        $db      = db_connect();
        $promoId = $this->insertPromotion();
        $prod1   = $this->insertProduct();
        $prod2   = $this->insertProduct();
        $prod3   = $this->insertProduct();

        // 초기 상품 등록
        $this->model->syncProducts($promoId, [
            ['product_id' => $prod1, 'sort_order' => 0],
            ['product_id' => $prod2, 'sort_order' => 1],
        ]);

        $count = $db->table('promotion_products')->where('promotion_id', $promoId)->countAllResults();
        $this->assertSame(2, $count);

        // 완전 교체
        $this->model->syncProducts($promoId, [
            ['product_id' => $prod3, 'sort_order' => 0],
        ]);

        $rows = $db->table('promotion_products')->where('promotion_id', $promoId)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($prod3, (int) $rows[0]['product_id']);
    }

    public function testSyncProducts_emptyList_removesAllProducts(): void
    {
        $db      = db_connect();
        $promoId = $this->insertPromotion();
        $prod1   = $this->insertProduct();

        $this->model->syncProducts($promoId, [['product_id' => $prod1, 'sort_order' => 0]]);
        $this->model->syncProducts($promoId, []);

        $count = $db->table('promotion_products')->where('promotion_id', $promoId)->countAllResults();
        $this->assertSame(0, $count);
    }
}
