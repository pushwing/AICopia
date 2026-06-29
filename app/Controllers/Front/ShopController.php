<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Models\BannerModel;
use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\ProductQnaModel;
use App\Models\ProductReviewModel;
use App\Models\ProductSkuModel;
use App\Models\PromotionModel;
use App\Models\RestockAlertModel;
use App\Models\WishlistModel;

class ShopController extends BaseController
{
    private ProductModel       $productModel;
    private CategoryModel      $categoryModel;
    private ProductImageModel  $imageModel;
    private ProductQnaModel    $qnaModel;
    private ProductReviewModel $reviewModel;
    private ProductSkuModel    $skuModel;
    private WishlistModel      $wishlistModel;
    private RestockAlertModel  $restockModel;

    public function __construct()
    {
        $this->productModel  = new ProductModel();
        $this->categoryModel = new CategoryModel();
        $this->imageModel    = new ProductImageModel();
        $this->qnaModel      = new ProductQnaModel();
        $this->reviewModel   = new ProductReviewModel();
        $this->skuModel      = new ProductSkuModel();
        $this->wishlistModel = new WishlistModel();
        $this->restockModel  = new RestockAlertModel();
    }

    public function home(): string
    {
        if (($this->viewData['settings']['store_homepage'] ?? 'default') === 'welcome') {
            return $this->welcome();
        }

        $bannerModel = new BannerModel();
        $newProducts = $this->productModel->getLatest(8);
        $this->imageModel->attachPrimaryImages($newProducts);

        $discountedProducts = $this->productModel->getDiscounted(8);
        $this->imageModel->attachPrimaryImages($discountedProducts);

        $promotions = (new PromotionModel())->getActiveFrontList();

        return $this->render('shop/home', [
            'mainTopBanners'     => $bannerModel->getActiveByPosition('main_top'),
            'mainBotBanners'     => $bannerModel->getActiveByPosition('main_bottom'),
            'newProducts'        => $newProducts,
            'discountedProducts' => $discountedProducts,
            'promotions'         => $promotions,
        ]);
    }

    public function welcome(): string
    {
        $s           = $this->viewData['settings'] ?? [];
        $bannerModel = new BannerModel();

        $newCount      = max(1, (int) ($s['welcome_new_count']      ?? 8));
        $discountCount = max(1, (int) ($s['welcome_discount_count'] ?? 8));
        $featuredCount = max(1, (int) ($s['welcome_featured_count'] ?? 8));

        $newProducts = ($s['welcome_show_new'] ?? '1') ? $this->productModel->getLatest($newCount) : [];
        if ($newProducts) $this->imageModel->attachPrimaryImages($newProducts);

        $discountedProducts = ($s['welcome_show_discount'] ?? '1') ? $this->productModel->getDiscounted($discountCount) : [];
        if ($discountedProducts) $this->imageModel->attachPrimaryImages($discountedProducts);

        $featuredProducts = ($s['welcome_show_featured'] ?? '1') ? $this->productModel->getFeatured($featuredCount) : [];
        if ($featuredProducts) $this->imageModel->attachPrimaryImages($featuredProducts);

        $categories = ($s['welcome_show_categories'] ?? '1') ? $this->categoryModel->getTree() : [];

        return $this->render('shop/welcome', [
            'heroBanners'        => ($s['welcome_show_hero'] ?? '1')          ? $bannerModel->getActiveByPosition('main_top')    : [],
            'mainBotBanners'     => ($s['welcome_show_bottom_banner'] ?? '1') ? $bannerModel->getActiveByPosition('main_bottom') : [],
            'newProducts'        => $newProducts,
            'discountedProducts' => $discountedProducts,
            'featuredProducts'   => $featuredProducts,
            'categories'         => $categories,
            'wcfg'               => $s,
        ]);
    }

