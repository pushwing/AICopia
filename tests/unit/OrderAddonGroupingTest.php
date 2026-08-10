<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\AddonGrouping;
use CodeIgniter\Test\CIUnitTestCase;

final class OrderAddonGroupingTest extends CIUnitTestCase
{
    public function testPlacesAddonRightAfterItsParent(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([10, 30, 20], array_column($ordered, 'product_id'));
        $this->assertFalse($ordered[0]['is_addon']);
        $this->assertTrue($ordered[1]['is_addon']);
        $this->assertFalse($ordered[2]['is_addon']);
    }

    public function testGroupsUnderFirstMatchingParentRow(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([1, 3, 2], array_column($ordered, 'id'), '같은 본품이 두 줄이면 첫 줄 아래로 묶인다');
    }

    public function testKeepsOrphanAddonAtTheEnd(): void
    {
        $items = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null],
            ['id' => 2, 'product_id' => 30, 'parent_product_id' => 99],
        ];

        $ordered = AddonGrouping::order($items);

        $this->assertSame([1, 2], array_column($ordered, 'id'));
        $this->assertFalse($ordered[1]['is_addon'], '본품을 못 찾은 애드온은 일반 항목으로 둔다');
    }

    public function testEmptyList(): void
    {
        $this->assertSame([], AddonGrouping::order([]));
    }
}
