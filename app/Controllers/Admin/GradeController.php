<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\GradeService;
use App\Models\UserModel;

class GradeController extends BaseController
{
    private GradeService $gradeService;
    private UserModel    $userModel;

    public function __construct()
    {
        $this->gradeService = new GradeService();
        $this->userModel    = new UserModel();
    }

    /** GET /admin/grade/platinum — 플래티넘 선정 화면 (골드 회원 목록) */
    public function platinum()
    {
        $keyword = $this->request->getGet('q') ?? '';
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        $result = $this->gradeService->getGoldMembersForPromotion($keyword, $page);

        return $this->render('admin/grade/platinum', array_merge($result, [
            'keyword' => $keyword,
        ]));
    }

    /** POST /admin/grade/platinum/:id/promote — 플래티넘 승급 */
    public function promote(int $id)
    {
        $user = $this->userModel->find($id);
        if (! $user || $user['grade'] !== 'gold') {
            return redirect()->to('/admin/grade/platinum')
                ->with('error', '골드 등급 회원만 플래티넘으로 승급할 수 있습니다.');
        }

        $settings = $this->viewData['settings'] ?? [];
        $this->gradeService->applyUpgrade($id, 'platinum', $settings);

        return redirect()->to('/admin/grade/platinum')
            ->with('success', $user['nickname'] . ' 님을 플래티넘으로 승급했습니다.');
    }
}
