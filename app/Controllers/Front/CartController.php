<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Models\CartModel;
use App\Models\ProductAddonModel;
use App\Models\ProductModel;
use App\Models\ProductSkuModel;

class CartController extends BaseController
{
    private readonly CartModel       $cartModel;
    private readonly ProductModel    $productModel;
    private readonly ProductSkuModel $skuModel;

    public function __construct()
    {
        $this->cartModel    = new CartModel();
        $this->productModel = new ProductModel();
        $this->skuModel     = new ProductSkuModel();
    }

    /**
     * GET /cart — 장바구니 목록
     * 회원은 DB 장바구니를, 비회원은 세션 장바구니를 조회한다.
     */
    public function index(): string
    {
        $userId = (int) session()->get('user_id');

        if ($userId > 0) {
            $this->cartModel->mergeAndClear($userId);
        }

        // 장바구니로 돌아왔다는 건 선택을 다시 한다는 뜻 — 이전 선택은 흘려보낸다.
        session()->remove(CartModel::CHECKOUT_SESSION_KEY);

        $items = $userId > 0
            ? $this->cartModel->getByUser($userId)
            : $this->cartModel->getBySession((array) (session()->get('cart') ?? []));

        return $this->render('shop/cart', ['items' => $items, 'isGuest' => $userId === 0]);
    }

    /**
     * POST /cart/checkout — 비회원은 로그인 후 주문서로, 회원은 선택한 상품만 주문서로 넘긴다.
     * 선택 항목의 cart_items.id 를 세션에 담고 주문서로 리다이렉트한다.
     */
    public function checkout(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');

        if ($userId === 0) {
            // 로그인 과정에서 세션 장바구니가 DB 장바구니로 병합된 뒤 주문서로 이동한다.
            session()->setFlashdata('redirect_url', site_url('order'));

            return redirect()->to('/auth/login')->with('error', '주문하려면 로그인이 필요합니다.');
        }

        $cartIds = array_values(array_unique(array_filter(
            array_map(intval(...), (array) $this->request->getPost('cart_ids')),
            static fn (int $id): bool => $id > 0,
        )));

        if ($cartIds === []) {
            return redirect()->to('/cart')->with('error', '주문할 상품을 선택해주세요.');
        }

        // user_id 조건이 함께 걸리므로 남의 장바구니 id 는 여기서 걸러진다.
        $selected = array_filter(
            $this->cartModel->getByUser($userId, $cartIds),
            static fn (array $item): bool => (bool) $item['is_available'],
        );

        if ($selected === []) {
            return redirect()->to('/cart')->with('error', '구매 가능한 상품이 없습니다.');
        }

        session()->set(
            CartModel::CHECKOUT_SESSION_KEY,
            array_values(array_map(static fn (array $item): int => (int) $item['id'], $selected)),
        );

        return redirect()->to('/order');
    }

