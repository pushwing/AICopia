<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Libraries\Mailer;
use App\Libraries\NaverShoppingProvider;
use App\Models\MediaModel;
use App\Libraries\MediaUploader;
use App\Models\CategoryModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Models\ProductSkuModel;
use App\Models\RestockAlertModel;
use App\Models\StockLogModel;
use Config\Database;

class ProductController extends BaseController
{
    private ProductModel      $productModel;
    private CategoryModel     $categoryModel;
    private ProductImageModel $imageModel;
    private ProductSkuModel   $skuModel;

    public function __construct()
    {
        $this->productModel  = new ProductModel();
        $this->categoryModel = new CategoryModel();
        $this->imageModel    = new ProductImageModel();
        $this->skuModel      = new ProductSkuModel();
    }

    public function index(): string
    {
        $lowStockThreshold = (int) ($this->viewData['settings']['low_stock_threshold'] ?? 5);

        $params = [
            'keyword'             => $this->request->getGet('keyword'),
            'status'              => $this->request->getGet('status'),
            'stock'               => $this->request->getGet('stock'),
            'page'                => $this->request->getGet('page'),
            'low_stock_threshold' => $lowStockThreshold,
        ];
        $result = $this->productModel->getAdminList($params);

        $this->imageModel->attachPrimaryImages($result['items']);

        $lowStockCount = $this->productModel
            ->where('stock <=', $lowStockThreshold)
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();

        $db = db_connect();
        $unassignedCount = (int) $db->table('products')
            ->where('deleted_at IS NULL')
            ->where("NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = products.id)", null, false)
            ->countAllResults();

        return $this->render('admin/products/list', array_merge($result, [
            'statuses'          => ProductModel::STATUSES,
            'keyword'           => $params['keyword'],
            'curStatus'         => $params['status'],
            'curStock'          => $params['stock'],
            'lowStockThreshold' => $lowStockThreshold,
            'lowStockCount'     => $lowStockCount,
            'unassignedCount'   => $unassignedCount,
        ]));
    }

    /** GET /admin/products/json */
    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db        = \Config\Database::connect();
        $rows      = $db->table('products')
            ->select("products.id, products.name, products.slug, products.price, products.discount_price,
                      products.stock, products.status, products.is_featured, products.created_at,
                      (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR ', ')
                       FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                       WHERE pc.product_id = products.id) AS category_name")
            ->where('products.deleted_at IS NULL')
            ->orderBy('products.id', 'DESC')
            ->get()->getResultArray();
        $this->imageModel->attachPrimaryImages($rows);
        $threshold = (int) ($this->viewData['settings']['low_stock_threshold'] ?? 5);

        $data = array_map(fn($p) => [
            'id'             => (int) $p['id'],
            'name'           => $p['name'],
            'slug'           => $p['slug'],
            'category_name'  => $p['category_name'] ?? '',
            'price'          => (int) $p['price'],
            'discount_price' => $p['discount_price'] ? (int) $p['discount_price'] : 0,
            'stock'          => (int) $p['stock'],
            'status'         => $p['status'],
            'primary_image'  => $p['primary_image'] ?? '',
            'created_at'     => $p['created_at'],
            'is_low_stock'   => (int) ($p['stock'] <= $threshold),
            'is_featured'    => (int) $p['is_featured'],
        ], $rows);

