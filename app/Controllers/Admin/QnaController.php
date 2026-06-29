<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Models\ProductModel;
use App\Models\ProductQnaModel;

class QnaController extends BaseController
{
    private ProductQnaModel $model;

    public function __construct()
    {
        $this->model = new ProductQnaModel();
    }

    /** GET /admin/qna */
    public function index(): string
    {
        $result = $this->model->adminGetAll([
            'keyword'  => trim($this->request->getGet('q') ?? ''),
            'answered' => $this->request->getGet('answered') ?? '',
            'page'     => $this->request->getGet('page') ?? 1,
        ]);

        return $this->render('admin/qna/list', array_merge($result, [
            'keyword'  => trim($this->request->getGet('q') ?? ''),
            'answered' => $this->request->getGet('answered') ?? '',
        ]));
    }

    /** POST /admin/qna/:id/answer */
    public function answer(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $answer = trim($this->request->getPost('answer') ?? '');

        if ($answer === '') {
            return redirect()->back()->with('error', '답변 내용을 입력해주세요.');
        }

        if (! $this->model->find($id)) {
            return redirect()->to('/admin/qna')->with('error', '문의를 찾을 수 없습니다.');
        }

        $this->model->update($id, [
            'answer'      => $answer,
            'is_answered' => 1,
            'answered_at' => date('Y-m-d H:i:s'),
            'answered_by' => (int) session()->get('user_id'),
        ]);

        return redirect()->back()->with('success', '답변이 등록되었습니다.');
    }

    /** POST /admin/qna/:id/suggest-answer — AI 답변 초안 생성 (AJAX) */
    public function suggestAnswer(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $qna = $this->model->find($id);
        if (! $qna) {
            return $this->response->setJSON(['error' => '문의를 찾을 수 없습니다.'])->setStatusCode(404);
        }

        $product     = (new ProductModel())->find((int) $qna['product_id']);
        $productName = $product['name'] ?? '';
        $productDesc = $product['description'] ?? '';

        try {
            $answer = AiCategoryAdvisor::create()->generateQnaAnswer(
                $productName,
                $productDesc,
                (string) $qna['title'],
                (string) $qna['content']
            );
            if ($answer === '') {
                return $this->response->setJSON(['error' => 'AI 응답이 비어있습니다. 잠시 후 다시 시도해주세요.'])->setStatusCode(500);
            }
            return $this->response->setJSON(['answer' => $answer]);
        } catch (AiKeyMissingException $e) {
            return $this->response->setJSON([
                'error'     => $e->getMessage(),
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            log_message('error', 'AiQnaAdvisor: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 답변 생성 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    /** POST /admin/qna/:id/delete */
    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->model->delete($id);
        return redirect()->to('/admin/qna')->with('success', '문의가 삭제되었습니다.');
    }
}
