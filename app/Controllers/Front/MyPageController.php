<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Models\CartModel;
use App\Models\OrderModel;
use App\Models\PointLogModel;
use App\Models\ShippingAddressModel;
use App\Models\UserCouponModel;
use App\Models\WishlistModel;

class MyPageController extends BaseController
{
    private OrderModel           $orderModel;
    private ShippingAddressModel $addressModel;
    private UserCouponModel      $userCouponModel;
    private PointLogModel        $pointLogModel;
    private WishlistModel        $wishlistModel;

    // 상태 탭 정의 — key: 쿼리 파라미터 값, label: 표시명
    private const STATUS_TABS = [
        ''                  => '전체',
        'awaiting_payment'  => '입금대기',
        'paid'              => '결제완료',
        'preparing'         => '배송준비',
        'shipped'           => '배송중',
        'delivered'         => '배송완료',
        'cancel'            => '취소/환불',
    ];

    public function __construct()
    {
        $this->orderModel      = new OrderModel();
        $this->addressModel    = new ShippingAddressModel();
        $this->userCouponModel = new UserCouponModel();
        $this->pointLogModel   = new PointLogModel();
        $this->wishlistModel   = new WishlistModel();
    }

    /** GET /mypage/orders */
    public function orders(): string
    {
        $userId  = (int) session()->get('user_id');
        $period  = $this->request->getGet('period') ?? 'all';
        $status  = $this->request->getGet('status') ?? '';
        $keyword = trim($this->request->getGet('keyword') ?? '');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        // 유효하지 않은 period 차단
        if (! in_array($period, ['1m', '3m', 'all'], true)) {
            $period = 'all';
        }

        // 유효하지 않은 status 차단
        if (! array_key_exists($status, self::STATUS_TABS)) {
            $status = '';
        }

        $result     = $this->orderModel->getByUser($userId, compact('period', 'status', 'keyword', 'page'));
        $statusTabs = self::STATUS_TABS;

        // 목록 카드용 상품명 요약 — 주문 ID 배열로 한 번에 조회 (N+1 제거)
        $db       = \Config\Database::connect();
        $orderIds = array_column($result['items'], 'id');
        $nameMap  = [];
        if ($orderIds) {
            $rows = $db->table('order_items')
                ->select('order_id, product_name')
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id', 'ASC')->orderBy('id', 'ASC')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $nameMap[(int) $row['order_id']][] = $row['product_name'];
            }
        }
        foreach ($result['items'] as &$order) {
            $names = $nameMap[$order['id']] ?? [];
            $extra = count($names) - 1;
            $order['_name_summary'] = ($names[0] ?? '') . ($extra > 0 ? ' 외 ' . $extra . '건' : '');
        }
        unset($order);

