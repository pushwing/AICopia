<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CouponModel;
use App\Models\UserCouponModel;
use App\Models\UserModel;

class CouponController extends BaseController
{
    private CouponModel     $couponModel;
    private UserCouponModel $userCouponModel;

    public function __construct()
    {
        $this->couponModel     = new CouponModel();
        $this->userCouponModel = new UserCouponModel();
    }

    /** GET /admin/coupons */
    public function index(): string
    {
        $result = $this->couponModel->getAdminList([
            'keyword' => $this->request->getGet('keyword'),
            'page'    => $this->request->getGet('page'),
        ]);

        return $this->render('admin/coupons/list', array_merge($result, [
            'keyword' => $this->request->getGet('keyword') ?? '',
            'types'   => CouponModel::TYPES,
        ]));
    }

    /** GET /admin/coupons/json */
    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = \Config\Database::connect()->table('coupons')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $data = array_map(fn($c) => [
            'id'                  => (int) $c['id'],
            'name'                => $c['name'],
            'code'                => $c['code'],
            'type'                => $c['type'],
            'type_label'          => CouponModel::TYPES[$c['type']] ?? $c['type'],
            'target_grade'        => $c['target_grade'] ?? '',
            'discount_value'      => (float) $c['discount_value'],
            'max_discount_amount' => (int) $c['max_discount_amount'],
            'min_order_amount'    => (int) $c['min_order_amount'],
            'total_qty'           => $c['total_qty'] !== null ? (int) $c['total_qty'] : null,
            'used_count'          => (int) $c['used_count'],
            'starts_at'           => $c['starts_at'] ?? '',
            'expires_at'          => $c['expires_at'] ?? '',
            'is_active'           => (int) $c['is_active'],
        ], $rows);

