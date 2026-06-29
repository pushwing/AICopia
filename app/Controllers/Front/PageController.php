<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\Mailer;
use App\Models\InquiryModel;
use App\Models\PageModel;

class PageController extends BaseController
{
    private PageModel $pageModel;

    public function __construct()
    {
        $this->pageModel = new PageModel();
    }

    /**
     * 슬러그 기반 동적 페이지 라우팅
     * layout 값에 따라 다른 뷰 파일 렌더링
     */
    public function show(string $slug): string
    {
        $page = $this->pageModel->getBySlug($slug);

        if (! $page) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $viewFile = match ($page['layout']) {
            'contact' => 'pages/contact',
            'landing' => 'pages/landing',
            default   => 'pages/default',
        };

        return $this->render($viewFile, ['page' => $page]);
    }

    /**
     * 문의폼 처리
     */
    public function inquirySubmit(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'name'    => 'required|max_length[100]',
            'email'   => 'required|valid_email',
            'message' => 'required|min_length[10]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $inquiryId = (int) (new InquiryModel())->insert([
            'name'       => $this->request->getPost('name'),
            'email'      => $this->request->getPost('email'),
            'phone'      => $this->request->getPost('phone'),
            'subject'    => $this->request->getPost('subject'),
            'message'    => $this->request->getPost('message'),
            'ip_address' => $this->request->getIPAddress(),
        ]);

        // AI 자동 분류 (백그라운드 워커가 처리)
        \App\Libraries\AiProvider\InquiryClassifyHandler::enqueue($inquiryId);

        // 관리자 이메일 발송 (설정에서 수신 이메일 읽기)
        $toEmail = $this->viewData['settings']['email'] ?? '';
        if ($toEmail) {
            (new Mailer($this->viewData['settings'] ?? []))->sendInquiry($toEmail, [
                'name'    => $this->request->getPost('name'),
                'email'   => $this->request->getPost('email'),
                'phone'   => $this->request->getPost('phone'),
                'subject' => $this->request->getPost('subject'),
                'message' => $this->request->getPost('message'),
            ]);
        }

        return redirect()->back()->with('success', '문의가 접수되었습니다. 빠른 시일 내에 답변드리겠습니다.');
    }

}

