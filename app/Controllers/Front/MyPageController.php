<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\PaymentReceipt;
use App\Models\CartModel;
use App\Models\OrderModel;
use App\Models\PointLogModel;
use App\Models\ShippingAddressModel;
use App\Models\WishlistModel;

class MyPageController extends BaseController
{
    private readonly OrderModel           $orderModel;
    private readonly ShippingAddressModel $addressModel;
    private readonly PointLogModel        $pointLogModel;
    private readonly WishlistModel        $wishlistModel;

    // 상태 탭 정의 — key: 쿼리 파라미터 값, label: 표시명
    private const array STATUS_TABS = [
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
        $this->orderModel    = new OrderModel();
        $this->addressModel  = new ShippingAddressModel();
        $this->pointLogModel = new PointLogModel();
        $this->wishlistModel = new WishlistModel();
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

        $result     = $this->orderModel->getByUser($userId, ['period' => $period, 'status' => $status, 'keyword' => $keyword, 'page' => $page]);
        $statusTabs = self::STATUS_TABS;

        // 목록 카드용 상품명 요약 — 주문 ID 배열로 한 번에 조회 (N+1 제거)
        $db       = \Config\Database::connect();
        $orderIds = array_column($result['items'], 'id');
        $nameMap  = [];
        $thumbMap = [];
        if ($orderIds !== []) {
            $rows = $db->table('order_items')
                ->select('order_id, product_name')
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id', 'ASC')->orderBy('id', 'ASC')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $nameMap[(int) $row['order_id']][] = $row['product_name'];
            }

            // 목록 카드용 대표 썸네일 — 각 주문 첫 상품의 대표 이미지를 한 번에 조회 (N+1 제거).
            // 상품명 요약(위)과 분리해 is_primary 중복이 요약 건수를 부풀리지 않게 한다.
            $thumbRows = $db->table('order_items oi')
                ->select('oi.order_id, m.file_path AS thumbnail')
                ->join('product_images pi', 'pi.product_id = oi.product_id AND pi.is_primary = 1', 'left')
                ->join('media m', 'm.id = pi.media_id', 'left')
                ->whereIn('oi.order_id', $orderIds)
                ->orderBy('oi.order_id', 'ASC')->orderBy('oi.id', 'ASC')
                ->get()->getResultArray();
            foreach ($thumbRows as $row) {
                $oid = (int) $row['order_id'];
                // 첫 상품을 우선하되, 첫 상품에 이미지가 없으면 이미지 있는 다음 상품으로 채운다.
                if (! array_key_exists($oid, $thumbMap) || ($thumbMap[$oid] === null && ! empty($row['thumbnail']))) {
                    $thumbMap[$oid] = $row['thumbnail'] !== '' ? $row['thumbnail'] : null;
                }
            }
        }
        foreach ($result['items'] as &$order) {
            $names = $nameMap[$order['id']] ?? [];
            $extra = count($names) - 1;
            $order['_name_summary'] = ($names[0] ?? '') . ($extra > 0 ? ' 외 ' . $extra . '건' : '');
            $order['_thumbnail']    = $thumbMap[$order['id']] ?? null;
        }
        unset($order);

        return $this->render('shop/orders/list', array_merge($result, ['period' => $period, 'status' => $status, 'keyword' => $keyword, 'statusTabs' => $statusTabs]));
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

        // MySQLi 는 조회 결과를 문자열로 돌려주므로 int 파라미터에 넘기기 전에 형변환한다.
        $order = $this->orderModel->getWithItems((int) $row['id'], $userId);

        // getWithItems() 는 결제 확정 전 레거시 pending 주문을 걸러내 null 을 돌려준다.
        // 그대로 뷰에 넘기면 상세 화면이 통째로 깨지므로 여기서 목록으로 돌려보낸다. (이슈 #214)
        if ($order === null) {
            return redirect()->to('/mypage/orders')->with('error', '주문을 찾을 수 없습니다.');
        }

        $returnReasonCodes = \App\Models\OrderModel::RETURN_REASON_CODES;
        $rCode             = $order['return_reason_code'] ?? null;
        $returnReasonPayer = $rCode ? ($returnReasonCodes[$rCode]['payer'] ?? null) : null;

        $exchangeReasonCodes = \App\Models\OrderModel::EXCHANGE_REASON_CODES;
        $eCode               = $order['exchange_reason_code'] ?? null;
        $exchangeReasonPayer = $eCode ? ($exchangeReasonCodes[$eCode]['payer'] ?? null) : null;

        // PG 원응답에서 카드 영수증 정보(카드사·카드번호·할부·승인번호)를 정규화해 넘긴다.
        $receipt = PaymentReceipt::fromPayment($order['payment'] ?? null);

        return $this->render('shop/orders/detail', ['order' => $order, 'receipt' => $receipt, 'returnReasonCodes' => $returnReasonCodes, 'returnReasonPayer' => $returnReasonPayer, 'exchangeReasonCodes' => $exchangeReasonCodes, 'exchangeReasonPayer' => $exchangeReasonPayer]);
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
        return $this->render('shop/addresses/index', ['addresses' => $addresses]);
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

        if ($id === 0) {
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
                $this->addressModel->setDefault((int) $next['id'], $userId);
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

        if (! in_array($tab, ['available', 'used'], true)) {
            $tab = 'available';
        }

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

        return $this->render('shop/mypage/coupons', ['coupons' => $coupons, 'tab' => $tab, 'total' => $total, 'currentPage' => $currentPage, 'perPage' => $perPage]);
    }

    /** GET /mypage/points */
    public function points(): string
    {
        $userId       = (int) session()->get('user_id');
        $currentPage  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $result       = $this->pointLogModel->getByUser($userId, $currentPage);
        $user         = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $userId)->get()->getRow();
        $pointBalance = (int) ($user->point_balance ?? 0);

        return $this->render('shop/mypage/points', array_merge($result, ['pointBalance' => $pointBalance]));
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

        // 레거시 pending 주문은 목록에 노출되지 않아 재주문 버튼도 없다 — id 를 직접
        // 넘겨 담는 경로만 남아 있으므로 조회 단계에서 제외한다. 만료(expired) 주문은
        // "취소/환불" 탭에 재주문 버튼과 함께 노출되므로 계속 허용한다. (이슈 #214)
        $order = $this->orderModel
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('status !=', 'pending')
            ->first();
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
        $result['recommended'] = new \App\Libraries\RecommendationService()->forUser($userId, 8);

        return $this->render('shop/mypage/wishlist', $result);
    }
}
