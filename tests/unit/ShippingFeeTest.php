<?php

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

    public function testMultipleItemsReturnsMaxFee(): void
    {
        $items = [
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 2500],
            ['shipping_type' => 'fixed', 'free_threshold' => 0, 'shipping_fee' => 5000],
            ['shipping_type' => 'free',  'free_threshold' => 0, 'shipping_fee' => 0],
        ];
        $this->assertSame(5000, $this->model->calculateShippingFee($items, 10000));
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
}
