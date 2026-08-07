<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 주문서(shop/checkout)의 결제 취소·복구 처리 검증.
 *
 * 결제창을 그냥 닫으면 PG SDK 는 requestPayment() 를 reject 한다. 이걸 일반 오류로
 * 취급하면 "오류가 발생했습니다" 경고창이 뜨고(사용자는 취소했을 뿐인데 장애로 읽힌다),
 * 결제 버튼이 '처리 중...' 상태로 잠긴 채 남아 다시 주문할 수도 없다.
 *
 * 뷰의 JS 는 실행해 볼 수단이 없으므로, 렌더된 결과에 취소 처리와 버튼 복구 코드가
 * 남아 있는지를 회귀 방지용으로 확인한다.
 *
 * @internal
 */
final class CheckoutPaymentCancelTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $item = [
            'id'             => 1,
            'product_id'     => 10,
            'name'           => '기본 티셔츠',
            'price'          => 22000,
            'discount_price' => null,
            'qty'            => 1,
            'primary_image'  => null,
            'option_label'   => null,
            'is_available'   => 1,
            'stock'          => 10,
        ];

        return [
            // BaseController::initController() 가 주입하는 전역 데이터
            'settings'        => ['site_name' => '테스트몰'],
            'menus'           => [],
            'authUser'        => ['id' => 1, 'nickname' => '홍길동', 'role' => 'member', 'grade' => 'bronze', 'loggedIn' => true],
            'unreadInquiries' => 0,
            'subLeftBanners'  => [],
            'activePopups'    => [],
            'cartCount'       => 1,
            'categories'      => [],

            // 주문서 전용 데이터
            'available'       => [$item],
            'totalProduct'    => 22000,
            'shippingFee'     => 0,
            'totalAmount'     => 22000,
            'pointBalance'    => 0,
            'pointEarnRate'   => 0,
            'minPayable'      => 0,
            'userCoupons'     => [],
            'savedAddresses'  => [],
            'savedAddress'    => null,
            'pgProviders'     => ['toss' => '토스페이먼츠'],
        ];
    }

    private function html(): string
    {
        return view('shop/checkout', $this->viewData());
    }

    /** 취소 코드를 구분해야 "오류가 발생했습니다" 경고창을 건너뛸 수 있다. */
    public function testCheckoutRecognizesPaymentCancelCodes(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('PAY_PROCESS_CANCELED', $html, '결제창 취소 코드를 구분하지 않고 있습니다.');
        $this->assertStringContainsString('PAY_PROCESS_ABORTED', $html);
        $this->assertStringContainsString('isPaymentCanceled', $html);
    }

    /**
     * 결제창이 닫히거나 실패하면 결제 버튼을 되살려야 한다.
     * finally 없이 catch 안에서만 복구하면, 오류 없이 중간에 return 하는 경로
     * (예: 키 설정 오류 안내)에서 버튼이 '처리 중...' 으로 잠긴 채 남는다.
     */
    public function testCheckoutRestoresOrderButtonInFinally(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '/\}\s*finally\s*\{[^}]*btn\.disabled\s*=\s*false/s',
            $html,
            '결제 버튼 복구가 finally 블록에 없습니다.'
        );
    }
}