    public function detail(string $slug): string
    {
        $product = $this->productModel
            ->where('slug', $slug)
            ->whereIn('status', ['on_sale', 'sold_out'])
            ->first();

        if (! $product) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // 재고는 캐시 아닌 DB 직접 조회 (이슈 #3 원칙)
        $freshStock = $this->productModel->db
            ->table('products')
            ->select('stock')
            ->where('id', $product['id'])
            ->get()->getRow();

        $product['stock']  = $freshStock ? (int) $freshStock->stock : 0;
        $product['status'] = $product['stock'] === 0 ? 'sold_out' : $product['status'];

        $images = $this->imageModel->getByProduct($product['id']);

        $qnaPerPage = 10;
        $qnaPage    = max(1, (int) ($this->request->getGet('qna_page') ?? 1));
        $qnaData    = $this->qnaModel->getByProduct($product['id'], $qnaPage, $qnaPerPage);

        $reviewPerPage = 10;
        $reviewPage    = max(1, (int) ($this->request->getGet('review_page') ?? 1));
        $reviewData    = $this->reviewModel->getByProduct($product['id'], $reviewPage, $reviewPerPage);

        $canWriteReview = false;
        $reviewOrderId  = null;
        $userId = (int) session()->get('user_id');
        if ($userId > 0) {
            $reviewOrderId  = $this->reviewModel->canWriteReview($userId, $product['id']);
            $canWriteReview = $reviewOrderId !== null;
        }

        $optionsAndSkus = $this->skuModel->getOptionsAndSkus($product['id']);

        // 찜 여부
        $isWished = $userId > 0
            ? $this->wishlistModel->isWished($userId, (int) $product['id'])
            : false;

        // 최근 본 상품 쿠키 업데이트
        $viewed = json_decode($this->request->getCookie('recently_viewed') ?? '[]', true);
        if (! is_array($viewed)) $viewed = [];
        $viewed = array_values(array_filter($viewed, fn($s) => $s !== $slug));
        array_unshift($viewed, $slug);
        $viewed = array_slice($viewed, 0, 11);
        $this->response->setCookie('recently_viewed', json_encode($viewed), 30 * 24 * 3600);

        // 최근 본 상품 목록 (현재 상품 제외, 최대 10개)
        $recentSlugs    = array_values(array_filter($viewed, fn($s) => $s !== $slug));
        $recentProducts = [];
        if ($recentSlugs) {
            $recentProducts = $this->productModel
                ->whereIn('slug', $recentSlugs)
                ->whereIn('status', ['on_sale', 'sold_out'])
                ->findAll();
            $this->imageModel->attachPrimaryImages($recentProducts);
            usort($recentProducts, fn($a, $b) =>
                array_search($a['slug'], $recentSlugs) <=> array_search($b['slug'], $recentSlugs)
            );
        }

        return $this->render('shop/detail', [
            'product'         => $product,
            'images'          => $images,
            'shipping_policy' => $this->viewData['settings']['shipping_policy'] ?? '',
            // 옵션 / SKU
            'options'         => $optionsAndSkus['options'],
            'skus'            => $optionsAndSkus['skus'],
            // QnA
            'qnaItems'        => $qnaData['items'],
            'qnaTotal'        => $qnaData['total'],
            'qnaPage'         => $qnaPage,
            'qnaPerPage'      => $qnaPerPage,
            // 리뷰
            'reviewItems'     => $reviewData['items'],
            'reviewTotal'     => $reviewData['total'],
            'reviewPage'      => $reviewPage,
            'reviewPerPage'   => $reviewPerPage,
            'canWriteReview'  => $canWriteReview,
            'reviewOrderId'   => $reviewOrderId,
            'reviewSummary'   => \App\Libraries\AiProvider\AiCache::get(
                \App\Libraries\AiProvider\ReviewSummaryHandler::cacheKey((int) $product['id'])
            ),
            // 찜 / 최근 본 상품
            'isWished'        => $isWished,
            'recentProducts'  => $recentProducts,
        ]);
    }

