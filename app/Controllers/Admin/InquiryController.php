<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Libraries\AiProvider\InquiryTaxonomy;
use App\Models\InquiryModel;

class InquiryController extends BaseController
{
    private InquiryModel $model;

    public function __construct()
    {
        $this->model = new InquiryModel();
    }

    public function index(): string
    {
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $filter   = $this->request->getGet('filter') ?? '';
        $category = $this->request->getGet('category') ?? '';
        $limit    = 20;

        $builder = $this->model->builder();
        if ($filter === 'unread') {
            $builder->where('is_read', 0);
        }
        if (in_array($category, InquiryTaxonomy::CATEGORIES, true)) {
            $builder->where('category', $category);
        }

        $total      = (clone $builder)->countAllResults(false);
        $inquiries  = $builder->orderBy('id', 'DESC')
                              ->limit($limit, ($page - 1) * $limit)
                              ->get()->getResultArray();

        return $this->render('admin/inquiries/index', [
            'inquiries'   => $inquiries,
            'totalPages'  => (int) ceil($total / $limit),
            'currentPage' => $page,
            'total'       => $total,
            'filter'      => $filter,
            'category'    => $category,
            'unreadCount' => $this->model->getUnreadCount(),
            'totalAll'    => $this->model->countAll(),
        ]);
    }

    /** POST /admin/inquiries/:id/suggest-reply — AI 답변 초안 생성 (AJAX) */
    public function suggestReply(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $inquiry = $this->model->find($id);
        if (! $inquiry) {
            return $this->response->setJSON(['error' => '문의를 찾을 수 없습니다.'])->setStatusCode(404);
        }

        try {
            $reply = AiCategoryAdvisor::create()->generateInquiryReply(
                (string) $inquiry['name'],
                (string) ($inquiry['subject'] ?? ''),
                (string) $inquiry['message']
            );
            if ($reply === '') {
                return $this->response->setJSON(['error' => 'AI 응답이 비어있습니다. 잠시 후 다시 시도해주세요.'])->setStatusCode(500);
            }
            return $this->response->setJSON(['reply' => $reply]);
        } catch (AiKeyMissingException $e) {
            return $this->response->setJSON([
                'error'     => $e->getMessage(),
                'setup_url' => '/admin/settings/api',
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            log_message('error', 'AiInquiryReply: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'AI 답변 생성 중 오류가 발생했습니다.'])->setStatusCode(500);
        }
    }

    public function view(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $inquiry = $this->model->find($id);
        if (! $inquiry) return redirect()->to('/admin/inquiries');

        $this->model->markRead($id);

        return $this->render('admin/inquiries/view', ['inquiry' => $inquiry]);
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->model->delete($id);
        return redirect()->to('/admin/inquiries')->with('success', '삭제되었습니다.');
    }
}
