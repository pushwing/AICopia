<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PointLogModel;

class PointController extends BaseController
{
    private PointLogModel $pointLogModel;

    public function __construct()
    {
        $this->pointLogModel = new PointLogModel();
    }

    /** GET /admin/points — 회원별 포인트 잔액 목록 */
    public function index(): string
    {
        $db      = \Config\Database::connect();
        $keyword = trim($this->request->getGet('keyword') ?? '');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 20;

        $builder = $db->table('users')->select('id, email, nickname, point_balance')
            ->where('role', 'member');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('email', $keyword)
                ->orLike('nickname', $keyword)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $users = $builder->orderBy('point_balance', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return $this->render('admin/points/index', compact('users', 'total', 'page', 'perPage', 'keyword'));
    }

    /** GET /admin/points/:userId/history */
    public function history(int $userId): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $db   = \Config\Database::connect();
        $user = $db->table('users')->select('id, email, nickname, point_balance')
            ->where('id', $userId)->get()->getRowArray();

        if (! $user) return redirect()->to('/admin/points')->with('error', '회원을 찾을 수 없습니다.');

        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $result = $this->pointLogModel->getByUser($userId, $page);

        return $this->render('admin/points/history', array_merge($result, compact('user')));
    }

    /** POST /admin/points/adjust — 포인트 수동 조정 */
    public function adjust(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) $this->request->getPost('user_id');
        $amount = (int) $this->request->getPost('amount');
        $note   = trim($this->request->getPost('note') ?? '');

        if (! $userId || $amount === 0) {
            return redirect()->back()->with('error', '잘못된 요청입니다.');
        }

        $db   = \Config\Database::connect();
        $user = $db->table('users')->select('id, point_balance')->where('id', $userId)->get()->getRowArray();

        if (! $user) return redirect()->back()->with('error', '회원을 찾을 수 없습니다.');

        $newBalance = (int) $user['point_balance'] + $amount;
        if ($newBalance < 0) {
            return redirect()->back()->with('error', '차감 후 잔액이 음수가 될 수 없습니다.');
        }

        $db->transStart();

        $db->table('users')->where('id', $userId)->update(['point_balance' => $newBalance]);

        $this->pointLogModel->record(
            $userId,
            'admin',
            $amount,
            null,
            $note ?: ($amount > 0 ? '관리자 포인트 지급' : '관리자 포인트 차감')
        );

        $db->transComplete();

        if (! $db->transStatus()) {
            return redirect()->back()->with('error', '처리에 실패했습니다.');
        }

        return redirect()->to("/admin/points/{$userId}/history")
            ->with('success', ($amount > 0 ? '+' : '') . number_format($amount) . 'P 조정 완료');
    }
}
