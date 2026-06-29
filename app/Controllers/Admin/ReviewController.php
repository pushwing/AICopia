<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ProductReviewModel;

class ReviewController extends BaseController
{
    private ProductReviewModel $model;

    public function __construct()
    {
        $this->model = new ProductReviewModel();
    }

    /** GET /admin/reviews */
    public function index(): string
    {
        $result = $this->model->adminGetAll([
            'keyword' => trim($this->request->getGet('q') ?? ''),
            'page'    => $this->request->getGet('page') ?? 1,
        ]);

        return $this->render('admin/reviews/list', array_merge($result, [
            'keyword' => trim($this->request->getGet('q') ?? ''),
        ]));
    }

    /** GET /admin/reviews/json */
    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('product_reviews r')
            ->select('r.id, r.content, r.is_rewarded, r.is_hidden, r.is_negative, r.created_at,
                      p.name AS product_name, p.slug AS product_slug,
                      u.nickname, u.username')
            ->join('products p', 'p.id = r.product_id')
            ->join('users u', 'u.id = r.user_id')
            ->orderBy('r.id', 'DESC')
            ->get()->getResultArray();

        $byReview = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach ($db->table('product_review_images')->whereIn('review_id', $ids)->get()->getResultArray() as $img) {
                $byReview[(int) $img['review_id']][] = $img['image_path'];
            }
        }

        $data = array_map(fn($r) => [
            'id'           => (int) $r['id'],
            'product_name' => $r['product_name'],
            'product_slug' => $r['product_slug'],
            'author'       => $r['nickname'] ?: $r['username'],
            'content'      => $r['content'],
            'image_count'  => count($byReview[(int) $r['id']] ?? []),
            'is_rewarded'  => (int) $r['is_rewarded'],
            'is_hidden'    => (int) ($r['is_hidden'] ?? 0),
            'is_negative'  => (int) ($r['is_negative'] ?? 0),
            'created_at'   => $r['created_at'],
        ], $rows);

        return $this->response->setJSON(['data' => $data]);
    }

    /** POST /admin/reviews/:id/toggle-hidden */
    public function toggleHidden(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $review = $this->model->find($id);
        $next   = $this->model->toggleHidden($id);
        if ($next === -1) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '리뷰를 찾을 수 없습니다.']);
        }
        // 노출 변경 → AI 요약 재생성
        \App\Libraries\AiProvider\ReviewSummaryHandler::enqueue((int) ($review['product_id'] ?? 0));
        return $this->response->setJSON(['is_hidden' => $next]);
    }

    /** POST /admin/reviews/:id/delete */
    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $review = $this->model->find($id);
        $this->model->deleteReview($id);
        // 삭제 → AI 요약 재생성
        \App\Libraries\AiProvider\ReviewSummaryHandler::enqueue((int) ($review['product_id'] ?? 0));
        return redirect()->to('/admin/reviews')->with('success', '리뷰가 삭제되었습니다.');
    }
}
