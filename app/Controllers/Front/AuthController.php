<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Exceptions\WithdrawalBlockedException;
use App\Libraries\GradeService;
use App\Libraries\Mailer;
use App\Libraries\WithdrawalService;
use App\Models\CartModel;
use App\Models\ShippingAddressModel;
use App\Models\UserModel;

class AuthController extends BaseController
{
    private readonly UserModel $userModel;

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

        // 로그인이 필요 없는 화면(상품 목록의 찜 버튼, 상단 로그인 버튼 등)에서
        // AuthFilter를 거치지 않고 곧장 /auth/login으로 이동한 경우 Referer로
        // 원래 있던 페이지를 기억해둔다.
        // flashdata(다음 요청까지만 유지)를 쓰는 이유: tempdata처럼 TTL로 오래
        // 남겨두면, 예전에 A 페이지에서 찜하기 → 로그인 취소 → B 페이지에서
        // 다시 찜하기를 눌러도 A가 "이미 저장된 값"으로 남아 덮어써지지 않고,
        // 로그인 후 엉뚱한 A로 돌아가 버린다. flashdata는 이 로그인 흐름을
        // 벗어나는 순간 자연히 사라지므로 그런 stale 값이 남지 않는다.
        if (session()->getFlashdata('redirect_url')) {
            session()->keepFlashdata('redirect_url');
        } else {
            $referer = $this->request->getHeaderLine('Referer');
            // app.baseURL(copia.test 등)이 아니라 실제 접속 호스트(localhost:8420 등)와
            // 비교해야 한다 — 로컬 개발·포트포워딩 환경에서는 둘이 다를 수 있다.
            $host       = $this->request->getServer('HTTP_HOST') ?: parse_url(base_url(), PHP_URL_HOST);
            $refererUrl = parse_url($referer);

            if ($referer !== '' && $host && ($refererUrl['host'] ?? null) === parse_url('http://' . $host, PHP_URL_HOST)
                && ! str_starts_with($refererUrl['path'] ?? '', '/auth')) {
                session()->setFlashdata('redirect_url', $referer);
            }
        }

