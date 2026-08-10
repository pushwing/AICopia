<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\AddonGrouping;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 엑셀 '상품명' 칸에 묶음이 드러나는지 — 포장 담당자가 같은 박스를 알아볼 수 있어야 한다.
 * 컨트롤러가 쓰는 것과 같은 AddonGrouping::labels() 를 직접 검증한다.
 */
final class OrderExcelAddonTest extends CIUnitTestCase
{
    public function testAddonFollowsParentWithPlusPrefix(): void
    {
        $rows = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null, 'product_name' => 'Patient Plate', 'qty' => 1],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null, 'product_name' => '다른상품', 'qty' => 1],
            ['id' => 3, 'product_id' => 30, 'parent_product_id' => 10, 'product_name' => 'Gender', 'qty' => 2],
        ];

        $this->assertSame(
            ['Patient Plate x1', '+ Gender x2', '다른상품 x1'],
            AddonGrouping::labels($rows),
        );
    }

    public function testPlainOrderIsUnchanged(): void
    {
        $rows = [
            ['id' => 1, 'product_id' => 10, 'parent_product_id' => null, 'product_name' => '상품A', 'qty' => 2],
            ['id' => 2, 'product_id' => 20, 'parent_product_id' => null, 'product_name' => '상품B', 'qty' => 1],
        ];

        $this->assertSame(['상품A x2', '상품B x1'], AddonGrouping::labels($rows), '애드온이 없으면 기존 형식 그대로여야 한다');
    }
}
