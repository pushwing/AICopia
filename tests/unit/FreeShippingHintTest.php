<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * OrderModel::freeShippingHint() 단위 테스트
 *
 * "○○원 더 담으면 무료배송" 안내를 위한 순수 계산 로직.
 * calculateShippingFee() 와 동일한 규칙(무료 상품·조건부 충족 시 전체 무료)을 전제로,
 * 아직 못 채운 조건부 기준 중 가장 가까운 값과 남은 금액을 돌려준다.
 */
final class FreeShippingHintTest extends CIUnitTestCase
{
    private OrderModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderModel();
    }

    /** @return array<string, mixed> */
    private function item(string $type, int $threshold = 0, int $fee = 3000): array
    {
        return ['shipping_type' => $type, 'free_threshold' => $threshold, 'shipping_fee' => $fee];
    }

    public function testFreeItemNeedsNoHint(): void
    {
        // 무료배송 상품이 있으면 조건부 미충족 상품이 섞여 있어도 전체 무료 → 안내 없음
        $items = [$this->item('free', 0, 0), $this->item('conditional', 50000)];

        $this->assertNull($this->model->freeShippingHint($items, 10000));
    }

    public function testMetConditionalNeedsNoHint(): void
    {
        $items = [$this->item('conditional', 50000)];

        $this->assertNull($this->model->freeShippingHint($items, 50000), '기준 충족 시 안내가 없어야 한다');
    }

    public function testUnmetConditionalReturnsRemaining(): void
    {
        $items = [$this->item('conditional', 50000)];

        $hint = $this->model->freeShippingHint($items, 30000);

        $this->assertSame(['threshold' => 50000, 'remaining' => 20000], $hint);
    }

    public function testNearestUnmetThresholdWins(): void
    {
        // 30000·50000 두 조건부가 모두 미충족이면 더 가까운 30000 기준을 안내한다
        $items = [$this->item('conditional', 50000), $this->item('conditional', 30000)];

        $hint = $this->model->freeShippingHint($items, 20000);

        $this->assertSame(['threshold' => 30000, 'remaining' => 10000], $hint);
    }

    public function testFixedOnlyNeedsNoHint(): void
    {
        // 조건부 상품이 없으면(고정 배송비만) 무료 기준 자체가 없어 안내 대상 아님
        $items = [$this->item('fixed', 0, 3000)];

        $this->assertNull($this->model->freeShippingHint($items, 10000));
    }
}
