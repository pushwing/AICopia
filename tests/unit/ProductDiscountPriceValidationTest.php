<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 상품 등록/수정 시 할인가는 정가보다 반드시 낮아야 한다 (같은 값도 불가).
 * Issue #284 — 할인가가 정가보다 높거나 같게 입력되던 문제 수정.
 */
final class ProductDiscountPriceValidationTest extends CIUnitTestCase
{
    public function testDiscountPriceLowerThanPriceIsValid(): void
    {
        $this->assertTrue(ProductModel::isDiscountPriceValid(10000, 9000));
    }

    public function testDiscountPriceEqualToPriceIsInvalid(): void
    {
        $this->assertFalse(ProductModel::isDiscountPriceValid(10000, 10000));
    }

    public function testDiscountPriceHigherThanPriceIsInvalid(): void
    {
        $this->assertFalse(ProductModel::isDiscountPriceValid(10000, 15000));
    }

    public function testNullDiscountPriceIsValid(): void
    {
        $this->assertTrue(ProductModel::isDiscountPriceValid(10000, null));
    }

    public function testZeroDiscountPriceIsValidWhenPricePositive(): void
    {
        $this->assertTrue(ProductModel::isDiscountPriceValid(10000, 0));
    }
}