        return $this->render('shop/orders/list', array_merge($result, compact('period', 'status', 'keyword', 'statusTabs')));
    }

    /** GET /mypage/orders/:orderNumber */
    public function orderDetail(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');

        $row = $this->orderModel
            ->where('order_number', $orderNumber)
            ->where('user_id', $userId)
            ->first();

        if (! $row) {
            return redirect()->to('/mypage/orders')->with('error', '주문을 찾을 수 없습니다.');
        }

        $order = $this->orderModel->getWithItems($row['id'], $userId);

        $returnReasonCodes = \App\Models\OrderModel::RETURN_REASON_CODES;
        $rCode             = $order['return_reason_code'] ?? null;
        $returnReasonPayer = $rCode ? ($returnReasonCodes[$rCode]['payer'] ?? null) : null;

        $exchangeReasonCodes = \App\Models\OrderModel::EXCHANGE_REASON_CODES;
        $eCode               = $order['exchange_reason_code'] ?? null;
        $exchangeReasonPayer = $eCode ? ($exchangeReasonCodes[$eCode]['payer'] ?? null) : null;

        return $this->render('shop/orders/detail', compact(
            'order', 'returnReasonCodes', 'returnReasonPayer',
            'exchangeReasonCodes', 'exchangeReasonPayer'
        ));
    }

    /** POST /mypage/orders/confirm-delivery — 배송 완료 확인 */
    public function confirmDelivery(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) session()->get('user_id');
        $orderId = (int) $this->request->getPost('order_id');

        if (! $orderId) {
            return $this->response->setJSON(['success' => false, 'message' => '잘못된 요청입니다.']);
        }

        $order = $this->orderModel->where('id', $orderId)->where('user_id', $userId)->first();

        if (! $order || $order['status'] !== 'shipped') {
            return $this->response->setJSON(['success' => false, 'message' => '배송 완료 처리할 수 없는 주문입니다.']);
        }

        $ok = $this->orderModel->updateStatus($orderId, 'delivered');

        return $this->response->setJSON(['success' => $ok, 'message' => $ok ? '' : '처리에 실패했습니다.']);
    }

    /** GET /mypage/addresses */
    public function addresses(): string
    {
        $userId    = (int) session()->get('user_id');
        $addresses = $this->addressModel->getByUser($userId);
        return $this->render('shop/addresses/index', compact('addresses'));
    }

    /** POST /mypage/addresses */
    public function addressStore(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');

        $rules = [
            'receiver_name'  => 'required|max_length[100]',
            'receiver_phone' => 'required|max_length[20]',
            'zipcode'        => 'required|max_length[10]',
            'address1'       => 'required|max_length[200]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost(['receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2']);

        $count = $this->addressModel->where('user_id', $userId)->countAllResults();
        $id    = $this->addressModel->saveAddress($userId, $data);

        if (! $id) {
            return redirect()->back()->withInput()->with('error', '배송지 저장에 실패했습니다. 다시 시도해주세요.');
        }

        // 첫 번째 주소는 자동으로 기본 배송지로 설정
        if ($count === 0) {
            $this->addressModel->setDefault($id, $userId);
        }

        return redirect()->to('/mypage/addresses')->with('success', '배송지가 저장되었습니다.');
    }

    /** POST /mypage/addresses/:id/default */
    public function addressSetDefault(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');

        if (! $this->addressModel->setDefault($id, $userId)) {
            return redirect()->to('/mypage/addresses')->with('error', '배송지를 찾을 수 없습니다.');
        }

        return redirect()->to('/mypage/addresses')->with('success', '기본 배송지가 변경되었습니다.');
    }

    /** POST /mypage/addresses/:id/delete */
    public function addressDelete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId  = (int) session()->get('user_id');
        $address = $this->addressModel->where('id', $id)->where('user_id', $userId)->first();

        if (! $address) {
            return redirect()->to('/mypage/addresses')->with('error', '배송지를 찾을 수 없습니다.');
        }

        $db = \Config\Database::connect();
        $db->transStart();

        $deleted = $this->addressModel->deleteByUser($id, $userId);

        if ($deleted && $address['is_default']) {
            $next = $this->addressModel->where('user_id', $userId)->orderBy('id', 'DESC')->first();
            if ($next) {
                $this->addressModel->setDefault($next['id'], $userId);
            }
        }

        $db->transComplete();

        if (! $db->transStatus() || ! $deleted) {
            return redirect()->to('/mypage/addresses')->with('error', '배송지 삭제에 실패했습니다.');
        }

        return redirect()->to('/mypage/addresses')->with('success', '배송지가 삭제되었습니다.');
    }

    /** GET /mypage/coupons */
    public function coupons(): string
    {
        $userId  = (int) session()->get('user_id');
        $tab         = $this->request->getGet('tab') ?? 'available';
        $currentPage = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage     = 10;

        if (! in_array($tab, ['available', 'used'], true)) $tab = 'available';

        $now     = date('Y-m-d H:i:s');
        $builder = \Config\Database::connect()->table('user_coupons uc')
            ->select('uc.*, c.code, c.name, c.type, c.discount_value,
                      c.min_order_amount, c.max_discount_amount, c.expires_at')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.user_id', $userId);

        if ($tab === 'available') {
            $builder->where('uc.status', 'issued')
                ->groupStart()
                    ->where('c.expires_at IS NULL', null, false)
                    ->orWhere('c.expires_at >=', $now)
                ->groupEnd();
        } else {
            $builder->where('uc.status', 'used');
        }

        $total   = (clone $builder)->countAllResults();
        $coupons = $builder->orderBy('uc.id', 'DESC')
            ->limit($perPage, ($currentPage - 1) * $perPage)
            ->get()->getResultArray();

        return $this->render('shop/mypage/coupons', compact('coupons', 'tab', 'total', 'currentPage', 'perPage'));
    }

    /** GET /mypage/points */
    public function points(): string
    {
        $userId       = (int) session()->get('user_id');
        $currentPage  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $result       = $this->pointLogModel->getByUser($userId, $currentPage);
        $user         = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRow();
        $pointBalance = (int) ($user->point_balance ?? 0);

        return $this->render('shop/mypage/points', array_merge($result, compact('pointBalance')));
    }

    /** POST /mypage/orders/return-request — 반품 신청 (배송 완료 후) */
    public function requestReturn(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId     = (int) session()->get('user_id');
        $orderId    = (int) $this->request->getPost('order_id');
        $reasonCode = trim($this->request->getPost('reason_code') ?? '');
        $note       = trim($this->request->getPost('note') ?? '');

        if (! $orderId || $reasonCode === '') {
            return $this->response->setJSON(['success' => false, 'message' => '반품 사유를 선택해주세요.']);
        }

        if (! array_key_exists($reasonCode, \App\Models\OrderModel::RETURN_REASON_CODES)) {
            return $this->response->setJSON(['success' => false, 'message' => '올바르지 않은 반품 사유입니다.']);
        }

        if ($note !== '' && mb_strlen($note) > 500) {
            return $this->response->setJSON(['success' => false, 'message' => '상세 사유는 500자 이내로 입력해주세요.']);
        }

        $success = $this->orderModel->requestReturn($orderId, $userId, $reasonCode, $note);

        return $this->response->setJSON([
            'success' => $success,
            'message' => $success ? '반품 신청이 완료되었습니다.' : '반품 신청 기간이 지났거나 신청할 수 없는 주문입니다.',
        ]);
    }

    /** POST /mypage/orders/exchange-request */
    public function requestExchange(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId     = (int) session()->get('user_id');
        $orderId    = (int) $this->request->getPost('order_id');
        $reasonCode = trim($this->request->getPost('reason_code') ?? '');
        $note       = trim($this->request->getPost('note')        ?? '');

        if (! $orderId || $reasonCode === '') {
            return $this->response->setJSON(['success' => false, 'message' => '교환 사유를 선택해주세요.']);
        }

        if (! array_key_exists($reasonCode, \App\Models\OrderModel::EXCHANGE_REASON_CODES)) {
            return $this->response->setJSON(['success' => false, 'message' => '올바르지 않은 교환 사유입니다.']);
        }

        if ($note !== '' && mb_strlen($note) > 1000) {
            return $this->response->setJSON(['success' => false, 'message' => '상세 내용은 1000자 이내로 입력해주세요.']);
        }

        $success = $this->orderModel->requestExchange($orderId, $userId, $reasonCode, $note);

        return $this->response->setJSON([
            'success' => $success,
            'message' => $success ? '교환 신청이 완료되었습니다.' : '교환 신청 기간이 지났거나 신청할 수 없는 주문입니다.',
        ]);
    }

    /** POST /mypage/orders/cancel — 즉시 취소 */
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

    /** POST /mypage/orders/reorder — 주문 상품을 장바구니에 다시 담기 */
    public function reorder(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId  = (int) session()->get('user_id');
        $orderId = (int) $this->request->getPost('order_id');

        if (! $orderId) {
            return $this->response->setJSON(['success' => false, 'message' => '잘못된 요청입니다.']);
        }

        $order = $this->orderModel->where('id', $orderId)->where('user_id', $userId)->first();
        if (! $order) {
            return $this->response->setJSON(['success' => false, 'message' => '주문을 찾을 수 없습니다.']);
        }

        $db    = \Config\Database::connect();
        $items = $db->table('order_items')
            ->select('product_id, sku_id, qty')
            ->where('order_id', $orderId)
            ->get()->getResultArray();

        if (! $items) {
            return $this->response->setJSON(['success' => false, 'message' => '주문 상품이 없습니다.']);
        }

        $cartModel = new CartModel();
        foreach ($items as $item) {
            $cartModel->upsert(
                $userId,
                (int) $item['product_id'],
                (int) $item['qty'],
                $item['sku_id'] ? (int) $item['sku_id'] : null
            );
        }

        return $this->response->setJSON([
            'success'   => true,
            'message'   => '장바구니에 담았습니다.',
            'cartCount' => $cartModel->getCount($userId),
        ]);
    }

    /** GET /mypage/wishlist */
    public function wishlist(): string
    {
        $userId      = (int) session()->get('user_id');
        $currentPage = max(1, (int) ($this->request->getGet('page') ?? 1));
        $result      = $this->wishlistModel->getByUser($userId, $currentPage);

        // 개인화 추천 (찜 목록 하단)
        $result['recommended'] = (new \App\Libraries\RecommendationService())->forUser($userId, 8);

        return $this->render('shop/mypage/wishlist', $result);
    }
}
