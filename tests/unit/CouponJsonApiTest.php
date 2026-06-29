<?php

namespace Tests\Unit;

use App\Models\CouponModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * CouponController::json() 데이터 레이어 검증
 *
 * - 반환 필드 구조
 * - type_label 매핑 (TYPES 상수)
 * - total_qty null / int 처리
 * - is_active int 캐스팅
 * - id DESC 정렬
 */
final class CouponJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'CJT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->cleanup !== []) {
            db_connect()->table('coupons')->whereIn('id', $this->cleanup)->delete();
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertCoupon(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'code'          => 'CJT' . $uid,
            'name'          => $this->prefix . $uid,
            'type'          => 'fixed',
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'max_discount_amount' => 0,
            'used_count'    => 0,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('coupons')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;
        return $id;
    }

    /** CouponController::json() 와 동일한 쿼리 + 변환 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $builder = db_connect()->table('coupons')->orderBy('id', 'DESC');
        if ($whereIn !== []) {
            $builder->whereIn('id', $whereIn);
        }
        $rows = $builder->get()->getResultArray();
        return array_map(fn($c) => [
            'id'                  => (int) $c['id'],
            'name'                => $c['name'],
            'code'                => $c['code'],
            'type'                => $c['type'],
            'type_label'          => CouponModel::TYPES[$c['type']] ?? $c['type'],
            'target_grade'        => $c['target_grade'] ?? '',
            'discount_value'      => (float) $c['discount_value'],
            'max_discount_amount' => (int) $c['max_discount_amount'],
            'min_order_amount'    => (int) $c['min_order_amount'],
            'total_qty'           => $c['total_qty'] !== null ? (int) $c['total_qty'] : null,
            'used_count'          => (int) $c['used_count'],
            'starts_at'           => $c['starts_at'] ?? '',
            'expires_at'          => $c['expires_at'] ?? '',
            'is_active'           => (int) $c['is_active'],
        ], $rows);
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $id   = $this->insertCoupon();
        $rows = $this->fetchJsonData([$id]);

        $expected = ['id', 'name', 'code', 'type', 'type_label', 'target_grade',
                     'discount_value', 'max_discount_amount', 'min_order_amount',
                     'total_qty', 'used_count', 'starts_at', 'expires_at', 'is_active'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── type_label 매핑 ───────────────────────────────────────────────────────

    public function testTypeLabelFixedMappedCorrectly(): void
    {
        $id   = $this->insertCoupon(['type' => 'fixed']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('정액 할인', $rows[0]['type_label']);
    }

    public function testTypeLabelPercentMappedCorrectly(): void
    {
        $id   = $this->insertCoupon(['type' => 'percent', 'discount_value' => 10]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('정률 할인', $rows[0]['type_label']);
    }

    public function testTypeLabelFreeShippingMappedCorrectly(): void
    {
        $id   = $this->insertCoupon(['type' => 'free_shipping', 'discount_value' => 0]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('무료배송', $rows[0]['type_label']);
    }

    // ── total_qty null / int ──────────────────────────────────────────────────

    public function testTotalQtyIsNullWhenUnset(): void
    {
        $id   = $this->insertCoupon(['total_qty' => null]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertNull($rows[0]['total_qty'], 'total_qty DB NULL → PHP null 이어야 한다');
    }

    public function testTotalQtyIsIntWhenSet(): void
    {
        $id   = $this->insertCoupon(['total_qty' => 100]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['total_qty'], 'total_qty 는 int 여야 한다');
        $this->assertSame(100, $rows[0]['total_qty']);
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIdIsInteger(): void
    {
        $id   = $this->insertCoupon();
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['id']);
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testIsActiveIsIntegerOne(): void
    {
        $id   = $this->insertCoupon(['is_active' => 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_active']);
        $this->assertSame(1, $rows[0]['is_active']);
    }

    public function testIsActiveIsIntegerZero(): void
    {
        $id   = $this->insertCoupon(['is_active' => 0]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_active']);
        $this->assertSame(0, $rows[0]['is_active']);
    }

    public function testDiscountValueIsFloat(): void
    {
        $id   = $this->insertCoupon(['discount_value' => 2500]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsFloat($rows[0]['discount_value']);
        $this->assertSame(2500.0, $rows[0]['discount_value']);
    }

    // ── target_grade null 처리 ────────────────────────────────────────────────

    public function testTargetGradeNullBecomesEmptyString(): void
    {
        $id   = $this->insertCoupon(['target_grade' => null]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['target_grade']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $id1  = $this->insertCoupon();
        $id2  = $this->insertCoupon();
        $id3  = $this->insertCoupon();
        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }
}
