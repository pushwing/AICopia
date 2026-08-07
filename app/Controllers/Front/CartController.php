<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Models\CartModel;
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
     * GET /cart — 장바구니 목록 (auth:member 필터로 보호)
     * 세션 장바구니가 있으면 DB에 병합
     */
    public function index(): string
    {
        $userId = (int) session()->get('user_id');

        $this->cartModel->mergeAndClear($userId);

        // 장바구니로 돌아왔다는 건 선택을 다시 한다는 뜻 — 이전 선택은 흘려보낸다.
        session()->remove(CartModel::CHECKOUT_SESSION_KEY);

        $items = $this->cartModel->getByUser($userId);

        return $this->render('shop/cart', ['items' => $items]);
    }

    /**
     * POST /cart/checkout — 선택한 상품만 주문서로 넘긴다 (auth:member)
     * 선택 항목의 cart_items.id 를 세션에 담고 주문서로 리다이렉트한다.
     */
    public function checkout(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');

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

        // 상품 유효성 확인
        $row = $this->productModel->db
            ->table('products')
            ->select('id, stock, status, deleted_at')
            ->where('id', $productId)
            ->where('status', 'on_sale')
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        if (! $row) {
            return $this->response->setJSON(['success' => false, 'message' => '구매할 수 없는 상품입니다.']);
        }

        // SKU 재고 / 상품 재고 분기
        if ($skuId !== null) {
            $sku = $this->skuModel->findForProduct($skuId, $productId);
            if (! $sku) {
                return $this->response->setJSON(['success' => false, 'message' => '존재하지 않는 옵션입니다.']);
            }
            $stock = (int) $sku['stock'];
        } else {
            $stock = (int) $row['stock'];
        }

        if ($stock < 1) {
            return $this->response->setJSON(['success' => false, 'message' => '재고가 없습니다.']);
        }

        $qty    = min($qty, $stock);
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