        return $this->render('auth/login');
    }

    public function loginProcess(): \CodeIgniter\HTTP\RedirectResponse
    {
        // 유효성 검사 실패·비밀번호 오류로 로그인 폼에 되돌아가는 동안에도
        // redirect_url flashdata가 살아있게 매 시도마다 수명을 연장한다.
        session()->keepFlashdata('redirect_url');

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

        if (! $user || ! password_verify($password, (string) $user['password'])) {
            return redirect()->back()->withInput()->with('error', '이메일 또는 비밀번호가 올바르지 않습니다.');
        }

        session()->set([
            'user_id'       => $user['id'],
            'user_nickname' => $user['nickname'],
            'user_role'     => $user['role'],
            'user_grade'    => $user['grade'] ?? 'bronze',
            // 초기 비밀번호 사용 계정은 변경 전까지 다른 화면으로 나가지 못한다 (이슈 #119)
            'must_change_password' => (bool) ($user['must_change_password'] ?? false),
        ]);

        $this->userModel->updateLastLogin((int) $user['id']);

        // 비로그인 세션 카트를 DB 카트로 병합
        new CartModel()->mergeAndClear((int) $user['id']);

        return redirect()->to(session()->getFlashdata('redirect_url') ?? '/');
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
            // 성별: 선택 항목이지만 입력 시 M/F만 허용 (DB ENUM 무결성 보호)
            'gender'   => 'permit_empty|in_list[M,F]',
            // 생년월일: 선택 항목이지만 입력 시 유효한 날짜(Y-m-d)이며 미래 날짜 불가.
            // Config/Validation.php는 gitignore 대상이라 커스텀 룰셋 등록 대신 클로저 룰을 사용한다.
            'birthday' => [
                'rules' => [
                    'permit_empty',
                    'valid_date[Y-m-d]',
                    static function (?string $value, array $data, ?string &$error): bool {
                        if ($value === null || $value === '') {
                            return true;
                        }
                        $date = date_create($value);
                        if ($date === false || $date > date_create('today')) {
                            $error = '생년월일은 오늘 이전 날짜여야 합니다.';

                            return false;
                        }

                        return true;
                    },
                ],
            ],
            // 주소: 선택 항목이지만 입력 시 DB 컬럼 길이 초과를 방지 (shipping_addresses 스키마 기준)
            'zipcode'  => 'permit_empty|max_length[10]',
            'address1' => 'permit_empty|max_length[200]',
            'address2' => 'permit_empty|max_length[100]',
        ];

        $messages = [
            'gender' => [
                'in_list' => '성별 값이 올바르지 않습니다.',
            ],
            'birthday' => [
                'valid_date' => '생년월일 형식이 올바르지 않습니다.',
            ],
            'zipcode' => [
                'max_length' => '우편번호가 너무 깁니다.',
            ],
            'address1' => [
                'max_length' => '기본 주소가 너무 깁니다.',
            ],
            'address2' => [
                'max_length' => '상세 주소는 100자 이내로 입력해주세요.',
            ],
        ];

        if (! $this->validate($rules, $messages)) {
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
            new ShippingAddressModel()->saveAddress((int) $userId, [
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
        new Mailer($this->viewData['settings'] ?? [])->sendVerify($user, $token);

        return redirect()->to('/auth/verify-pending')
            ->with('verify_email', $this->request->getPost('email'));
    }

    // ─── 이메일 인증 ──────────────────────────────────────────────────────────────

    public function verifyPending(): string
    {
        return $this->render('auth/verify_pending');
    }

    public function verifyEmail(string $token): \CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->verifyByToken($token);

        if (! $user) {
            return redirect()->to('/auth/login')
                ->with('error', '인증 링크가 유효하지 않거나 만료되었습니다. 다시 요청해주세요.');
        }

        $this->userModel->clearVerifyToken((int) $user['id']);

        // 가입 보너스 포인트 지급
        $settings = $this->viewData['settings'] ?? [];
        new GradeService()->awardSignupBonus((int) $user['id'], $settings);

        return redirect()->to('/auth/login')
            ->with('success', '이메일 인증이 완료되었습니다. 로그인해주세요.');
    }

    public function resendVerification(): \CodeIgniter\HTTP\RedirectResponse
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
            time() - strtotime((string) $user['email_verify_token_at']) < 60) {
            return redirect()->to('/auth/verify-pending')
                ->with('verify_email', $email)
                ->with('error', '1분 후 다시 시도해주세요.');
        }

        $token = $this->userModel->generateVerifyToken((int) $user['id']);
        new Mailer($this->viewData['settings'] ?? [])->sendVerify($user, $token);

        return redirect()->to('/auth/verify-pending')
            ->with('verify_email', $email)
            ->with('resend_success', '1');
    }

    // ─── 내 정보 수정 ────────────────────────────────────────────────────────────

    public function profile(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $user      = $this->userModel->find((int) session()->get('user_id'));
        $activeTab = $this->request->getGet('tab') ?? 'info';

        $data = ['user' => $user, 'activeTab' => $activeTab];

        // 탈퇴 탭에서만 차단 판정·소멸자산을 계산한다 (기본 정보 탭의 불필요한 쿼리 방지)
        if ($activeTab === 'withdraw' && is_array($user)) {
            $service            = new WithdrawalService();
            $data['withdrawal'] = $service->canWithdraw($user);
            $data['forfeit']    = $service->forfeitSummary((int) $user['id']);
        }

        return $this->render('auth/profile', $data);
    }

    public function profileUpdate(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');
        $user   = $this->userModel->find($userId);
        $tab    = $this->request->getPost('tab') ?? 'info';

        if (! is_array($user)) {
            return redirect()->to('/auth/login');
        }

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
                'password'             => password_hash($this->request->getPost('new_password'), PASSWORD_DEFAULT),
                'must_change_password' => 0,
            ]);
            session()->remove('must_change_password');

            return redirect()->to('/auth/profile?tab=password')->with('success', '비밀번호가 변경되었습니다.');
        }

        return redirect()->to('/auth/profile');
    }

    // ─── 회원탈퇴 ────────────────────────────────────────────────────────────────

    public function withdrawProcess(): \CodeIgniter\HTTP\RedirectResponse
    {
        $userId = (int) session()->get('user_id');
        $user   = $this->userModel->find($userId);

        if (! is_array($user)) {
            return redirect()->to('/auth/login');
        }

        $service = new WithdrawalService();

        // 본인 확인 — 소셜 계정은 비밀번호가 랜덤이라 검증할 수 없어 확인 문구로 대체
        if (empty($user['social_provider'])) {
            $password = (string) $this->request->getPost('password');
            if (! password_verify($password, $user['password'])) {
                return redirect()->back()->withInput()
                    ->with('errors', ['password' => '비밀번호가 일치하지 않습니다.']);
            }
        } elseif ($this->request->getPost('confirm_text') !== '탈퇴합니다') {
            return redirect()->back()->withInput()
                ->with('errors', ['confirm_text' => '확인 문구를 정확히 입력해 주세요.']);
        }

        $reasonCode = (string) $this->request->getPost('reason_code');
        if (! array_key_exists($reasonCode, WithdrawalService::REASON_CODES) || $reasonCode === 'admin') {
            $reasonCode = 'etc';
        }

        $reasonText = $this->request->getPost('reason_text');
        $reasonText = is_string($reasonText) && trim($reasonText) !== '' ? mb_substr(trim($reasonText), 0, 500) : null;

        try {
            $service->withdraw($userId, $reasonCode, $reasonText);
        } catch (WithdrawalBlockedException $e) {
            return redirect()->to('/auth/profile?tab=withdraw')
                ->with('error', implode(' ', $e->reasons));
        } catch (\Throwable $e) {
            log_message('error', '[withdraw] 탈퇴 처리 실패 user_id=' . $userId . ' — ' . $e->getMessage());

            return redirect()->to('/auth/profile?tab=withdraw')
                ->with('error', '탈퇴 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.');
        }

        session()->destroy();

        return redirect()->to('/')->with('success', '탈퇴가 완료되었습니다. 그동안 이용해 주셔서 감사합니다.');
    }

    // ─── 이메일 발송 (private) ────────────────────────────────────────────────────

}
