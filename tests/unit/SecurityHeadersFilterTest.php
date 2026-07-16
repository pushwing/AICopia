<?php

declare(strict_types=1);

use App\Filters\SecurityHeadersFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecurityHeadersFilterTest extends CIUnitTestCase
{
    private SecurityHeadersFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new SecurityHeadersFilter();
    }

    public function testCspAllowsNaverShoppingImageCdn(): void
    {
        $request  = service('request');
        $response = service('response');

        $result = $this->filter->after($request, $response, null);

        $csp = $result->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString('shopping-phinf.pstatic.net', $csp);
    }
}