    /** POST /shop/:slug/wish — 찜 토글 (회원 전용) */
    public function wishToggle(string $slug): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return $this->response->setJSON(['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $product = $this->productModel->where('slug', $slug)->first();
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $wished = $this->wishlistModel->toggle($userId, (int) $product['id']);
        return $this->response->setJSON(['success' => true, 'wished' => $wished]);
    }

    /** POST /shop/:slug/qna */
    public function qnaStore(string $slug): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return $this->response->setJSON(['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $product = $this->productModel->where('slug', $slug)->whereIn('status', ['on_sale', 'sold_out'])->first();
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $title   = trim($this->request->getPost('title') ?? '');
        $content = trim($this->request->getPost('content') ?? '');
        if ($title === '' || $content === '') {
            return $this->response->setJSON(['success' => false, 'message' => '제목과 내용을 입력해주세요.']);
        }

        $this->qnaModel->insert([
            'product_id' => $product['id'],
            'user_id'    => $userId,
            'title'      => $title,
            'content'    => $content,
            'is_secret'  => (int) $this->request->getPost('is_secret') === 1 ? 1 : 0,
        ]);

        return $this->response->setJSON(['success' => true, 'message' => '문의가 등록되었습니다.']);
    }

    /** POST /shop/:slug/qna/:id/delete */
    public function qnaDelete(string $slug, int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return $this->response->setJSON(['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $qna = $this->qnaModel->where('id', $id)->where('user_id', $userId)->first();
        if (! $qna) {
            return $this->response->setJSON(['success' => false, 'message' => '삭제할 수 없습니다.']);
        }

        $this->qnaModel->delete($id);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /shop/:slug/review */
    public function reviewStore(string $slug): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return $this->response->setJSON(['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $product = $this->productModel->where('slug', $slug)->whereIn('status', ['on_sale', 'sold_out'])->first();
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $orderId = $this->reviewModel->canWriteReview($userId, $product['id']);
        if ($orderId === null) {
            return $this->response->setJSON(['success' => false, 'message' => '구매 확정된 상품에만 리뷰를 작성할 수 있습니다.']);
        }

        $content = trim($this->request->getPost('content') ?? '');
        if ($content === '') {
            return $this->response->setJSON(['success' => false, 'message' => '리뷰 내용을 입력해주세요.']);
        }

        // 이미지 업로드 (최대 3장)
        $uploadedImages = [];
        $uploadFiles    = $this->request->getFiles();
        if (isset($uploadFiles['images'])) {
            $fileList = is_array($uploadFiles['images']) ? $uploadFiles['images'] : [$uploadFiles['images']];
            foreach (array_slice($fileList, 0, 3) as $file) {
                if (! ($file instanceof \CodeIgniter\HTTP\Files\UploadedFile)) continue;
                if (! $file->isValid() || $file->hasMoved()) continue;
                if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) continue;
                if ($file->getSize() > 5 * 1024 * 1024) continue;
                $name = $file->getRandomName();
                $dir  = FCPATH . 'uploads/reviews/';
                if (! is_dir($dir)) mkdir($dir, 0755, true);
                if ($file->move($dir, $name)) {
                    $uploadedImages[] = '/uploads/reviews/' . $name;
                }
            }
        }

        $reviewId = $this->reviewModel->insert([
            'product_id'  => $product['id'],
            'order_id'    => $orderId,
            'user_id'     => $userId,
            'content'     => $content,
            'is_rewarded' => 0,
        ]);

        if ($reviewId === false || $reviewId === 0) {
            return $this->response->setJSON(['success' => false, 'message' => '리뷰 등록에 실패했습니다.']);
        }

        $reviewId = (int) $reviewId;
        $sort     = 0;
        foreach ($uploadedImages as $path) {
            $this->reviewModel->db->table('product_review_images')->insert([
                'review_id'  => $reviewId,
                'image_path' => $path,
                'sort_order' => $sort++,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // AI 리뷰 요약 재생성 (백그라운드 워커가 처리)
        \App\Libraries\AiProvider\ReviewSummaryHandler::enqueue((int) $product['id']);

        if (mb_strlen($content) >= 30 && count($uploadedImages) >= 1) {
            $this->reviewModel->grantPoints($reviewId, $userId);
            return $this->response->setJSON(['success' => true, 'message' => '리뷰가 등록되었습니다. 150 포인트가 적립되었습니다!']);
        }

        return $this->response->setJSON(['success' => true, 'message' => '리뷰가 등록되었습니다.']);
    }

    /** POST /shop/:slug/review/:id/delete */
    public function reviewDelete(string $slug, int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $userId = (int) session()->get('user_id');
        if (! $userId) {
            return $this->response->setJSON(['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        if (! $this->reviewModel->deleteReview($id, $userId)) {
            return $this->response->setJSON(['success' => false, 'message' => '삭제할 수 없습니다.']);
        }

        // AI 리뷰 요약 재생성
        $product = $this->productModel->where('slug', $slug)->first();
        if ($product) {
            \App\Libraries\AiProvider\ReviewSummaryHandler::enqueue((int) $product['id']);
        }

        return $this->response->setJSON(['success' => true]);
    }

    public function index(): string
    {
        $params = [
            'keyword'      => $this->request->getGet('keyword'),
            'category_id'  => $this->request->getGet('category_id'),
            'sort'         => $this->request->getGet('sort'),
            'page'         => $this->request->getGet('page'),
            'per_page'     => 12,
            'price_min'    => $this->request->getGet('price_min'),
            'price_max'    => $this->request->getGet('price_max'),
            'only_discount'=> $this->request->getGet('only_discount'),
        ];

        // 시맨틱 검색: 검색어를 AI로 확장해 재현율 향상 (AI 미설정 시 일반 검색)
        $expandedTerms = [];
        if (! empty($params['keyword'])) {
            $expandedTerms = (new \App\Libraries\SemanticSearchService())->expand((string) $params['keyword']);
            $params['expanded_terms'] = $expandedTerms;
        }

        $result = $this->productModel->getList($params);

        $this->imageModel->attachPrimaryImages($result['items']);

        // 현재 페이지 상품에 대한 찜 여부 (로그인 사용자)
        $wishedIds = [];
        $userId    = (int) session()->get('user_id');
        if ($userId > 0 && ! empty($result['items'])) {
            $productIds = array_column($result['items'], 'id');
            $rows       = $this->wishlistModel
                ->select('product_id')
                ->where('user_id', $userId)
                ->whereIn('product_id', $productIds)
                ->findAll();
            $wishedIds = array_map('intval', array_column($rows, 'product_id'));
        }

        // 로그인 회원 개인화 추천 (필터·검색 없는 목록 1페이지에서만)
        $recommended = [];
        $noFilter    = empty($params['keyword']) && (int) $params['category_id'] === 0 && empty($params['only_discount']);
        if ($userId > 0 && (int) ($params['page'] ?? 1) <= 1 && $noFilter) {
            $recommended = (new \App\Libraries\RecommendationService())->forUser($userId, 8);
        }

        return $this->render('shop/list', array_merge($result, [
            'tree'         => $this->categoryModel->getTree(),
            'keyword'      => $params['keyword'],
            'curCat'       => (int) $params['category_id'],
            'curSort'      => $params['sort'],
            'priceMin'     => $params['price_min'] ?? '',
            'priceMax'     => $params['price_max'] ?? '',
            'onlyDiscount' => (bool) $params['only_discount'],
            'wishedIds'    => $wishedIds,
            'recommended'  => $recommended,
            'expandedTerms'=> $expandedTerms,
        ]));
    }

    /** POST /shop/(:segment)/restock-alert */
    public function restockAlert(string $slug): \CodeIgniter\HTTP\ResponseInterface
    {
        $product = $this->productModel->where('slug', $slug)->where('deleted_at IS NULL', null, false)->first();
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $isSoldOut = $product['status'] === 'sold_out' || (int) $product['stock'] === 0;
        if (! $isSoldOut) {
            return $this->response->setJSON(['success' => false, 'message' => '현재 구매 가능한 상품입니다.']);
        }

        $userId = (int) session()->get('user_id');
        if ($userId > 0) {
            $user  = db_connect()->table('users')->select('email')->where('id', $userId)->get()->getRowArray();
            $email = $user['email'] ?? '';
        } else {
            $email = trim($this->request->getPost('email') ?? '');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setJSON(['success' => false, 'message' => '유효한 이메일 주소를 입력해주세요.']);
        }

        if ($this->restockModel->exists((int) $product['id'], $email)) {
            return $this->response->setJSON(['success' => true, 'already' => true, 'message' => '이미 재입고 알림이 신청되어 있습니다.']);
        }

        $this->restockModel->insert([
            'product_id' => (int) $product['id'],
            'user_id'    => $userId ?: null,
            'email'      => $email,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'success'   => true,
            'message'   => '재입고 시 이메일로 알려드리겠습니다.',
            'csrf_hash' => csrf_hash(),
        ]);
    }
}
