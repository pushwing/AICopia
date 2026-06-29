<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Models\PromotionModel;
use App\Models\UserModel;

class PromotionController extends BaseController
{
    /** GET /promotions */
    public function index(): string
    {
        $model      = new PromotionModel();
        $promotions = $model->getActiveFrontList();

        $userId    = (int) session()->get('user_id');
        $userGrade = null;
        if ($userId > 0) {
            $user      = (new UserModel())->find($userId);
            $userGrade = $user['grade'] ?? 'bronze';
        }

        foreach ($promotions as &$p) {
            $p['accessible'] = $model->checkGradeAccess($p['grade_access'], $userGrade);
        }
        unset($p);

        return $this->render('promotions/list', ['promotions' => $promotions]);
    }

    /** GET /promotion/:slug */
    public function detail(string $slug): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $model     = new PromotionModel();
        $promotion = $model->getActiveBySlug($slug);

        if (! $promotion) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $userId    = (int) session()->get('user_id');
        $userGrade = null;
        if ($userId > 0) {
            $user      = (new UserModel())->find($userId);
            $userGrade = $user['grade'] ?? 'bronze';
        }

        if (! $model->checkGradeAccess($promotion['grade_access'], $userGrade)) {
            if (! $userId) {
                return redirect()->to('/auth/login?redirect=' . urlencode(current_url()));
            }
            return $this->render('promotions/grade_denied', [
                'promotion' => $promotion,
            ]);
        }

        $products = $model->getProducts((int) $promotion['id']);

        return $this->render('promotions/detail', [
            'promotion' => $promotion,
            'products'  => $products,
        ]);
    }
}