        return $this->response->setJSON(['data' => $data]);
    }

    /** GET /admin/coupons/create */
    public function create(): string
    {
        return $this->render('admin/coupons/form', [
            'coupon' => null,
            'types'  => CouponModel::TYPES,
        ]);
    }

    /** POST /admin/coupons/create */
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! $this->validate($this->validationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->couponModel->insert($this->collectData());

        return redirect()->to('/admin/coupons')->with('success', '쿠폰이 등록되었습니다.');
    }

    /** GET /admin/coupons/:id/edit */
    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        return $this->render('admin/coupons/form', [
            'coupon' => $coupon,
            'types'  => CouponModel::TYPES,
        ]);
    }

    /** POST /admin/coupons/:id/edit */
    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        if (! $this->validate($this->validationRules($id))) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $this->couponModel->update($id, $this->collectData());

        return redirect()->to('/admin/coupons')->with('success', '쿠폰이 수정되었습니다.');
    }

    /** POST /admin/coupons/:id/delete */
    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        $this->couponModel->update($id, ['is_active' => 0]);

        return redirect()->to('/admin/coupons')->with('success', '쿠폰이 비활성화되었습니다.');
    }

    /** GET /admin/coupons/:id/issue */
    public function issueForm(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $result = $this->userCouponModel->getByCoupon($id, $page);

        return $this->render('admin/coupons/issue', array_merge($result, compact('coupon')));
    }

    /** POST /admin/coupons/:id/issue */
    public function issue(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        $userIds = array_filter(array_map('intval', explode(',', $this->request->getPost('user_ids') ?? '')));
        if (empty($userIds)) {
            return redirect()->back()->with('error', '발급할 회원 ID를 입력해주세요.');
        }

        $now = date('Y-m-d H:i:s');
        $db  = \Config\Database::connect();

        $existingRows = $db->table('user_coupons')
            ->select('user_id')
            ->whereIn('user_id', array_values($userIds))
            ->where('coupon_id', $id)
            ->get()->getResultArray();
        $alreadyHas = array_map('intval', array_column($existingRows, 'user_id'));
        $skipped    = count($alreadyHas);

        $toInsert = [];
        foreach ($userIds as $userId) {
            if (in_array($userId, $alreadyHas, true)) continue;
            $toInsert[] = [
                'user_id'    => $userId,
                'coupon_id'  => $id,
                'source'     => 'admin',
                'status'     => 'issued',
                'issued_at'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $issued = count($toInsert);
        if ($toInsert) {
            $db->table('user_coupons')->insertBatch($toInsert);
        }

        $msg = "발급 완료: {$issued}명";
        if ($skipped > 0) $msg .= " (이미 보유: {$skipped}명 건너뜀)";

        return redirect()->to("/admin/coupons/{$id}/issue")->with('success', $msg);
    }

    /** POST /admin/coupons/:id/issue-grade — 등급별 일괄 발급 */
    public function issueGrade(int $id)
    {
        $coupon = $this->couponModel->find($id);
        if (! $coupon) return redirect()->to('/admin/coupons')->with('error', '쿠폰을 찾을 수 없습니다.');

        $grade = $this->request->getPost('grade');
        $validGrades = ['bronze', 'silver', 'gold', 'platinum'];
        if (! in_array($grade, $validGrades, true)) {
            return redirect()->back()->with('error', '유효하지 않은 등급입니다.');
        }

        $db      = \Config\Database::connect();
        $members = $db->table('users')->select('id')
            ->where('role', 'member')->where('is_active', 1)->where('grade', $grade)
            ->get()->getResultArray();

        if (empty($members)) {
            return redirect()->back()->with('error', '해당 등급의 회원이 없습니다.');
        }

        $now       = date('Y-m-d H:i:s');
        $memberIds = array_map(fn(array $m) => (int) $m['id'], $members);

        $existingRows = $db->table('user_coupons')
            ->select('user_id')
            ->whereIn('user_id', $memberIds)
            ->where('coupon_id', $id)
            ->get()->getResultArray();
        $alreadyHas = array_map('intval', array_column($existingRows, 'user_id'));
        $skipped    = count($alreadyHas);

        $toInsert = [];
        foreach ($memberIds as $userId) {
            if (in_array($userId, $alreadyHas, true)) continue;
            $toInsert[] = [
                'user_id'    => $userId,
                'coupon_id'  => $id,
                'source'     => 'grade_bulk',
                'status'     => 'issued',
                'issued_at'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $issued = count($toInsert);
        if ($toInsert) {
            $db->table('user_coupons')->insertBatch($toInsert);
        }

        $msg = "등급별 발급 완료: {$issued}명";
        if ($skipped > 0) $msg .= " (이미 보유: {$skipped}명 건너뜀)";

        return redirect()->to("/admin/coupons/{$id}/issue")->with('success', $msg);
    }

    private function validationRules(?int $excludeId = null): array
    {
        $uniqueCode = 'is_unique[coupons.code' . ($excludeId ? ",id,{$excludeId}" : '') . ']';
        $type = $this->request->getPost('type');
        return [
            'code'             => "required|max_length[50]|{$uniqueCode}",
            'name'             => 'required|max_length[100]',
            'type'             => 'required|in_list[fixed,percent,free_shipping]',
            'discount_value'   => $type === 'free_shipping' ? 'permit_empty|integer|greater_than_equal_to[0]' : 'required|integer|greater_than[0]',
            'min_order_amount' => 'permit_empty|integer|greater_than_equal_to[0]',
            'max_discount_amount' => 'permit_empty|integer|greater_than_equal_to[0]',
            'per_user_limit'   => 'required|integer|greater_than[0]',
        ];
    }

    private function collectData(): array
    {
        $type         = $this->request->getPost('type');
        $gradeRaw     = $this->request->getPost('target_grade') ?? [];
        $validGrades  = ['bronze', 'silver', 'gold', 'platinum'];
        $selectedGrades = array_values(array_filter((array) $gradeRaw, fn($g) => in_array($g, $validGrades, true)));
        $targetGrade  = empty($selectedGrades) ? null : implode(',', $selectedGrades);

        return [
            'code'                => strtoupper(trim($this->request->getPost('code'))),
            'name'                => trim($this->request->getPost('name')),
            'type'                => $type,
            'target_grade'        => $targetGrade,
            'discount_value'      => $type === 'free_shipping' ? 0 : (int) $this->request->getPost('discount_value'),
            'min_order_amount'    => (int) ($this->request->getPost('min_order_amount') ?? 0),
            'max_discount_amount' => (int) ($this->request->getPost('max_discount_amount') ?? 0),
            'total_qty'           => ($v = $this->request->getPost('total_qty')) ? (int) $v : null,
            'per_user_limit'      => max(1, (int) ($this->request->getPost('per_user_limit') ?? 1)),
            'starts_at'           => $this->request->getPost('starts_at') ?: null,
            'expires_at'          => $this->request->getPost('expires_at') ?: null,
            'is_active'           => (int) (bool) $this->request->getPost('is_active'),
        ];
    }
}
