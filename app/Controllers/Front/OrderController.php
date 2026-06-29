<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\CouponService;
use App\Libraries\GradeService;
use App\Libraries\PG\PGFactory;
use App\Models\CartModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\ShippingAddressModel;
use App\Models\UserCouponModel;

class OrderController extends BaseController
{
    private OrderModel          $orderModel;
    private CartModel           $cartModel;
    private ProductModel        $productModel;
    private ShippingAddressModel $addressModel;
    private UserCouponModel     $userCouponModel;

    public function __construct()
    {
        $this->orderModel      = new OrderModel();
        $this->cartModel       = new CartModel();
        $this->productModel    = new ProductModel();
        $this->addressModel    = new ShippingAddressModel();
        $this->userCouponModel = new UserCouponModel();
    }

    /** GET /order — 주문서 */
    public function index(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');
        $items  = $this->cartModel->getByUser($userId);

        if (empty($items)) {
            return redirect()->to('/cart')->with('error', '장바구니가 비어 있습니다.');
        }

        $available = array_filter($items, fn($i) => $i['is_available']);
        if (empty($available)) {
            return redirect()->to('/cart')->with('error', '구매 가능한 상품이 없습니다.');
        }

        $totalProduct = array_sum(array_map(
            fn($i) => ($i['discount_price'] ?? $i['price']) * $i['qty'],
            $available
        ));

        $shippingFee    = $this->orderModel->calculateShippingFee($available, $totalProduct);
        $totalAmount    = $totalProduct + $shippingFee;
        $savedAddresses = $this->addressModel->getByUser($userId);
        $savedAddress   = $this->addressModel->getDefault($userId);
        $pgProviders    = PGFactory::enabledLabels();
        $userCoupons    = $this->userCouponModel->getAvailable($userId, $totalAmount);

        $user         = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRow();
        $pointBalance = (int) ($user->point_balance ?? 0);

        return $this->render('shop/checkout', compact(
            'available', 'totalProduct', 'shippingFee', 'totalAmount',
            'savedAddresses', 'savedAddress', 'pgProviders',
            'userCoupons', 'pointBalance'
        ));
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
            'pg_provider'    => 'required|in_list[' . implode(',', PGFactory::providers()) . ']',
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

        $items = $this->cartModel->getByUser($userId);
        $items = array_values(array_filter($items, fn($i) => $i['is_available']));

        if (empty($items)) {
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

        // 서버 금액 재계산
        $totalProduct = array_sum(array_map(
            fn($i) => ((int) ($i['discount_price'] ?? $i['price'])) * (int) $i['qty'],
            $items
        ));
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

        // payable_amount
        $payableAmount = max(0, $totalAmount - $couponDiscountAmount - $pointUse);
        $minPayable    = max(0, (int) ($settings['min_payable_amount'] ?? 10000));
        if ($payableAmount > 0 && $payableAmount < $minPayable) {
            return $this->response->setJSON([
                'success' => false,
                'message' => '최소 결제 금액은 ' . number_format($minPayable) . '원입니다. 포인트 사용량을 조정해주세요.',
            ]);
        }

        // 포인트 적립 예정액 (배송완료 시점 등급 기준 — 여기선 현재 등급으로 미리 계산)
        $userRow     = \Config\Database::connect()->table('users')->select('grade')->where('id', $userId)->get()->getRow();
        $userGrade   = $userRow->grade ?? 'bronze';
        $earnRate    = (new GradeService())->getEarnRate($userGrade, $settings);
        $pointEarned = $payableAmount > 0 ? (int) floor($payableAmount * $earnRate / 100) : 0;

        $orderId = $this->orderModel->createPending(
            $userId, $shippingData, $items,
            $couponId, $resolvedUserCouponId,
            $couponDiscountAmount, $pointUse, $pointEarned
        );

        if (! $orderId) {
            return $this->response->setJSON(['success' => false, 'message' => '주문 생성에 실패했습니다. (포인트 또는 쿠폰 처리 오류)']);
        }

        if ($saveAddress) {
            $this->addressModel->saveAddress($userId, $shippingData);
        }

        // 무통장입금
        if ($pgProvider === 'bank_transfer') {
            $db    = \Config\Database::connect();
            $now   = date('Y-m-d H:i:s');
            $order = $this->orderModel->getWithItems($orderId, $userId);

            $this->orderModel->update($orderId, ['status' => 'awaiting_payment']);

            $db->table('payments')->insert([
                'order_id'     => $orderId,
                'pg_provider'  => 'bank_transfer',
                'pg_tid'       => null,
                'method'       => '무통장입금',
                'amount'       => (int) $order['payable_amount'],
                'status'       => 'pending',
                'raw_response' => '{}',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $productIds = array_column($items, 'product_id');
            $db->table('cart_items')
                ->where('user_id', $userId)
                ->whereIn('product_id', $productIds)
                ->delete();

            return $this->response->setJSON([
                'success'  => true,
                'orderId'  => $orderId,
                'pgParams' => [
                    'pg'          => 'bank_transfer',
                    'redirectUrl' => '/order/bank_transfer/' . $order['order_number'],
                ],
            ]);
        }

        $order    = $this->orderModel->getWithItems($orderId, $userId);
        $pg       = PGFactory::make($pgProvider);
        $pgParams = $pg->buildPaymentParams($order);

        if ($pgProvider === 'kakaopay' && isset($pgParams['tid'])) {
            session()->set('kakaopay_tid', $pgParams['tid']);
            session()->set('kakaopay_order_number', $order['order_number']);
        }
        if ($pgProvider === 'toss') {
            session()->set('toss_order_id', (string) $orderId);
        }

        return $this->response->setJSON([
            'success'  => true,
            'orderId'  => $orderId,
            'pgParams' => $pgParams,
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

        $order = $this->orderModel->getWithItems($order['id'], $userId);

        return $this->render('shop/bank_transfer', compact('order'));
    }

    /** GET /order/complete/:orderNumber */
    public function complete(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');
        $order  = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();

        if (! $order || $order['status'] !== 'paid') {
            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }

        $order = $this->orderModel->getWithItems($order['id'], $userId);

        return $this->render('shop/order_complete', compact('order'));
    }

    /** GET /order/fail/:orderNumber */
    public function fail(string $orderNumber): string
    {
        $userId  = (int) session()->get('user_id');
        $order   = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();
        $message = session()->getFlashdata('pg_error') ?? '결제에 실패했습니다.';

        return $this->render('shop/order_fail', compact('order', 'message'));
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
