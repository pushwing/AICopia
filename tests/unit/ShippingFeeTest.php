<?php

declare(strict_types=1);

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * OrderModel::calculateShippingFee() 순수 계산 로직 테스트.
 * 실제 DB 쿼리를 실행하지 않으며, DB 연결 객체만 초기화됩니다.
 *
 * @internal
 */
final class ShippingFeeTest extends CIUnitTestCase
{
    private OrderModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    public function testConditionalFreeWhenThresholdMet(): void
    {
        $items = [['shipping_type' => 'conditional', 'free_threshold' => 30000, 'shipping_fee' => 3000]];
        $this->assertSame(0, $this->model->calculateShippingFee($items, 30000));
    }

    public function testConditionalFreeWhenThresholdNotMet(): void
    {
        $items = [['shipping_type' => 'conditional', 'free_threshold' => 30000, 'shipping_fee' => 3000]];
        $this->assertSame(3000, $this->model->calculateShippingFee($items, 29999));
    }

    public function testFreeShippingTypeAlwaysZero(): void
    {
        $items = [['shipping_type' => 'free', 'free_threshold' => 0, 'shipping_fee' => 0]];
        $this->assertSame(0, $this->model->calculateShippingFee($items, 0));
    }

    public function testFixedShippingReturnsConfiguredFee(): void
    {
        $items = [['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 2500]];
        $this->assertSame(2500, $this->model->calculateShippingFee($items, 0));
    }

    public function testMultipleFixedItemsWithoutFreeItemReturnsMaxFee(): void
    {
        $items = [
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 2500],
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 5000],
        ];
        $this->assertSame(5000, $this->model->calculateShippingFee($items, 10000));
    }

    public function testAnyFreeShippingItemWaivesWholeOrderFee(): void
    {
        // 무료배송 상품이 하나라도 있으면 나머지 유료배송 상품이 섞여 있어도 전체 무료배송으로 처리한다.
        $items = [
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 2500],
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 5000],
            ['shipping_type' => 'free',  'free_threshold' => 0, 'shipping_fee' => 0],
        ];
        $this->assertSame(0, $this->model->calculateShippingFee($items, 10000));
    }

    public function testEmptyItemsReturnsZero(): void
    {
        $this->assertSame(0, $this->model->calculateShippingFee([], 0));
    }

    public function testConditionalWithZeroThresholdIsNotFree(): void
    {
        // free_threshold = 0 이면 조건부 무료 미적용
        $items = [['shipping_type' => 'conditional', 'free_threshold' => 0, 'shipping_fee' => 3000]];
        $this->assertSame(3000, $this->model->calculateShippingFee($items, 50000));
    }

    public function testAddonShippingFeeIgnoredWhenParentIsFreeShipping(): void
    {
        // 이슈 #170: 본품이 무료배송이면 함께 담긴 추가구성상품(애드온)의 배송비도 부과되지 않아야 한다.
        $items = [
            ['product_id' => 1, 'parent_product_id' => null, 'shipping_type' => 'free', 'free_threshold' => 0, 'shipping_fee' => 0],
            ['product_id' => 2, 'parent_product_id' => 1, 'shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 3000],
        ];
        $this->assertSame(0, $this->model->calculateShippingFee($items, 10000));
    }

    public function testAddonShippingFeeAppliesWhenParentIsNotFreeShipping(): void
    {
        // 본품이 무료배송이 아니면 애드온 배송비는 기존처럼 최댓값 비교에 참여한다.
        $items = [
            ['product_id' => 1, 'parent_product_id' => null, 'shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 2500],
            ['product_id' => 2, 'parent_product_id' => 1, 'shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 3000],
        ];
        $this->assertSame(3000, $this->model->calculateShippingFee($items, 10000));
    }

    public function testAddonShippingFeeAppliesWhenParentNotInItems(): void
    {
        // 본품 행이 함께 넘어오지 않으면(예: 부분 선택 구매) 애드온은 자기 배송비대로 계산된다.
        $items = [
            ['product_id' => 2, 'parent_product_id' => 1, 'shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 3000],
        ];
        $this->assertSame(3000, $this->model->calculateShippingFee($items, 10000));
    }
}