        return $this->response->setJSON(['data' => $data]);
    }

    /** POST /admin/products/bulk — 상품 일괄 편집 (상태·재고·삭제) */
    public function bulk(): \CodeIgniter\HTTP\RedirectResponse
    {
        $ids    = array_values(array_filter(array_map('intval', (array) $this->request->getPost('ids'))));
        $action = $this->request->getPost('action');

        if (empty($ids)) {
            return redirect()->back()->with('error', '상품을 선택해주세요.');
        }

        $db = \Config\Database::connect();

        switch ($action) {
            case 'status':
                $status = $this->request->getPost('status');
                if (! array_key_exists($status, ProductModel::STATUSES)) {
                    return redirect()->back()->with('error', '올바른 상태 값이 아닙니다.');
                }
                $db->table('products')
                   ->whereIn('id', $ids)
                   ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
                return redirect()->back()->with('success', count($ids) . '개 상품 상태가 변경되었습니다.');

            case 'stock':
                $stock = $this->request->getPost('stock');
                if (! is_numeric($stock) || (int) $stock < 0) {
                    return redirect()->back()->with('error', '올바른 재고 수량을 입력해주세요.');
                }
                $stock    = (int) $stock;
                $adminId  = (int) session()->get('user_id');
                $logModel = new StockLogModel();
                foreach ($ids as $id) {
                    $product = $this->productModel->find($id);
                    if (! $product) continue;
                    $oldStock = (int) $product['stock'];
                    $this->productModel->update($id, ['stock' => $stock]);
                    $logModel->record($id, 'adjust', abs($stock - $oldStock), $oldStock, $stock, '관리자 일괄 재고 조정', $adminId);
                }
                return redirect()->back()->with('success', count($ids) . '개 상품 재고가 변경되었습니다.');

            case 'price_discount':
                $discountType  = $this->request->getPost('discount_type');
                $discountValue = $this->request->getPost('discount_value');

                if ($discountType === 'clear') {
                    $db->table('products')
                       ->whereIn('id', $ids)
                       ->update(['discount_price' => null, 'updated_at' => date('Y-m-d H:i:s')]);
                    return redirect()->back()->with('success', count($ids) . '개 상품 할인가가 초기화되었습니다.');
                }

                if (! in_array($discountType, ['percent', 'fixed'], true)
                    || ! is_numeric($discountValue)
                    || (int) $discountValue < 0) {
                    return redirect()->back()->with('error', '올바른 할인 값을 입력해주세요.');
                }

                $discountValue = (int) $discountValue;
                foreach ($ids as $id) {
                    $product = $this->productModel->find($id);
                    if (! $product) continue;
                    $price         = (int) $product['price'];
                    $discountPrice = $discountType === 'percent'
                        ? (int) round($price * (1 - $discountValue / 100))
                        : max(0, $price - $discountValue);
                    $this->productModel->update($id, ['discount_price' => $discountPrice, 'updated_at' => date('Y-m-d H:i:s')]);
                }
                return redirect()->back()->with('success', count($ids) . '개 상품에 할인가가 적용되었습니다.');

            case 'delete':
                foreach ($ids as $id) {
                    $this->productModel->delete($id);
                }
                return redirect()->back()->with('success', count($ids) . '개 상품이 삭제되었습니다.');

            default:
                return redirect()->back()->with('error', '올바른 액션이 아닙니다.');
        }
    }

    /** POST /admin/products/:id/stock — 인라인 재고 수정 */
    public function updateStock(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $product = $this->productModel->find($id);
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }

        $newStock = $this->request->getPost('stock');
        if (! is_numeric($newStock) || (int) $newStock < 0) {
            return $this->response->setJSON(['success' => false, 'message' => '올바른 재고 수량을 입력해주세요.']);
        }

        $newStock = (int) $newStock;
        $oldStock = (int) $product['stock'];
        $adminId  = (int) session()->get('user_id');

        $this->productModel->update($id, ['stock' => $newStock]);

        (new StockLogModel())->record(
            $id, 'adjust',
            abs($newStock - $oldStock),
            $oldStock, $newStock,
            '관리자 재고 조정',
            $adminId
        );

        if ($oldStock === 0 && $newStock > 0 && $product['status'] !== 'sold_out') {
            $this->dispatchRestockAlerts($this->productModel->find($id));
        }

        return $this->response->setJSON(['success' => true, 'stock' => $newStock]);
    }

    public function toggleFeatured(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $product = $this->productModel->find($id);
        if (! $product) {
            return $this->response->setJSON(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        }
        $newVal = $product['is_featured'] ? 0 : 1;
        $this->productModel->update($id, ['is_featured' => $newVal]);
        return $this->response->setJSON(['success' => true, 'is_featured' => $newVal]);
    }

    public function create(): string
    {
        return $this->render('admin/products/form', [
            'product'        => null,
            'categoryIds'    => [],
            'images'         => [],
            'tree'           => $this->categoryModel->getTree(),
            'statuses'       => ProductModel::STATUSES,
            'shippings'      => ProductModel::SHIPPING_TYPES,
            'suppliers'      => Database::connect()->table('suppliers')->orderBy('name')->get()->getResultArray(),
            'optionsAndSkus' => ['options' => [], 'skus' => []],
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! $this->validate($this->validationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->collectData();
        $id   = $this->productModel->insert($data);

        $this->handleCategories($id);
        $this->handleImages($id);
        $this->handleOptions($id);

        return redirect()->to('/admin/products')->with('success', '상품이 등록되었습니다.');
    }

    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $product = $this->productModel->find($id);
        if (! $product) return redirect()->to('/admin/products')->with('error', '상품을 찾을 수 없습니다.');

        return $this->render('admin/products/form', [
            'product'        => $product,
            'categoryIds'    => $this->productModel->getCategories($id),
            'images'         => $this->imageModel->getByProduct($id),
            'tree'           => $this->categoryModel->getTree(),
            'statuses'       => ProductModel::STATUSES,
            'shippings'      => ProductModel::SHIPPING_TYPES,
            'suppliers'      => Database::connect()->table('suppliers')->orderBy('name')->get()->getResultArray(),
            'optionsAndSkus' => $this->skuModel->getOptionsAndSkus($id),
        ]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $product = $this->productModel->find($id);
        if (! $product) return redirect()->to('/admin/products')->with('error', '상품을 찾을 수 없습니다.');

        if (! $this->validate($this->validationRules($id))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $wasOutOfStock = (int) $product['stock'] === 0 || $product['status'] === 'sold_out';

        $this->productModel->update($id, $this->collectData($id));
        $this->handleCategories($id);
        $this->handleImages($id);
        $this->handleOptions($id);

        if ($wasOutOfStock) {
            $updated = $this->productModel->find($id);
            if ($updated && (int) $updated['stock'] > 0 && $updated['status'] !== 'sold_out') {
                $this->dispatchRestockAlerts($updated);
            }
        }

        return redirect()->to('/admin/products')->with('success', '저장되었습니다.');
    }

    private function dispatchRestockAlerts(array $product): void
    {
        $alertModel = new RestockAlertModel();
        $pending    = $alertModel->getPending((int) $product['id']);
        if (! $pending) return;

        $settings = model('SettingModel')->getAllAsMap();
        $mailer   = new Mailer($settings);

        // 상품당 1회만 AI 개인화 문구 생성·캐시 → 모든 수신자에 재사용 (키 없으면 기본 문구)
        $aiMessage = $this->restockAiMessage($product);

        foreach ($pending as $alert) {
            $mailer->sendRestockAlert($alert['email'], $product, $aiMessage);
        }
        $alertModel->markNotified((int) $product['id']);
    }

    /** 재입고 알림용 AI 개인화 문구 (상품별 캐시, 실패 시 null로 폴백). */
    private function restockAiMessage(array $product): ?string
    {
        try {
            $key = \App\Libraries\AiProvider\AiCache::key(
                'restock_msg',
                (string) $product['id'],
                md5((string) ($product['name'] ?? ''))
            );
            $message = \App\Libraries\AiProvider\AiCache::remember(
                $key,
                fn () => AiCategoryAdvisor::create()->generateRestockMessage(
                    (string) ($product['name'] ?? ''),
                    (string) ($product['description'] ?? '')
                ),
                86400
            );
            return $message !== '' ? $message : null;
        } catch (\Throwable $e) {
            log_message('error', 'AiRestockMessage: ' . $e->getMessage());
            return null;
        }
    }

    public function copy(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $product = $this->productModel->find($id);
        if (! $product) return redirect()->to('/admin/products')->with('error', '상품을 찾을 수 없습니다.');

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $newName = $product['name'] . ' (복사)';
        $newSlug = $this->productModel->generateSlug($newName);

        $newId = $this->productModel->insert([
            'supplier_id'    => $product['supplier_id'],
            'name'           => $newName,
            'slug'           => $newSlug,
            'price'          => $product['price'],
            'cost_price'     => $product['cost_price'],
            'discount_price' => $product['discount_price'],
            'stock'          => 0,
            'status'         => 'hidden',
            'description'    => $product['description'],
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // 카테고리 복사
        $this->productModel->setCategories($newId, $this->productModel->getCategories($id));

        // 이미지 복사 (media_id 참조만 공유, 파일 복사 없음)
        foreach ($db->table('product_images')->where('product_id', $id)->get()->getResultArray() as $img) {
            $db->table('product_images')->insert([
                'product_id' => $newId,
                'media_id'   => $img['media_id'],
                'is_primary' => $img['is_primary'],
                'sort_order' => $img['sort_order'],
                'created_at' => $now,
            ]);
        }

        // 옵션 복사: options → option_values → skus → sku_values
        $optionIdMap      = [];
        $optionValueIdMap = [];
        foreach ($db->table('product_options')->where('product_id', $id)->get()->getResultArray() as $opt) {
            $db->table('product_options')->insert(['product_id' => $newId, 'name' => $opt['name'], 'sort_order' => $opt['sort_order']]);
            $newOptId                   = (int) $db->insertID();
            $optionIdMap[(int) $opt['id']] = $newOptId;

            foreach ($db->table('product_option_values')->where('option_id', $opt['id'])->get()->getResultArray() as $val) {
                $db->table('product_option_values')->insert(['option_id' => $newOptId, 'value' => $val['value'], 'sort_order' => $val['sort_order']]);
                $optionValueIdMap[(int) $val['id']] = (int) $db->insertID();
            }
        }

        foreach ($db->table('product_skus')->where('product_id', $id)->get()->getResultArray() as $sku) {
            $db->table('product_skus')->insert(['product_id' => $newId, 'price_diff' => $sku['price_diff'], 'stock' => 0, 'sku_code' => $sku['sku_code']]);
            $newSkuId = (int) $db->insertID();

            foreach ($db->table('product_sku_values')->where('sku_id', $sku['id'])->get()->getResultArray() as $sv) {
                $newValId = $optionValueIdMap[(int) $sv['option_value_id']] ?? null;
                if ($newValId) {
                    $db->table('product_sku_values')->insert(['sku_id' => $newSkuId, 'option_value_id' => $newValId]);
                }
            }
        }

        return redirect()->to("/admin/products/{$newId}/edit")->with('success', '상품이 복사되었습니다. 내용을 확인하고 저장해주세요.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $product = $this->productModel->find($id);
        if (! $product) return redirect()->to('/admin/products')->with('error', '상품을 찾을 수 없습니다.');

        $this->productModel->delete($id);
        return redirect()->to('/admin/products')->with('success', '삭제되었습니다.');
    }

    // ── 미분류 상품 일괄 카테고리 배정 ───────────────────────────────────────────

    public function unassigned(): string
    {
        $db             = db_connect();
        $perPage        = 50;
        $page           = max(1, (int) ($this->request->getGet('page') ?? 1));
        $onlyUnassigned = (bool) $this->request->getGet('only_unassigned');
        $keyword        = trim((string) ($this->request->getGet('keyword') ?? ''));

        $builder = $db->table('products')
            ->select("products.id, products.name, products.price, products.stock, products.status, products.created_at,
                (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order, c.id SEPARATOR ', ')
                 FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                 WHERE pc.product_id = products.id) AS category_names")
            ->where('products.deleted_at IS NULL')
            ->orderBy('products.id', 'DESC');

        if ($onlyUnassigned) {
            $builder->where("NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = products.id)", null, false);
        }
        if ($keyword !== '') {
            $builder->like('products.name', $keyword);
        }

        $total      = (clone $builder)->countAllResults();
        $offset     = ($page - 1) * $perPage;
        $items      = $builder->limit($perPage, $offset)->get()->getResultArray();
        $totalPages = (int) ceil($total / $perPage);

        $this->imageModel->attachPrimaryImages($items);

        $unassignedCount = (int) $db->table('products')
            ->where('deleted_at IS NULL')
            ->where("NOT EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = products.id)", null, false)
            ->countAllResults();

        return $this->render('admin/products/unassigned', [
            'items'           => $items,
            'total'           => $total,
            'totalPages'      => $totalPages,
            'currentPage'     => $page,
            'perPage'         => $perPage,
            'onlyUnassigned'  => $onlyUnassigned,
            'keyword'         => $keyword,
            'unassignedCount' => $unassignedCount,
            'tree'            => $this->categoryModel->getTree(),
            'statuses'        => ProductModel::STATUSES,
        ]);
    }

    public function assignCategory(): \CodeIgniter\HTTP\RedirectResponse
    {
        $productIds  = array_values(array_filter(array_map('intval', (array) $this->request->getPost('product_ids'))));
        $categoryIds = array_values(array_filter(array_map('intval', (array) $this->request->getPost('category_ids'))));

        if (empty($productIds)) {
            return redirect()->back()->with('error', '상품을 선택해주세요.');
        }
        if (empty($categoryIds)) {
            return redirect()->back()->with('error', '카테고리를 선택해주세요.');
        }

        foreach ($productIds as $pid) {
            // 기존 카테고리에 추가 (덮어쓰지 않고 병합)
            $existing = $this->productModel->getCategories($pid);
            $merged   = array_unique(array_merge($existing, $categoryIds));
            $this->productModel->setCategories($pid, $merged);
        }

        return redirect()->to('/admin/products/unassigned')
            ->with('success', count($productIds) . '개 상품에 카테고리가 적용되었습니다.');
    }

    /** POST /admin/products/suggest-category — AI 카테고리 추천 (AJAX) */
    public function suggestCategory(): \CodeIgniter\HTTP\ResponseInterface
    {
        $name        = trim((string) $this->request->getPost('name'));
        $description = trim((string) $this->request->getPost('description'));

        if ($name === '') {
            return $this->response->setJSON(['error' => '상품명을 먼저 입력해주세요.'])->setStatusCode(422);
        }

        try {
            $tree = $this->categoryModel->getTree();
            // 동일 상품명·설명 재요청 시 캐시 사용 (카테고리 구조 변경도 키에 반영)
            $ids  = \App\Libraries\AiProvider\AiCache::remember(
                \App\Libraries\AiProvider\AiCache::key('category', $name, $description, md5(json_encode($tree))),
                fn () => AiCategoryAdvisor::create()->suggestCategories($name, $description, $tree)
            );
            return $this->response->setJSON(['category_ids' => $ids]);
        } catch (AiKeyMissingException $e) {
            return $this->response->setJSON([
                'error'     => $e->getMessage(),
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            log_message('error', 'AiCategoryAdvisor: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 추천 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    /** POST /admin/products/import-image — 외부 URL 이미지 다운로드 → 미디어 라이브러리 저장 (AJAX) */
    public function importImage(): \CodeIgniter\HTTP\ResponseInterface
    {
        $url = trim((string) $this->request->getPost('url'));

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return $this->response->setJSON(['error' => '유효하지 않은 URL입니다.'])->setStatusCode(422);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            return $this->response->setJSON(['error' => '이미지 다운로드에 실패했습니다.'])->setStatusCode(500);
        }

        $mimeType = strtolower(explode(';', $mimeType)[0]);
        $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext      = $extMap[$mimeType] ?? null;

        if (! $ext) {
            // URL 확장자로 fallback
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? ($ext === 'jpeg' ? 'jpg' : $ext) : null;
        }

        if (! $ext) {
            return $this->response->setJSON(['error' => '지원하지 않는 이미지 형식입니다.'])->setStatusCode(422);
        }

        $subDir     = date('Y/m');
        $uploadPath = FCPATH . "uploads/media/{$subDir}";
        if (! is_dir($uploadPath)) mkdir($uploadPath, 0755, true);

        $storedName   = bin2hex(random_bytes(16)) . ".{$ext}";
        $relativePath = "uploads/media/{$subDir}/{$storedName}";
        $fullPath     = FCPATH . $relativePath;

        if (file_put_contents($fullPath, $raw) === false) {
            return $this->response->setJSON(['error' => '이미지 저장에 실패했습니다.'])->setStatusCode(500);
        }

        $mediaId = (new MediaModel())->insert([
            'original_name' => $storedName,
            'stored_name'   => $storedName,
            'file_path'     => $relativePath,
            'file_size'     => strlen($raw),
            'mime_type'     => $mimeType ?: "image/{$ext}",
            'alt'           => '',
        ]);

        return $this->response->setJSON([
            'success'  => true,
            'media_id' => $mediaId,
            'url'      => base_url($relativePath),
        ]);
    }

    /** GET /admin/products/naver-search — 네이버 쇼핑 상품 검색 (AJAX) */
    public function naverSearch(): \CodeIgniter\HTTP\ResponseInterface
    {
        $keyword = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));

        if ($keyword === '') {
            return $this->response->setJSON(['error' => '검색어를 입력해주세요.'])->setStatusCode(422);
        }

        $settings     = model('SettingModel')->getAllAsMap();
        $clientId     = $settings['naver_shopping_client_id']     ?? '';
        $clientSecret = $settings['naver_shopping_client_secret'] ?? '';

        if ($clientId === '' || $clientSecret === '') {
            return $this->response->setJSON([
                'error'      => 'API 키가 설정되지 않았습니다.',
                'setup_url'  => '/admin/settings/api',
                'setup_msg'  => '설정 > 외부 API에서 네이버 클라이언트 ID/Secret을 먼저 등록해주세요.',
            ])->setStatusCode(422);
        }

        $display = 10;
        $start   = ($page - 1) * $display + 1;

        try {
            $result = (new NaverShoppingProvider())->search($keyword, $display, $start);
            return $this->response->setJSON($result);
        } catch (\Throwable $e) {
            log_message('error', 'NaverShopping: ' . $e->getMessage());
            return $this->response->setJSON(['error' => '검색 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    /** POST /admin/products/generate-description — AI 상품 설명 생성 (AJAX) */
    public function generateDescription(): \CodeIgniter\HTTP\ResponseInterface
    {
        $name        = trim((string) $this->request->getPost('name'));
        $description = trim((string) $this->request->getPost('description'));

        if ($name === '') {
            return $this->response->setJSON(['error' => '상품명을 먼저 입력해주세요.'])->setStatusCode(422);
        }

        try {
            $generated = AiCategoryAdvisor::create()->generateDescription($name, $description);
            if ($generated === '') {
                return $this->response->setJSON(['error' => 'AI 응답이 비어있습니다. 잠시 후 다시 시도해주세요.'])->setStatusCode(500);
            }
            return $this->response->setJSON(['description' => $generated]);
        } catch (AiKeyMissingException $e) {
            return $this->response->setJSON([
                'error'     => $e->getMessage(),
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            log_message('error', 'AiDescriptionGenerator: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 설명 생성 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    /** POST /admin/products/extract-from-image — 이미지(Vision)로 상품명·설명 추출 (AJAX) */
    public function extractFromImage(): \CodeIgniter\HTTP\ResponseInterface
    {
        // Vision은 Claude 전용 — Anthropic 키가 있으면 ai_provider 설정과 무관하게 Claude 사용
        $settings = model('SettingModel')->getAllAsMap();
        $apiKey   = ($settings['anthropic_api_key'] ?? '') ?: env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            return $this->response->setJSON([
                'error'     => '이미지 분석(Vision)은 Claude(Anthropic) API 키가 필요합니다. 설정에서 등록해주세요.',
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        }

        $file = $this->request->getFile('image');
        if ($file === null || ! $file->isValid()) {
            return $this->response->setJSON(['error' => '이미지를 선택해주세요.'])->setStatusCode(422);
        }

        $mime    = $file->getMimeType();
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! in_array($mime, $allowed, true)) {
            return $this->response->setJSON(['error' => '지원하지 않는 형식입니다. (jpg, png, gif, webp)'])->setStatusCode(422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->response->setJSON(['error' => '이미지 용량은 5MB 이하만 가능합니다.'])->setStatusCode(422);
        }

        try {
            $bytes = file_get_contents($file->getTempName());
            if ($bytes === false) {
                return $this->response->setJSON(['error' => '이미지를 읽을 수 없습니다.'])->setStatusCode(500);
            }

            $result = (new \App\Libraries\AiProvider\ClaudeProvider())
                ->extractProductInfo(base64_encode($bytes), $mime);

            if ($result['name'] === '') {
                return $this->response->setJSON(['error' => '이미지에서 상품 정보를 추출하지 못했습니다. 다시 시도해주세요.'])->setStatusCode(500);
            }
            return $this->response->setJSON($result);
        } catch (\Throwable $e) {
            log_message('error', 'AiVision: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 이미지 분석 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    // ── 카테고리 CRUD ─────────────────────────────────────────────────────────

    public function categories(): string
    {
        return $this->render('admin/products/categories', [
            'tree' => $this->categoryModel->getTreeDirect(),
        ]);
    }

    public function categoryStore(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = ['name' => 'required|max_length[100]'];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $name     = $this->request->getPost('name');
        $parentId = $this->request->getPost('parent_id') ?: null;

        $this->categoryModel->insert([
            'parent_id'  => $parentId,
            'name'       => $name,
            'slug'       => $this->categoryModel->generateSlug($name),
            'sort_order' => (int) $this->request->getPost('sort_order'),
            'is_active'  => $this->request->getPost('is_active') ? 1 : 0,
        ]);

        return redirect()->to('/admin/products/categories')->with('success', '카테고리가 추가되었습니다.');
    }

    public function categoryUpdate(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $category = $this->categoryModel->find($id);
        if (! $category) return redirect()->to('/admin/products/categories')->with('error', '카테고리를 찾을 수 없습니다.');

        $rules = ['name' => 'required|max_length[100]'];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->categoryModel->update($id, [
            'parent_id'  => $this->request->getPost('parent_id') ?: null,
            'name'       => $this->request->getPost('name'),
            'sort_order' => (int) $this->request->getPost('sort_order'),
            'is_active'  => $this->request->getPost('is_active') ? 1 : 0,
        ]);

        return redirect()->to('/admin/products/categories')->with('success', '저장되었습니다.');
    }

    public function categoryDelete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $hasProducts = db_connect()->table('product_categories')->where('category_id', $id)->countAllResults();
        if ($hasProducts) {
            return redirect()->to('/admin/products/categories')->with('error', '해당 카테고리에 상품이 있어 삭제할 수 없습니다.');
        }
        $hasChildren = $this->categoryModel->where('parent_id', $id)->countAllResults();
        if ($hasChildren) {
            return redirect()->to('/admin/products/categories')->with('error', '하위 카테고리가 있어 삭제할 수 없습니다.');
        }

        $this->categoryModel->delete($id);
        return redirect()->to('/admin/products/categories')->with('success', '삭제되었습니다.');
    }

    public function categoryMove(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $direction = $this->request->getPost('direction');
        $current   = $this->categoryModel->find($id);
        if (! $current || ! in_array($direction, ['up', 'down'], true)) {
            return $this->response->setJSON(['ok' => false]);
        }

        $db = db_connect();
        $builder = $db->table('categories');

        if (empty($current['parent_id'])) {
            $builder->where('parent_id IS NULL', null, false);
        } else {
            $builder->where('parent_id', $current['parent_id']);
        }

        // sort_order 중복 대비: id를 보조 정렬 키로 사용
        $siblings = $builder->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')
                            ->get()->getResultArray();

        $currentIdx = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) { $currentIdx = $i; break; }
        }

        if ($currentIdx === null) {
            return $this->response->setJSON(['ok' => false]);
        }

        $swapIdx = $direction === 'up' ? $currentIdx - 1 : $currentIdx + 1;

        if ($swapIdx < 0 || $swapIdx >= count($siblings)) {
            return $this->response->setJSON(['ok' => false]);
        }

        // 배열에서 위치 교환 후 sort_order 재정규화 (0, 1, 2, …)
        [$siblings[$currentIdx], $siblings[$swapIdx]] = [$siblings[$swapIdx], $siblings[$currentIdx]];

        foreach ($siblings as $i => $s) {
            $this->categoryModel->update((int) $s['id'], ['sort_order' => $i]);
        }

        return $this->response->setJSON(['ok' => true]);
    }

    public function categoryPublish(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->categoryModel->clearCache();
        return redirect()->to('/admin/products/categories')->with('success', '카테고리가 쇼핑몰에 적용되었습니다.');
    }

    // ── 이미지 삭제 (Ajax) ────────────────────────────────────────────────────

    public function imageDelete(int $imageId): \CodeIgniter\HTTP\ResponseInterface
    {
        $image = $this->imageModel->find($imageId);
        if (! $image) {
            return $this->response->setJSON(['success' => false, 'error' => '이미지를 찾을 수 없습니다.']);
        }

        $this->imageModel->delete($imageId);
        return $this->response->setJSON(['success' => true]);
    }

    // ── private 헬퍼 ─────────────────────────────────────────────────────────

    private function validationRules(?int $excludeId = null): array
    {
        $slugRule = 'required|max_length[220]|is_unique[products.slug' . ($excludeId ? ",id,{$excludeId}" : '') . ']';
        return [
            'name'       => 'required|max_length[200]',
            'slug'       => $slugRule,
            'price'      => 'required|integer|greater_than_equal_to[0]',
            'stock'      => 'required|integer|greater_than_equal_to[0]',
            'cost_price' => 'permit_empty|decimal|greater_than_equal_to[0]',
        ];
    }

    private function collectData(?int $productId = null): array
    {
        $name = $this->request->getPost('name');
        $slug = $this->request->getPost('slug') ?: $this->productModel->generateSlug($name, $productId);

        return [
            'supplier_id'    => $this->request->getPost('supplier_id') ?: null,
            'name'           => $name,
            'slug'           => $slug,
            'price'          => (int) $this->request->getPost('price'),
            'cost_price'     => (float) $this->request->getPost('cost_price'),
            'discount_price' => $this->request->getPost('discount_price') !== '' ? (int) $this->request->getPost('discount_price') : null,
            'stock'          => (int) $this->request->getPost('stock'),
            'status'         => $this->request->getPost('status'),
            'description'    => $this->request->getPost('description'),
            'shipping_type'  => $this->request->getPost('shipping_type'),
            'shipping_fee'   => (int) $this->request->getPost('shipping_fee'),
            'free_threshold' => (int) $this->request->getPost('free_threshold'),
        ];
    }

    private function handleCategories(int $productId): void
    {
        $ids = array_filter(array_map('intval', (array) $this->request->getPost('category_ids')));
        $this->productModel->setCategories($productId, array_values($ids));
    }

    private function handleOptions(int $productId): void
    {
        $json = $this->request->getPost('options_json');
        if (! $json) {
            // 옵션 JSON이 없으면 기존 옵션 전체 삭제 (옵션 제거)
            $this->skuModel->deleteByProduct($productId);
            return;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) return;

        $this->skuModel->saveOptionsAndSkus($productId, $data);
    }

    private function handleImages(int $productId): void
    {
        $uploader   = new MediaUploader();
        $files      = $this->request->getFiles();
        $newImages  = $files['images'] ?? [];
        $existCount = $this->imageModel->where('product_id', $productId)->countAllResults();

        // 네이버 검색 등 외부 임포트 이미지 (media_id 직접 지정)
        $importedIds = array_values(array_filter(
            array_map('intval', (array) $this->request->getPost('imported_media_ids'))
        ));
        foreach ($importedIds as $mediaId) {
            $this->imageModel->insert([
                'product_id' => $productId,
                'media_id'   => $mediaId,
                'is_primary' => $existCount === 0 ? 1 : 0,
                'sort_order' => $existCount,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $existCount++;
        }

        foreach ($newImages as $file) {
            if (! $file->isValid() || $file->hasMoved()) continue;

            $result = $uploader->upload($file, $this->request->getPost('name'));
            if (! $result['success']) continue;

            $this->imageModel->insert([
                'product_id' => $productId,
                'media_id'   => $result['id'],
                'is_primary' => $existCount === 0 ? 1 : 0,
                'sort_order' => $existCount,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $existCount++;
        }

        // 대표 이미지 변경 요청
        $primaryMediaId = $this->request->getPost('primary_media_id');
        if ($primaryMediaId) {
            $this->imageModel->setPrimary($productId, (int) $primaryMediaId);
        }
    }

    // ── 엑셀 일괄 등록 ──────────────────────────────────────────────────────────

    /** GET /admin/products/import-template */
    public function importTemplate(): \CodeIgniter\HTTP\ResponseInterface
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $col = fn(int $c, int $r): string =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;

        $headers = ['상품명*', '판매가*', '재고*', '상태', '배송유형', '배송비', '무료배송기준금액', '할인가', '카테고리', '상품설명'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($col($i + 1, 1), $h);
        }

        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType'    => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                       'startColor'  => ['argb' => 'FFE9ECEF']],
            'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ]);

        $example = ['예시상품', 10000, 100, 'on_sale', 'free', 0, 0, '', '', '상품 설명'];
        foreach ($example as $i => $v) {
            $sheet->setCellValue($col($i + 1, 2), $v);
        }

        foreach (range(1, 10) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="product_import_template.xlsx"')
            ->setBody($content);
    }

    /** GET /admin/products/import */
    public function import(): string
    {
        return $this->render('admin/products/import', [
            'preview'     => session()->getFlashdata('import_preview')  ?? [],
            'importErrors'=> session()->getFlashdata('import_errors')   ?? [],
            'validCount'  => session()->getFlashdata('import_valid_count') ?? 0,
        ]);
    }

    /** POST /admin/products/import */
    public function importProcess(): \CodeIgniter\HTTP\RedirectResponse
    {
        $file = $this->request->getFile('excel_file');

        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', '파일을 선택해주세요.');
        }

        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            return redirect()->back()->with('error', 'Excel 파일(.xlsx, .xls)만 허용됩니다.');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getTempName());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', '파일을 읽을 수 없습니다: ' . $e->getMessage());
        }

        $sheet  = $spreadsheet->getActiveSheet();
        $maxRow = $sheet->getHighestDataRow();

        $catRows = Database::connect()->table('categories')->select('id, name')->get()->getResultArray();
        $catMap  = [];
        foreach ($catRows as $c) {
            $catMap[trim($c['name'])] = (int) $c['id'];
        }

        $valid       = [];
        $importErrors= [];

        for ($row = 2; $row <= $maxRow; $row++) {
            $cells = [];
            for ($c = 1; $c <= 10; $c++) {
                $cells[] = trim((string) ($sheet->getCell([$c, $row])->getValue() ?? ''));
            }
            if (implode('', $cells) === '') continue;

            $parsed = $this->parseImportRow($cells, $catMap);

            if ($parsed['errors']) {
                $importErrors[] = ['row' => $row, 'data' => $cells, 'messages' => $parsed['errors']];
            } else {
                $valid[] = $parsed['data'];
            }
        }

        if (empty($valid) && empty($importErrors)) {
            return redirect()->back()->with('error', '데이터가 없습니다. 2행부터 입력해주세요.');
        }

        session()->set('product_import_valid', $valid);

        return redirect()->to('/admin/products/import')
            ->with('import_preview',     $valid)
            ->with('import_errors',      $importErrors)
            ->with('import_valid_count', (string) count($valid));
    }

    /** POST /admin/products/import/confirm */
    public function importConfirm(): \CodeIgniter\HTTP\RedirectResponse
    {
        $valid = session()->get('product_import_valid');

        if (empty($valid)) {
            return redirect()->to('/admin/products/import')->with('error', '가져올 데이터가 없습니다. 다시 업로드해주세요.');
        }

        $inserted = 0;
        foreach ($valid as $row) {
            $slug      = $this->productModel->generateSlug($row['name']);
            $productData = [
                'name'          => $row['name'],
                'slug'          => $slug,
                'price'         => $row['price'],
                'stock'         => $row['stock'],
                'status'        => $row['status'],
                'shipping_type' => $row['shipping_type'],
                'shipping_fee'  => $row['shipping_fee'],
                'free_threshold'=> $row['free_threshold'],
                'description'   => $row['description'],
            ];
            if ($row['discount_price'] !== null) {
                $productData['discount_price'] = $row['discount_price'];
            }

            $productId = $this->productModel->insert($productData);

            if ($row['category_id']) {
                $this->productModel->setCategories((int) $productId, [$row['category_id']]);
            }

            $inserted++;
        }

        session()->remove('product_import_valid');

        return redirect()->to('/admin/products')->with('success', "{$inserted}개 상품이 일괄 등록되었습니다.");
    }

    public function parseImportRow(array $cells, array $catMap): array
    {
        [$name, $price, $stock, $status, $shippingType, $shippingFee, $freeThreshold, $discountPrice, $categoryName, $description]
            = array_pad($cells, 10, '');

        $errors = [];
        $data   = [];

        // 상품명
        if ($name === '') {
            $errors[] = '상품명은 필수입니다.';
        } else {
            $data['name'] = $name;
        }

        // 판매가
        if (! is_numeric($price) || (int) $price <= 0) {
            $errors[] = '판매가는 0보다 큰 정수여야 합니다.';
        } else {
            $data['price'] = (int) $price;
        }

        // 재고
        if (! is_numeric($stock) || (int) $stock < 0) {
            $errors[] = '재고는 0 이상의 정수여야 합니다.';
        } else {
            $data['stock'] = (int) $stock;
        }

        // 상태
        $statusVal = $status !== '' ? $status : 'on_sale';
        if (! array_key_exists($statusVal, ProductModel::STATUSES)) {
            $errors[] = '상태는 on_sale/sold_out/hidden 중 하나여야 합니다.';
        } else {
            $data['status'] = $statusVal;
        }

        // 배송유형
        $shippingTypeVal = $shippingType !== '' ? $shippingType : 'free';
        if (! array_key_exists($shippingTypeVal, ProductModel::SHIPPING_TYPES)) {
            $errors[] = '배송유형은 free/fixed/conditional 중 하나여야 합니다.';
        } else {
            $data['shipping_type'] = $shippingTypeVal;
        }

        // 배송비
        $feeVal = $shippingFee !== '' ? (int) $shippingFee : 0;
        $data['shipping_fee'] = max(0, $feeVal);

        // 무료배송기준
        $thresholdVal = $freeThreshold !== '' ? (int) $freeThreshold : 0;
        $data['free_threshold'] = max(0, $thresholdVal);

        // 할인가
        if ($discountPrice !== '') {
            if (! is_numeric($discountPrice) || (int) $discountPrice <= 0) {
                $errors[] = '할인가는 0보다 큰 정수여야 합니다.';
            } else {
                $data['discount_price'] = (int) $discountPrice;
            }
        } else {
            $data['discount_price'] = null;
        }

        // 카테고리
        $data['category_id'] = $categoryName !== '' ? ($catMap[$categoryName] ?? null) : null;

        // 설명
        $data['description'] = $description;

        return ['errors' => $errors, 'data' => $errors ? [] : $data];
    }
}
