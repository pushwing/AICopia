<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\CouponService;
use App\Libraries\GradeService;
use App\Libraries\ItemPricing;
use App\Libraries\PG\PGFactory;
use App\Models\CartModel;
use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ShippingAddressModel;
use App\Models\UserCouponModel;

class OrderController extends BaseController
{
    private readonly OrderModel          $orderModel;
    private readonly OrderAttemptModel   $attemptModel;
    private readonly CartModel           $cartModel;
    private readonly ProductModel        $productModel;
    private readonly ShippingAddressModel $addressModel;
    private readonly UserCouponModel     $userCouponModel;

    public function __construct()
    {
        $this->orderModel      = new OrderModel();
        $this->attemptModel    = new OrderAttemptModel();
        $this->cartModel       = new CartModel();
        $this->productModel    = new ProductModel();
        $this->addressModel    = new ShippingAddressModel();
        $this->userCouponModel = new UserCouponModel();
    }

    /**
     * 장바구니에서 선택해 넘어온 cart_items.id 목록.
     * 비어 있으면(직접 /order 진입 등) 장바구니 전체를 주문 대상으로 본다.
     *
     * @return list<int>
     */
    private function selectedCartIds(): array
    {
        $ids = session()->get(CartModel::CHECKOUT_SESSION_KEY);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => $id > 0));
    }

    /** GET /order — 주문서 */
    public function index(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId      = (int) session()->get('user_id');
        $selectedIds = $this->selectedCartIds();
        $items       = $this->cartModel->getByUser($userId, $selectedIds);

        if ($items === []) {
            // 선택했던 항목이 그 사이 삭제·주문된 경우도 여기로 온다.
            session()->remove(CartModel::CHECKOUT_SESSION_KEY);

            return redirect()->to('/cart')->with(
                'error',
                $selectedIds === [] ? '장바구니가 비어 있습니다.' : '선택하신 상품을 장바구니에서 찾을 수 없습니다.',
            );
        }

        $available = array_filter($items, fn (array $i) => $i['is_available']);
        if ($available === []) {
            return redirect()->to('/cart')->with('error', '구매 가능한 상품이 없습니다.');
        }

        $totalProduct = ItemPricing::totalProductPrice($available);

        $shippingFee    = $this->orderModel->calculateShippingFee($available, $totalProduct);
        $totalAmount    = $totalProduct + $shippingFee;
        $savedAddresses = $this->addressModel->getByUser($userId);
        $savedAddress   = $this->addressModel->getDefault($userId);
        $pgProviders    = PGFactory::enabledLabels();
        $userCoupons    = $this->userCouponModel->getAvailable($userId, $totalAmount);

        $user         = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRow();
        $pointBalance = (int) ($user->point_balance ?? 0);

        return $this->render('shop/checkout', ['available' => $available, 'totalProduct' => $totalProduct, 'shippingFee' => $shippingFee, 'totalAmount' => $totalAmount, 'savedAddresses' => $savedAddresses, 'savedAddress' => $savedAddress, 'pgProviders' => $pgProviders, 'userCoupons' => $userCoupons, 'pointBalance' => $pointBalance]);
    }

    /**
     * POST /order/create — 주문 생성
     * 쿠폰 확정 + 포인트 차감 + payable_amount 산출까지 서버에서 처리
     */
    public function create(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId   = (int) session()->get('user_id');
        $settings = $this->viewData['settings'];

        $rules = [
            'receiver_name'  => 'required|max_length[100]',
            'receiver_phone' => 'required|max_length[20]',
            'zipcode'        => 'required|max_length[10]',
            'address1'       => 'required|max_length[200]',
            // 실결제액이 0원이면 결제수단 자체가 없으므로 필수가 아니다.
            // 금액을 계산한 뒤(아래) 1원 이상일 때만 선택 여부를 따진다.
            'pg_provider'    => 'permit_empty|in_list[' . implode(',', PGFactory::providers()) . ']',
        ];

        if (! $this->validate($rules)) {
            return $this->response->setJSON(['success' => false, 'errors' => $this->validator->getErrors()]);
        }

        $shippingData = $this->request->getPost(['receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2', 'delivery_memo']);
        $pgProvider   = $this->request->getPost('pg_provider');
        $saveAddress  = (bool) $this->request->getPost('save_address');
        $couponCode   = trim($this->request->getPost('coupon_code') ?? '');
        $userCouponId = (int) ($this->request->getPost('user_coupon_id') ?? 0);
        $pointUse     = max(0, (int) ($this->request->getPost('point_use') ?? 0));

        $items = $this->cartModel->getByUser($userId, $this->selectedCartIds());
        $items = array_values(array_filter($items, fn (array $i) => $i['is_available']));

        if ($items === []) {
            return $this->response->setJSON(['success' => false, 'message' => '구매 가능한 상품이 없습니다.']);
        }

        // 재고 사전 확인
        foreach ($items as $item) {
            $stock = (int) $this->productModel->db
                ->table('products')->select('stock')->where('id', $item['product_id'])->get()->getRow()->stock;
            if ($stock < (int) $item['qty']) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => "[{$item['name']}] 재고가 부족합니다. (현재 {$stock}개)",
                ]);
            }
        }

        // 서버 금액 재계산 (옵션 추가금 포함 — 이슈 #124)
        $totalProduct = ItemPricing::totalProductPrice($items);
        $shippingFee = $this->orderModel->calculateShippingFee($items, $totalProduct);
        $totalAmount = $totalProduct + $shippingFee;

        // 쿠폰 검증
        $couponId             = null;
        $couponDiscountAmount = 0;
        $resolvedUserCouponId = null;

        if ($userCouponId > 0 || $couponCode !== '') {
            $svc = new CouponService();
            $result = $userCouponId > 0
                ? $svc->validateByUserCouponId($userCouponId, $userId, $totalAmount)
                : $svc->validate($couponCode, $userId, $totalAmount);

            if (! $result['valid']) {
                return $this->response->setJSON(['success' => false, 'message' => $result['message']]);
            }
            $couponId             = $result['coupon']['id'];
            $resolvedUserCouponId = $result['user_coupon_id'];
            // 무료배송 쿠폰은 배송비 전액을 할인으로 처리
            $couponDiscountAmount = $result['coupon']['type'] === 'free_shipping'
                ? $shippingFee
                : $result['discount'];
        }

        // 포인트 검증
        if ($pointUse > 0) {
            $user         = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRow();
            $pointBalance = (int) ($user->point_balance ?? 0);
            if ($pointUse > $pointBalance) {
                return $this->response->setJSON(['success' => false, 'message' => '포인트 잔액이 부족합니다.']);
            }
        }

        // payable_amount — 0원이면 PG 를 거치지 않는 무료 주문이 된다
        $payableAmount = max(0, $totalAmount - $couponDiscountAmount - $pointUse);
        $minPayable    = max(0, (int) ($settings['min_payable_amount'] ?? 10000));
        $isFreeOrder   = $payableAmount === 0;

        $payableError = $this->orderModel->validatePayableAmount($payableAmount, $minPayable);
        if ($payableError !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $payableError]);
        }

        // 결제가 실제로 일어나는 주문만 결제수단이 필요하다.
        if (! $isFreeOrder && ($pgProvider === null || $pgProvider === '')) {
            return $this->response->setJSON(['success' => false, 'message' => '결제 수단을 선택해주세요.']);
        }

        // 포인트 적립 예정액 (배송완료 시점 등급 기준 — 여기선 현재 등급으로 미리 계산)
        $userRow     = \Config\Database::connect()->table('users')->select('grade')->where('id', $userId)->get()->getRow();
        $userGrade   = $userRow->grade ?? 'bronze';
        $earnRate    = new GradeService()->getEarnRate($userGrade, $settings);
        $pointEarned = $payableAmount > 0 ? (int) floor($payableAmount * $earnRate / 100) : 0;

        // 주문은 결제가 확정될 때만 orders 에 남긴다(이슈 #214). 여기서는 시도만 만든다.
        $attemptId = $this->attemptModel->createAttempt(
            $userId,
            $shippingData,
            $items,
            $couponId,
            $resolvedUserCouponId,
            $couponDiscountAmount,
            $pointUse,
            $pointEarned,
            $isFreeOrder ? 'free' : $pgProvider
        );

        if ($attemptId === 0) {
            return $this->response->setJSON(['success' => false, 'message' => '주문 생성에 실패했습니다. (포인트 또는 쿠폰 처리 오류)']);
        }

        if ($saveAddress) {
            // 첫 번째 주소는 자동으로 기본 배송지로 설정 (MyPageController::addressStore() 와 동일한 규칙)
            $isFirstAddress = $this->addressModel->where('user_id', $userId)->countAllResults() === 0;
            $addressId      = $this->addressModel->saveAddress($userId, $shippingData);
            if ($isFirstAddress) {
                $this->addressModel->setDefault($addressId, $userId);
            }
        }

        // 무료 주문 — 결제창 없이 바로 확정한다(재고 차감·장바구니 비우기는 convertAttempt 안에서).
        if ($isFreeOrder) {
            $result = $this->orderModel->convertAttempt($attemptId, 'paid', 'free', null, 'free', ['reason' => 'payable_amount = 0']);

            if (! $result->succeeded()) {
                // 무료 주문은 PG 청구가 없어 환불 대상이 아니다 — 실패 원인만
                // 정확히 남기고, 안내는 원인별 문구를 그대로 쓴다.
                log_message('error', "무료 주문 확정 실패 — attempt_id={$attemptId}, reason={$result->failure?->value}");

                return $this->response->setJSON([
                    'success' => false,
                    'message' => $result->failure?->userMessage(charged: false) ?? '주문 생성에 실패했습니다.',
                ]);
            }

            $orderId = $result->orderId;
            $order   = $this->orderModel->getWithItems($orderId, $userId);
            session()->remove(CartModel::CHECKOUT_SESSION_KEY);

            return $this->response->setJSON([
                'success'  => true,
                'orderId'  => $orderId,
                'pgParams' => [
                    'pg'          => 'free',
                    'redirectUrl' => '/order/complete/' . $order['order_number'],
                ],
            ]);
        }

        // 무통장입금 — 입금 계좌를 주문내역에서 확인해야 하므로 즉시 주문으로 전환한다.
        if ($pgProvider === 'bank_transfer') {
            $result = $this->orderModel->convertAttempt($attemptId, 'awaiting_payment', 'bank_transfer', null, '무통장입금', []);

            if (! $result->succeeded()) {
                // 무통장은 아직 입금 전이라 환불 대상이 아니다 — 원인만 남긴다.
                log_message('error', "무통장 주문 생성 실패 — attempt_id={$attemptId}, reason={$result->failure?->value}");

                return $this->response->setJSON([
                    'success' => false,
                    'message' => $result->failure?->userMessage(charged: false) ?? '주문 생성에 실패했습니다.',
                ]);
            }

            $orderId = $result->orderId;
            $order   = $this->orderModel->getWithItems($orderId, $userId);

            // 장바구니 비우기는 convertAttempt() 안에서 처리된다(무통장도 포함).
            session()->remove(CartModel::CHECKOUT_SESSION_KEY);

            return $this->response->setJSON([
                'success'  => true,
                'orderId'  => $orderId,
                'pgParams' => [
                    'pg'          => 'bank_transfer',
                    'redirectUrl' => '/order/bank_transfer/' . $order['order_number'],
                ],
            ]);
        }

        // PG 결제 — 승인 콜백이 와야 orders 로 전환된다. 여기서는 시도 배열을 넘긴다.
        $attemptRow = $this->attemptModel->find($attemptId);
        if ($attemptRow === null) {
            log_message('critical', "[Order] 방금 만든 주문 시도를 다시 읽지 못함 — attempt_id={$attemptId}");

            return $this->response->setJSON(['success' => false, 'message' => '주문 생성에 실패했습니다.']);
        }

        $attempt  = $this->attemptModel->withItems($attemptRow);
        $pg       = PGFactory::make($pgProvider);
        $pgParams = $pg->buildPaymentParams($attempt);

        if ($pgProvider === 'kakaopay' && isset($pgParams['tid'])) {
            session()->set('kakaopay_tid', $pgParams['tid']);
            session()->set('kakaopay_order_number', $attempt['order_number']);
        }
        // 승인(confirm) 요청의 orderId 는 결제창에 넘긴 값과 완전히 같아야 한다.
        // 어댑터가 만든 값을 그대로 보관해 두 값이 어긋날 여지를 없앤다.
        // (키 설정 오류로 어댑터가 error 만 돌려준 경우엔 orderId 자체가 없다.)
        if ($pgProvider === 'toss' && isset($pgParams['orderId'])) {
            session()->set('toss_order_id', (string) $pgParams['orderId']);
        }

        return $this->response->setJSON([
            'success'   => true,
            'attemptId' => $attemptId,
            'pgParams'  => $pgParams,
        ]);
    }

    /** GET /order/bank_transfer/:orderNumber */
    public function bankTransfer(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');
        $order  = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();

        if (! $order || ! in_array($order['status'], ['awaiting_payment', 'paid'], true)) {
            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }

        $order = $this->orderModel->getWithItems((int) $order['id'], $userId);

        return $this->render('shop/bank_transfer', ['order' => $order]);
    }

    /** GET /order/complete/:orderNumber */
    public function complete(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');
        $order  = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();

        if (! $order || $order['status'] !== 'paid') {
            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }

        $order = $this->orderModel->getWithItems((int) $order['id'], $userId);

        return $this->render('shop/order_complete', ['order' => $order]);
    }

    /** GET /order/fail/:orderNumber */
    public function fail(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');

        // 결제가 확정되지 않은 시도는 즉시 걷어내 쿠폰·포인트를 돌려준다(이슈 #214).
        // 이미 전환됐으면 markFailed() 가 false 를 돌려주므로 아무 일도 일어나지 않는다.
        $attempt = $this->attemptModel
            ->where('order_number', $orderNumber)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
        if ($attempt !== null) {
            $this->attemptModel->markFailed((int) $attempt['id'], '결제 실패 또는 결제창 이탈');
        }

        // 토스는 카카오페이·PAYCO·이니시스·네이버페이와 달리 별도 취소 URL이 없어,
        // 사용자가 결제창을 그냥 닫아도 진짜 승인 실패와 구분 없이 failUrl(이 라우트)로
        // 온다. code 가 취소성 코드면 다른 PG와 동일하게 주문서로 돌려보낸다 — 진짜
        // 승인 실패(카드 한도 초과 등)는 이 코드가 아니므로 그대로 실패 화면을 보여준다.
        $code = $this->request->getGet('code');
        if (in_array($code, ['PAY_PROCESS_CANCELED', 'PAY_PROCESS_ABORTED', 'USER_CANCEL'], true)) {
            return redirect()->to('/order');
        }

        $order   = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();
        $message = session()->getFlashdata('pg_error') ?? '결제에 실패했습니다.';

        return $this->render('shop/order_fail', ['order' => $order, 'message' => $message]);
    }

    /** POST /order/cancel */
    public function cancel(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) session()->get('user_id');
        $orderId = (int) $this->request->getPost('order_id');

        if (! $orderId) {
            return $this->response->setJSON(['success' => false, 'message' => '잘못된 요청입니다.']);
        }

        $success = $this->orderModel->cancelOrder($orderId, $userId);

        return $this->response->setJSON([
            'success' => $success,
            'message' => $success ? '주문이 취소되었습니다.' : '취소할 수 없는 주문입니다.',
        ]);
    }
}
