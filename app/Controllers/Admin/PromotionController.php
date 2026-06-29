<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ImageUploader;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use App\Models\PromotionModel;

class PromotionController extends BaseController
{
    private PromotionModel $model;

    public function __construct()
    {
        $this->model = new PromotionModel();
    }

    /** GET /admin/promotions */
    public function index(): string
    {
        return $this->render('admin/promotions/list', [
            'promotions' => $this->model->getList(),
        ]);
    }

    /** GET /admin/promotions/create */
    public function create(): string
    {
        return $this->render('admin/promotions/form', [
            'promotion'  => null,
            'products'   => [],
            'categories' => (new CategoryModel())->findAll(),
        ]);
    }

    /** POST /admin/promotions/create */
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $data = $this->buildData();

        $file = $this->request->getFile('banner_image_file');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $result = (new ImageUploader('promotions'))->upload($file);
            if (! $result['success']) {
                return redirect()->back()->withInput()->with('error', $result['error']);
            }
            $data['banner_image'] = $result['path'];
        }

        $id = (int) $this->model->insert($data);
        if ($id > 0) {
            $this->model->syncProducts($id, $this->parseProducts());
        }
        return redirect()->to('/admin/promotions')->with('success', '기획전이 등록되었습니다.');
    }

    /** GET /admin/promotions/:id/edit */
    public function edit(int $id): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $promotion = $this->model->find($id);
        if (! $promotion) {
            return redirect()->to('/admin/promotions')->with('error', '기획전을 찾을 수 없습니다.');
        }
        return $this->render('admin/promotions/form', [
            'promotion'  => $promotion,
            'products'   => $this->model->getProducts($id),
            'categories' => (new CategoryModel())->findAll(),
        ]);
    }

    /** POST /admin/promotions/:id/edit */
    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $promotion = $this->model->find($id);
        if (! $promotion) {
            return redirect()->to('/admin/promotions')->with('error', '기획전을 찾을 수 없습니다.');
        }

        $data = $this->buildData();
        $data['banner_image'] = $promotion['banner_image'];

        $file = $this->request->getFile('banner_image_file');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $result = (new ImageUploader('promotions'))->upload($file);
            if (! $result['success']) {
                return redirect()->back()->withInput()->with('error', $result['error']);
            }
            if ($promotion['banner_image'] && file_exists(FCPATH . $promotion['banner_image'])) {
                unlink(FCPATH . $promotion['banner_image']);
            }
            $data['banner_image'] = $result['path'];
        }

        $this->model->update($id, $data);
        $this->model->syncProducts($id, $this->parseProducts());
        return redirect()->to('/admin/promotions')->with('success', '기획전이 수정되었습니다.');
    }

    /** POST /admin/promotions/:id/delete */
    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->model->db->table('promotion_products')->where('promotion_id', $id)->delete();
        $this->model->delete($id);
        return redirect()->to('/admin/promotions')->with('success', '기획전이 삭제되었습니다.');
    }

    /** GET /admin/promotions/product-search — AJAX */
    public function productSearch(): \CodeIgniter\HTTP\ResponseInterface
    {
        $q     = trim($this->request->getGet('q') ?? '');
        $catId = $this->request->getGet('category_id');

        $builder = db_connect()->table('products')
            ->select('products.id, products.name, products.price, products.discount_price, products.stock, products.status, media.file_path AS primary_image')
            ->join('product_images', 'product_images.product_id = products.id AND product_images.is_primary = 1', 'left')
            ->join('media', 'media.id = product_images.media_id', 'left')
            ->where('products.deleted_at IS NULL', null, false);

        if ($q !== '') {
            $builder->groupStart()
                    ->like('products.name', $q)
                    ->orLike('products.description', $q)
                    ->groupEnd();
        }
        if ($catId) {
            $catId = (int) $catId;
            $builder->where("EXISTS (SELECT 1 FROM product_categories pc2
                WHERE pc2.product_id = products.id AND pc2.category_id = {$catId})", null, false);
        }

        $products = $builder->orderBy('products.id', 'DESC')->limit(30)->get()->getResultArray();

        foreach ($products as &$p) {
            $p['primary_image'] = $p['primary_image'] ? base_url($p['primary_image']) : null;
        }

        return $this->response->setJSON(['products' => $products]);
    }

    // ── private ──────────────────────────────────────────────────────────────

    private function buildData(): array
    {
        $startDate = $this->request->getPost('start_date');
        $endDate   = $this->request->getPost('end_date');
        return [
            'title'        => trim($this->request->getPost('title') ?? ''),
            'slug'         => trim($this->request->getPost('slug')  ?? ''),
            'description'  => $this->request->getPost('description') ?? '',
            'banner_image' => null,
            'grade_access' => $this->request->getPost('grade_access') ?? 'all',
            'start_date'   => $startDate !== '' ? $startDate : null,
            'end_date'     => $endDate   !== '' ? $endDate   : null,
            'is_active'    => (int) $this->request->getPost('is_active'),
            'sort_order'   => (int) $this->request->getPost('sort_order'),
        ];
    }

    /** products_json → array of {product_id, sort_order} */
    private function parseProducts(): array
    {
        $json = $this->request->getPost('products_json') ?? '[]';
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
