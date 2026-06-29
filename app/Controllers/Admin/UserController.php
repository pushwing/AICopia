<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\GradeService;
use App\Libraries\Mailer;
use App\Models\UserModel;

class UserController extends BaseController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function json(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->userModel->builder()
            ->select('id, nickname, email, phone, role, grade, social_provider, is_active, email_verify_token, created_at, last_login')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $data = array_map(fn($u) => [
            'id'                 => (int) $u['id'],
            'nickname'           => $u['nickname'],
            'email'              => $u['email'],
            'phone'              => $u['phone'] ?? '',
            'role'               => $u['role'],
            'grade'              => $u['grade'] ?? 'bronze',
            'social_provider'    => $u['social_provider'] ?? '',
            'is_active'          => (int) $u['is_active'],
            'email_verify_token' => $u['email_verify_token'] ? '1' : '',
            'created_at'         => $u['created_at'],
            'last_login'         => $u['last_login'] ?? '',
        ], $rows);

        return $this->response->setJSON(['data' => $data]);
    }

    public function index(): string
    {
        $keyword  = $this->request->getGet('q') ?? '';
        $role     = $this->request->getGet('role') ?? '';
        $status   = $this->request->getGet('status') ?? '';
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = 20;

        $builder = $this->userModel->builder();

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('nickname', $keyword)
                ->orLike('email', $keyword)
                ->orLike('username', $keyword)
                ->orLike('phone', $keyword)
                ->groupEnd();
        }
        if ($role !== '') {
            $builder->where('role', $role);
        }
        if ($status === 'unverified') {
            // 이메일 미인증: is_active=0 이면서 verify_token이 있는 일반 가입자
            $builder->where('is_active', 0)
                    ->where('email_verify_token IS NOT NULL');
        } elseif ($status === '1') {
            $builder->where('is_active', 1);
        } elseif ($status === '0') {
            // 비활성(관리자 차단): is_active=0 이면서 token이 없는 경우
            $builder->where('is_active', 0)
                    ->where('email_verify_token IS NULL');
        }

        $total = (clone $builder)->countAllResults(false);
        $users = $builder->orderBy('id', 'DESC')
                         ->limit($perPage, ($page - 1) * $perPage)
                         ->get()->getResultArray();

        return $this->render('admin/users/list', [
            'users'       => $users,
            'total'       => $total,
            'currentPage' => $page,
            'totalPages'  => (int) ceil($total / $perPage),
            'keyword'     => $keyword,
            'role'        => $role,
            'status'      => $status,
        ]);
    }

    public function edit(int $id): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $user = $this->userModel->find($id);
        if (! $user) {
            return redirect()->to('/admin/users')->with('error', '회원을 찾을 수 없습니다.');
        }

        return $this->render('admin/users/edit', ['member' => $user]);
    }

    public function update(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->find($id);
        if (! $user) {
            return redirect()->to('/admin/users')->with('error', '회원을 찾을 수 없습니다.');
        }

        if ((int) $id === (int) session()->get('user_id')) {
            return redirect()->back()->with('error', '본인 계정은 수정할 수 없습니다.');
        }

        $grade = $this->request->getPost('grade');
        $validGrades = ['bronze', 'silver', 'gold', 'platinum'];
        $data = [
            'nickname'  => $this->request->getPost('nickname'),
            'phone'     => $this->request->getPost('phone') ?: null,
            'gender'    => $this->request->getPost('gender') ?: null,
            'birthday'  => $this->request->getPost('birthday') ?: null,
            'role'      => $this->request->getPost('role'),
            'grade'     => in_array($grade, $validGrades, true) ? $grade : 'bronze',
            'is_active' => (int) $this->request->getPost('is_active'),
        ];

        // 관리자가 is_active=1 로 변경하면 미인증 토큰도 정리
        if ($data['is_active'] === 1 && $user['email_verify_token']) {
            $data['email_verify_token']    = null;
            $data['email_verify_token_at'] = null;
        }

        $this->userModel->update($id, $data);

        return redirect()->to('/admin/users')->with('success', '회원 정보가 수정되었습니다.');
    }

    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        if ((int) $id === (int) session()->get('user_id')) {
            return redirect()->back()->with('error', '본인 계정은 삭제할 수 없습니다.');
        }

        $this->userModel->delete($id);

        return redirect()->to('/admin/users')->with('success', '회원이 삭제되었습니다.');
    }

    /** 관리자 수동 이메일 인증 처리 */
    public function manualVerify(int $id)
    {
        $user = $this->userModel->find($id);
        if (! $user) {
            return redirect()->to('/admin/users')->with('error', '회원을 찾을 수 없습니다.');
        }

        // 미인증 상태일 때만 보너스 지급 (이미 인증된 경우 중복 지급 방지)
        $wasUnverified = ! $user['is_active'] && ! empty($user['email_verify_token']);

        $this->userModel->clearVerifyToken($id);

        if ($wasUnverified) {
            $settings = $this->viewData['settings'] ?? [];
            (new GradeService())->awardSignupBonus($id, $settings);
        }

        return redirect()->back()->with('success', '이메일 인증이 완료 처리되었습니다.');
    }

    /** 인증 메일 재발송 */
    public function resendVerify(int $id)
    {
        $user = $this->userModel->find($id);
        if (! $user) {
            return redirect()->to('/admin/users')->with('error', '회원을 찾을 수 없습니다.');
        }

        if ($user['is_active']) {
            return redirect()->back()->with('error', '이미 인증된 회원입니다.');
        }

        $token = $this->userModel->generateVerifyToken($id);
        (new Mailer($this->viewData['settings'] ?? []))->sendVerify($user, $token);

        return redirect()->back()->with('success', '인증 메일을 재발송했습니다.');
    }

    /** GET /admin/users/export — 회원 목록 엑셀 다운로드 */
    public function export(): \CodeIgniter\HTTP\ResponseInterface
    {
        $keyword = trim($this->request->getGet('q')   ?? '');
        $role    = $this->request->getGet('role')      ?? '';
        $status  = $this->request->getGet('status')    ?? '';
        $grade   = $this->request->getGet('grade')     ?? '';
        $from    = $this->request->getGet('from')      ?? '';
        $to      = $this->request->getGet('to')        ?? '';

        $builder = $this->userModel->builder()
            ->select('id, nickname, email, phone, role, grade, social_provider, is_active, email_verify_token, created_at, last_login');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('nickname', $keyword)
                ->orLike('email', $keyword)
                ->orLike('username', $keyword)
                ->orLike('phone', $keyword)
                ->groupEnd();
        }
        if ($role  !== '') $builder->where('role', $role);
        if ($grade !== '') $builder->where('grade', $grade);
        if ($from  !== '') $builder->where('DATE(created_at) >=', $from);
        if ($to    !== '') $builder->where('DATE(created_at) <=', $to);

        if ($status === 'active')         $builder->where('is_active', 1);
        elseif ($status === 'unverified') $builder->where('is_active', 0)->where('email_verify_token IS NOT NULL');
        elseif ($status === 'inactive')   $builder->where('is_active', 0)->where('email_verify_token IS NULL');

        $users = $builder->orderBy('id', 'DESC')->get()->getResultArray();

        $gradeLabels = ['bronze' => '브론즈', 'silver' => '실버', 'gold' => '골드', 'platinum' => '플래티넘'];
        $roleLabels  = ['admin' => '관리자', 'member' => '일반회원'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $col         = fn(int $c, int $r): string =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;

        $headers = ['ID', '닉네임', '이메일', '휴대폰', '역할', '등급', '소셜', '상태', '가입일', '최근 로그인'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($col($i + 1, 1), $h);
        }
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font'    => ['bold' => true],
            'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                          'startColor' => ['argb' => 'FFE9ECEF']],
            'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ]);

        foreach ($users as $i => $u) {
            $rowNum = $i + 2;
            if ($u['is_active'])              $stLabel = '활성';
            elseif ($u['email_verify_token']) $stLabel = '이메일 미인증';
            else                              $stLabel = '비활성';

            $sheet->setCellValue($col(1,  $rowNum), (int) $u['id']);
            $sheet->setCellValue($col(2,  $rowNum), $u['nickname'] ?? '');
            $sheet->setCellValue($col(3,  $rowNum), $u['email']);
            $sheet->setCellValue($col(4,  $rowNum), $u['phone'] ?? '');
            $sheet->setCellValue($col(5,  $rowNum), $roleLabels[$u['role']] ?? $u['role']);
            $sheet->setCellValue($col(6,  $rowNum), $gradeLabels[$u['grade']] ?? ($u['grade'] ?? ''));
            $sheet->setCellValue($col(7,  $rowNum), $u['social_provider'] ?? '');
            $sheet->setCellValue($col(8,  $rowNum), $stLabel);
            $sheet->setCellValue($col(9,  $rowNum), $u['created_at']  ? substr($u['created_at'],  0, 10) : '');
            $sheet->setCellValue($col(10, $rowNum), $u['last_login']  ? substr($u['last_login'],  0, 10) : '');
        }

        foreach (range('A', 'J') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = '회원목록_' . date('Ymd') . '.xlsx';
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($content);
    }

    /** GET /admin/users/:id/tab/orders */
    public function tabOrders(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('orders')
            ->select('id, order_number, status, total_amount, payable_amount, created_at')
            ->where('user_id', $id)
            ->orderBy('id', 'DESC')
            ->limit(30)
            ->get()->getResultArray();

        return $this->response->setJSON(['data' => $rows]);
    }

    /** GET /admin/users/:id/tab/points */
    public function tabPoints(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('point_logs')
            ->select('type, amount, note, created_at')
            ->where('user_id', $id)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $row     = \Config\Database::connect()->table('users')->select('point_balance')->where('id', $id)->get()->getRowArray();
        $balance = $row ? (int) $row['point_balance'] : 0;

        return $this->response->setJSON(['data' => $rows, 'balance' => $balance]);
    }

    /** GET /admin/users/:id/tab/coupons */
    public function tabCoupons(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('user_coupons uc')
            ->select('uc.id, c.name, c.code, uc.status, uc.used_at, c.expires_at, uc.created_at')
            ->join('coupons c', 'c.id = uc.coupon_id')
            ->where('uc.user_id', $id)
            ->orderBy('uc.id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->response->setJSON(['data' => $rows]);
    }

}