    /**
     * 세션 장바구니의 재고 맵 구성 (SKU/상품 구분)
     * 반환: ['productId_skuId' => stock, ...]
     */
    /**
     * POST /cart/add — 장바구니 담기 (로그인·비로그인 모두 허용)
     * 로그인: DB, 비로그인: 세션
     */
    public function add(): \CodeIgniter\HTTP\ResponseInterface
    {
        $productId = (int) $this->request->getPost('product_id');
        $qty       = max(1, (int) $this->request->getPost('qty'));
        $skuId     = $this->request->getPost('sku_id') ? (int) $this->request->getPost('sku_id') : null;

        $check = $this->checkPurchasable($productId, $skuId, $qty);

        if (! $check['ok']) {
            // addBundle()과 조회 로직은 공유하되, /cart/add 고유의 사유별 문구는 그대로 유지한다.
            $message = match ($check['reason']) {
                'not_found'      => '구매할 수 없는 상품입니다.',
                'invalid_option' => '존재하지 않는 옵션입니다.',
                'out_of_stock'   => '재고가 없습니다.',
                default          => '구매할 수 없는 상품입니다.',
            };

            return $this->response->setJSON(['success' => false, 'message' => $message]);
        }

        $qty    = $check['qty'];
        $stock  = $check['stock'];
        $userId = session()->get('user_id');

        if ($userId) {
            $this->cartModel->upsert((int) $userId, $productId, $qty, $skuId);
            $count = $this->cartModel->getCount((int) $userId);
        } else {
            $cart    = session()->get('cart') ?? [];
            $sessKey = CartModel::sessionKey($productId, $skuId);
            $cart[$sessKey] = min(($cart[$sessKey] ?? 0) + $qty, $stock);
            session()->set('cart', $cart);
            $count = count($cart);
        }

        return $this->response->setJSON([
            'success'   => true,
            'message'   => '장바구니에 담겼습니다.',
            'cartCount' => $count,
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * POST /cart/add-bundle — 본품 + 추가구성상품을 한 요청으로 담는다.
     *
     * 요청마다 CSRF 토큰이 회전하므로 /cart/add 를 N 번 부르는 대신 한 번에 처리한다.
     */
    public function addBundle(): \CodeIgniter\HTTP\ResponseInterface
    {
        $productId = (int) $this->request->getPost('product_id');
        $qty       = max(1, (int) $this->request->getPost('qty'));
        $skuId     = $this->request->getPost('sku_id') ? (int) $this->request->getPost('sku_id') : null;
        $addons    = $this->request->getPost('addons');
        $addons    = is_array($addons) ? $addons : [];

        $mainKey = CartModel::sessionKey($productId, $skuId);

        // (product_id, sku_id) 기준으로 애드온 요청을 먼저 합산한다. 같은 애드온이 여러
        // 항목으로 쪼개져 들어와도 재고는 합산 수량 기준으로 딱 한 번만 클리핑해야
        // resolvePurchasable() 을 반복 호출하며 같은 재고를 중복으로 승인하는 걸 막는다.
        //
        // 본품과 동일한 (product_id, sku_id) 로 들어온 애드온 항목은 별도 상품이 아니라
        // "본품을 더 담아달라"는 요청으로 취급해 본품 수량에 합산한다 — 애드온 목록으로
        // 분류됐다는 이유만으로 본품과 같은 재고 풀을 한 번 더 클리핑해서 실제 재고보다
        // 많이 담기는 것을 막기 위함이다. 이 경우 addon-link 검증은 의미가 없으므로
        // (사용자가 이미 본품으로 직접 구매 요청한 상품) 건너뛴다.
        /** @var array<string, array{product_id: int, sku_id: int|null, qty: int}> $addonRequests */
        $addonRequests = [];
        $mainExtraQty  = 0;

        foreach ($addons as $addon) {
            $addonId  = (int) ($addon['product_id'] ?? 0);
            $addonSku = isset($addon['sku_id']) && $addon['sku_id'] ? (int) $addon['sku_id'] : null;
            $addonQty = max(1, (int) ($addon['qty'] ?? 1));
            $key      = CartModel::sessionKey($addonId, $addonSku);

            if ($key === $mainKey) {
                $mainExtraQty += $addonQty;
                continue;
            }

            if (! isset($addonRequests[$key])) {
                $addonRequests[$key] = ['product_id' => $addonId, 'sku_id' => $addonSku, 'qty' => 0];
            }
            $addonRequests[$key]['qty'] += $addonQty;
        }

        $main = $this->resolvePurchasable($productId, $skuId, $qty + $mainExtraQty);
        if ($main === null) {
            return $this->response->setJSON(['success' => false, 'message' => '구매할 수 없는 상품입니다.', 'csrf_hash' => csrf_hash()]);
        }

        $addonModel = new ProductAddonModel();
        $accepted   = [];
        $skipped    = [];

        foreach ($addonRequests as $req) {
            if (! $addonModel->isLinked($productId, $req['product_id'])) {
                $skipped[] = '추가구성상품이 아닌 항목은 담지 않았습니다.';
                continue;
            }

            $resolved = $this->resolvePurchasable($req['product_id'], $req['sku_id'], $req['qty']);
            if ($resolved === null) {
                $skipped[] = '품절이거나 판매하지 않는 추가구성상품은 담지 않았습니다.';
                continue;
            }

            $accepted[] = $resolved;
        }

        $this->storeInCart($main['product_id'], $main['sku_id'], $main['qty'], null, $main['stock']);
        foreach ($accepted as $item) {
            $this->storeInCart($item['product_id'], $item['sku_id'], $item['qty'], $productId, $item['stock']);
        }

        $userId = session()->get('user_id');
        $count  = $userId ? $this->cartModel->getCount((int) $userId) : count(session()->get('cart') ?? []);

        return $this->response->setJSON([
            'success'   => true,
            'message'   => '장바구니에 담겼습니다.',
            'cartCount' => $count,
            'skipped'   => array_values(array_unique($skipped)),
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * 살 수 있는 상품인지 확인하고 재고까지 클리핑한 수량을 돌려준다.
     *
     * add()가 필요로 하는 "왜 실패했는지"는 checkPurchasable()의 reason 을 그대로 버린다 —
     * addBundle() 쪽 스킵 사유는 사유 구분 없이 뭉뚱그린 문구를 쓰기 때문이다.
     *
     * @return array{product_id: int, sku_id: int|null, qty: int, stock: int}|null
     */
    private function resolvePurchasable(int $productId, ?int $skuId, int $qty): ?array
    {
        $check = $this->checkPurchasable($productId, $skuId, $qty);

        if (! $check['ok']) {
            return null;
        }

        return [
            'product_id' => $check['product_id'],
            'sku_id'     => $check['sku_id'],
            'qty'        => $check['qty'],
            'stock'      => $check['stock'],
        ];
    }

    /**
     * 상품 구매 가능 여부를 확인하고 재고까지 클리핑한 수량을 돌려준다.
     *
     * add()와 addBundle() 양쪽이 공유하는 조회 로직. 실패 사유(reason)를 구분해서
     * 돌려주므로 호출부가 각자 원하는 문구로 매핑한다 — add()는 사유별로 다른
     * 메시지를, addBundle()은 뭉뚱그린 스킵 사유 메시지를 쓴다.
     *
     * @return array{ok: bool, reason: ('not_found'|'invalid_option'|'out_of_stock')|null, product_id: int, sku_id: int|null, qty: int, stock: int}
     */
    private function checkPurchasable(int $productId, ?int $skuId, int $qty): array
    {
        $row = $this->productModel->db
            ->table('products')
            ->select('id, stock, status, deleted_at')
            ->where('id', $productId)
            ->where('status', 'on_sale')
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        if (! $row) {
            return ['ok' => false, 'reason' => 'not_found', 'product_id' => $productId, 'sku_id' => $skuId, 'qty' => 0, 'stock' => 0];
        }

        if ($skuId !== null) {
            $sku = $this->skuModel->findForProduct($skuId, $productId);
            if (! $sku) {
                return ['ok' => false, 'reason' => 'invalid_option', 'product_id' => $productId, 'sku_id' => $skuId, 'qty' => 0, 'stock' => 0];
            }
            $stock = (int) $sku['stock'];
        } else {
            $stock = (int) $row['stock'];
        }

        if ($stock < 1) {
            return ['ok' => false, 'reason' => 'out_of_stock', 'product_id' => $productId, 'sku_id' => $skuId, 'qty' => 0, 'stock' => 0];
        }

        return ['ok' => true, 'reason' => null, 'product_id' => $productId, 'sku_id' => $skuId, 'qty' => min($qty, $stock), 'stock' => $stock];
    }

    /**
     * 회원이면 DB, 비회원이면 세션에 담는다.
     *
     * $stock 은 resolvePurchasable() 이 이미 조회해 클리핑에 사용한 값을 그대로 받는다 —
     * 여기서 다시 쿼리하지 않는다. add()의 비회원 분기(min(기존 + qty, stock))와 동일하게,
     * 이미 세션에 담겨 있던 수량까지 합산한 뒤 재고를 넘지 않도록 클리핑해야 한다.
     * addBundle()이 한 요청 안에서 클리핑하는 qty 만으로는 이전 요청에서 세션에 이미
     * 쌓여 있던 수량을 볼 수 없기 때문이다.
     */
    private function storeInCart(int $productId, ?int $skuId, int $qty, ?int $parentProductId, int $stock): void
    {
        $userId = session()->get('user_id');

        if ($userId) {
            $this->cartModel->upsert((int) $userId, $productId, $qty, $skuId, $parentProductId);

            return;
        }

        $cart    = session()->get('cart') ?? [];
        $parents = session()->get('cart_addon_of') ?? [];
        $sessKey = CartModel::sessionKey($productId, $skuId);

        $cart[$sessKey] = min(($cart[$sessKey] ?? 0) + $qty, $stock);
        if ($parentProductId !== null && ! isset($parents[$sessKey])) {
            $parents[$sessKey] = $parentProductId;
        }

        session()->set('cart', $cart);
        session()->set('cart_addon_of', $parents);
    }

    /**
     * POST /cart/update — 수량 수정 (Ajax, auth:member)
     */
    public function update(): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId    = (int) session()->get('user_id');
        $productId = (int) $this->request->getPost('product_id');
        $qty       = max(1, (int) $this->request->getPost('qty'));
        $skuId     = $this->request->getPost('sku_id') ? (int) $this->request->getPost('sku_id') : null;

        $builder = $this->cartModel->where('user_id', $userId)->where('product_id', $productId);
        if ($skuId !== null) {
            $builder->where('sku_id', $skuId);
        } else {
            $builder->where('sku_id IS NULL', null, false);
        }
        if (! $builder->first()) {
            return $this->response->setJSON(['success' => false, 'message' => '장바구니에 없는 상품입니다.']);
        }

        // 재고 상한 클리핑
        if ($skuId !== null) {
            $skuRow = $this->skuModel->db->table('product_skus')->where('id', $skuId)->get()->getRowArray();
            if ($skuRow) {
                $qty = min($qty, (int) $skuRow['stock']);
            }
        } else {
            $stockRow = $this->productModel->db->table('products')->select('stock')->where('id', $productId)->get()->getRow();
            if ($stockRow && (int) $stockRow->stock > 0) {
                $qty = min($qty, (int) $stockRow->stock);
            }
        }

        $this->cartModel->updateQty($userId, $productId, $qty, $skuId);

        return $this->response->setJSON(['success' => true, 'qty' => $qty]);
    }

    /**
     * POST /cart/delete — 개별 삭제 (auth:member)
     */
    public function delete(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId    = (int) session()->get('user_id');
        $productId = (int) $this->request->getPost('product_id');
        $skuId     = $this->request->getPost('sku_id') ? (int) $this->request->getPost('sku_id') : null;

        $this->cartModel->removeItem($userId, $productId, $skuId);

        return redirect()->to('/cart')->with('success', '상품이 삭제되었습니다.');
    }

    /**
     * POST /cart/clear — 전체 비우기 (auth:member)
     */
    public function clear(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');
        $this->cartModel->clear($userId);

        return redirect()->to('/cart');
    }
}
