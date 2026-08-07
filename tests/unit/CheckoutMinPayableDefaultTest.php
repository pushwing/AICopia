<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 주문서의 최소 결제 금액 기본값이 서버와 일치하는지 검증.
 *
 * min_payable_amount 설정 행이 없을 때 뷰가 0 으로, 서버
 * (OrderController::create)가 10000 으로 가정하면 클라이언트는 통과시킨 주문을
 * 서버가 JSON 에러로 거부해 안내가 어긋난다. 두 기본값은 같아야 한다.
 *
 * @internal
 */
final class CheckoutMinPayableDefaultTest extends CIUnitTestCase
{
    /** OrderController::create() 가 쓰는 기본값. 바뀌면 뷰도 함께 바뀌어야 한다. */
    private const SERVER_DEFAULT = 10000;

    /**
     * BaseController 전역 데이터 + OrderController::checkout() 페이지 데이터.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function viewData(array $settings): array
    {
        return [
            'settings'        => array_merge(['site_name' => '테스트몰'], $settings),
            'menus'           => [],
            'authUser'        => ['id' => 1, 'nickname' => '구매자', 'role' => 'member', 'grade' => 'bronze', 'loggedIn' => true],
            'unreadInquiries' => 0,
            'subLeftBanners'  => [],
            'activePopups'    => [],
            'cartCount'       => 1,
            'categories'      => [],
            // CartModel::getByUser() 가 돌려주는 항목 형태
            'available'       => [[
                'id'             => 1,
                'product_id'     => 10,
                'sku_id'         => null,
                'name'           => '기본 티셔츠',
                'slug'           => 'basic-tee',
                'price'          => 30000,
                'discount_price' => null,
                'qty'            => 1,
                'primary_image'  => null,
                'display_price'  => 30000,
                'price_diff'     => 0,
                'sku_label'      => '',
                'is_available'   => true,
            ]],
            'totalProduct'    => 30000,
            'shippingFee'     => 3000,
            'totalAmount'     => 33000,
            'savedAddresses'  => [],
            'savedAddress'    => null,
            'pgProviders'     => ['toss' => '토스페이먼츠'],
            'userCoupons'     => [],
            'pointBalance'    => 50000,
        ];
    }

    private function minPayableInRenderedView(array $settings): int
    {
        $html = view('shop/checkout', $this->viewData($settings));

        $this->assertMatchesRegularExpression(
            '/const MIN_PAYABLE\s*=\s*(\d+);/',
            $html,
            '주문서에서 MIN_PAYABLE 상수를 찾지 못했습니다.'
        );
        preg_match('/const MIN_PAYABLE\s*=\s*(\d+);/', $html, $m);

        return (int) $m[1];
    }

    /** 설정 행이 없으면 서버와 같은 기본값을 써야 한다. */
    public function testDefaultsToServerDefaultWhenSettingMissing(): void
    {
        $this->assertSame(self::SERVER_DEFAULT, $this->minPayableInRenderedView([]));
    }

    /** 설정이 있으면 그 값을 그대로 쓴다. */
    public function testUsesConfiguredValueWhenSettingPresent(): void
    {
        $this->assertSame(5000, $this->minPayableInRenderedView(['min_payable_amount' => '5000']));
    }

    /** 최소 결제 금액을 0 으로 꺼둔 경우도 그대로 반영한다. */
    public function testAllowsExplicitZeroToDisableMinimum(): void
    {
        $this->assertSame(0, $this->minPayableInRenderedView(['min_payable_amount' => '0']));
    }
}
