<?php

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Libraries\GradeService;
use App\Libraries\Mailer;
use App\Models\CartModel;
use App\Models\ShippingAddressModel;
use App\Models\UserModel;

class AuthController extends BaseController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    // ─── 로그인 ──────────────────────────────────────────────────────────────────

    public function login(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        if (session()->get('user_id')) {
            return redirect()->to('/');
        }
        return $this->render('auth/login');
    }

    public function loginProcess(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[6]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        // 미인증 유저 선체크 — 비밀번호 검증 전에 분기해 정탐 방지
        $unverified = $this->userModel->findUnverified($email);
        if ($unverified) {
            return redirect()->back()->withInput()
                ->with('unverified_email', $email)
                ->with('error', '이메일 인증이 필요합니다. 메일함을 확인해주세요.');
        }

        $user = $this->userModel->findByEmail($email);

        if (! $user || ! password_verify($password, $user['password'])) {
            return redirect()->back()->withInput()->with('error', '이메일 또는 비밀번호가 올바르지 않습니다.');
        }

        session()->set([
            'user_id'       => $user['id'],
            'user_nickname' => $user['nickname'],
            'user_role'     => $user['role'],
            'user_grade'    => $user['grade'] ?? 'bronze',
        ]);

        $this->userModel->updateLastLogin($user['id']);

        // 비로그인 세션 카트를 DB 카트로 병합
        (new CartModel())->mergeAndClear((int) $user['id']);

        return redirect()->to(session()->getTempdata('redirect_url') ?? '/');
    }

    public function logout(): \CodeIgniter\HTTP\RedirectResponse
    {
        session()->destroy();
        return redirect()->to('/auth/login');
    }

    // ─── 회원가입 ─────────────────────────────────────────────────────────────────

    public function register(): string
    {
        return $this->render('auth/register');
    }

    public function registerProcess(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rules = [
            'email'    => 'required|valid_email|is_unique[users.email]',
            'password' => 'required|min_length[8]',
            'nickname' => 'required|min_length[2]|max_length[20]',
            'phone'    => 'required|min_length[10]|max_length[20]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $userId = $this->userModel->insert([
            'email'     => $this->request->getPost('email'),
            'username'  => $this->request->getPost('email'),
            'password'  => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
            'nickname'  => $this->request->getPost('nickname'),
            'phone'     => $this->request->getPost('phone'),
            'gender'    => $this->request->getPost('gender') ?: null,
            'birthday'  => $this->request->getPost('birthday') ?: null,
            'role'      => 'member',
            'is_active' => 0,
        ], true);

        if (! $userId) {
            return redirect()->back()->withInput()->with('error', '회원가입 중 오류가 발생했습니다. 다시 시도해주세요.');
        }

        // 주소 입력된 경우 배송지에 저장
        $zipcode  = $this->request->getPost('zipcode');
        $address1 = $this->request->getPost('address1');
        if ($zipcode && $address1) {
            (new ShippingAddressModel())->saveAddress((int) $userId, [
                'receiver_name'  => $this->request->getPost('nickname'),
                'receiver_phone' => $this->request->getPost('phone'),
                'zipcode'        => $zipcode,
                'address1'       => $address1,
                'address2'       => $this->request->getPost('address2') ?: '',
                'is_default'     => 1,
            ]);
        }

        $token = $this->userModel->generateVerifyToken((int) $userId);
        $user  = $this->userModel->find((int) $userId);
        (new Mailer($this->viewData['settings'] ?? []))->sendVerify($user, $token);

        return redirect()->to('/auth/verify-pending')
            ->with('verify_email', $this->request->getPost('email'));
    }

    // ─── 이메일 인증 ──────────────────────────────────────────────────────────────

    public function verifyPending()
    {
        return $this->render('auth/verify_pending');
    }

    public function verifyEmail(string $token)
    {
        $user = $this->userModel->verifyByToken($token);

        if (! $user) {
            return redirect()->to('/auth/login')
                ->with('error', '인증 링크가 유효하지 않거나 만료되었습니다. 다시 요청해주세요.');
        }

        $this->userModel->clearVerifyToken($user['id']);

        // 가입 보너스 포인트 지급
        $settings = $this->viewData['settings'] ?? [];
        (new GradeService())->awardSignupBonus((int) $user['id'], $settings);

        return redirect()->to('/auth/login')
            ->with('success', '이메일 인증이 완료되었습니다. 로그인해주세요.');
    }

    public function resendVerification()
    {
        $email = $this->request->getPost('email');

        if (! $email) {
            return redirect()->back()->with('error', '이메일을 입력해주세요.');
        }

        $user = $this->userModel->findUnverified($email);

        if (! $user) {
            // 이미 인증됐거나 존재하지 않는 경우도 동일 메시지 (이메일 열거 방지)
            return redirect()->to('/auth/verify-pending')
                ->with('verify_email', $email)
                ->with('resend_success', '1');
        }

        // 1분 이내 재발송 차단
        if ($user['email_verify_token_at'] &&
            time() - strtotime($user['email_verify_token_at']) < 60) {
            return redirect()->to('/auth/verify-pending')
                ->with('verify_email', $email)
                ->with('error', '1분 후 다시 시도해주세요.');
        }

        $token = $this->userModel->generateVerifyToken($user['id']);
        (new Mailer($this->viewData['settings'] ?? []))->sendVerify($user, $token);

        return redirect()->to('/auth/verify-pending')
            ->with('verify_email', $email)
            ->with('resend_success', '1');
    }

    // ─── 내 정보 수정 ────────────────────────────────────────────────────────────

    public function profile(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $user      = $this->userModel->find(session()->get('user_id'));
        $activeTab = $this->request->getGet('tab') ?? 'info';
        return $this->render('auth/profile', compact('user', 'activeTab'));
    }

    public function profileUpdate(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = session()->get('user_id');
        $user   = $this->userModel->find($userId);
        $tab  = $this->request->getPost('tab') ?? 'info';

        // ── 기본정보 탭 ──
        if ($tab === 'info') {
            $rules = [
                'nickname' => 'required|min_length[2]|max_length[20]',
                'phone'    => 'required|min_length[10]|max_length[20]',
            ];

            if (! $this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            $this->userModel->update($userId, [
                'nickname' => $this->request->getPost('nickname'),
                'phone'    => $this->request->getPost('phone'),
                'gender'   => $this->request->getPost('gender') ?: null,
                'birthday' => $this->request->getPost('birthday') ?: null,
            ]);

            session()->set('user_nickname', $this->request->getPost('nickname'));

            return redirect()->to('/auth/profile')->with('success', '정보가 수정되었습니다.');
        }

        // ── 비밀번호 탭 ──
        if ($tab === 'password') {
            if ($user['social_provider']) {
                return redirect()->back()->with('error', '소셜 로그인 계정은 비밀번호를 변경할 수 없습니다.');
            }

            $rules = [
                'current_password' => 'required',
                'new_password'     => 'required|min_length[8]',
                'confirm_password' => 'required|matches[new_password]',
            ];

            if (! $this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            if (! password_verify($this->request->getPost('current_password'), $user['password'])) {
                return redirect()->back()->withInput()->with('error', '현재 비밀번호가 올바르지 않습니다.');
            }

            $this->userModel->update($userId, [
                'password' => password_hash($this->request->getPost('new_password'), PASSWORD_DEFAULT),
            ]);

            return redirect()->to('/auth/profile?tab=password')->with('success', '비밀번호가 변경되었습니다.');
        }

        return redirect()->to('/auth/profile');
    }

    // ─── 이메일 발송 (private) ────────────────────────────────────────────────────

}

