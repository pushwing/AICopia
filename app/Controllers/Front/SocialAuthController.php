<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\BaseController;
use App\Exceptions\SocialEmailNotVerifiedException;
use App\Libraries\GradeService;
use App\Libraries\OAuth\OAuthFactory;
use App\Models\CartModel;
use App\Models\UserModel;

class SocialAuthController extends BaseController
{
    private readonly UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    /**
     * 소셜 로그인 시작 — 제공자 OAuth 페이지로 리다이렉트
     * GET /auth/social/{provider}
     */
    public function redirect(string $provider): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! in_array($provider, OAuthFactory::supported())) {
            return redirect()->to('/auth/login')->with('error', '지원하지 않는 로그인 방식입니다.');
        }

        // CSRF 대용 state 값 생성
        $state = bin2hex(random_bytes(16));
        session()->set('oauth_state', $state);
        session()->set('oauth_provider', $provider);

        $oauth   = OAuthFactory::make($provider);
        $authUrl = $oauth->getAuthUrl($state);

        return redirect()->to($authUrl);
    }

    /**
     * 소셜 로그인 콜백 처리
     * GET /auth/social/{provider}/callback
     */
    public function callback(string $provider): \CodeIgniter\HTTP\RedirectResponse
    {
        $code  = $this->request->getGet('code');
        $state = $this->request->getGet('state');
        $error = $this->request->getGet('error');

        // 사용자가 취소
        if ($error) {
            return redirect()->to('/auth/login')->with('error', '소셜 로그인이 취소되었습니다.');
        }

        // state 검증 (CSRF 방지)
        //
        // 양쪽 값의 "존재"를 먼저 강제한다 — 단순 `!==` 비교만 하면 쿼리에도 세션에도
        // state 가 없을 때 null !== null 이 false 라 그대로 통과한다. 정상 플로우 완료 후
        // oauth_state 를 지우므로 대부분의 세션이 그 상태다. (이슈 #121)
        $expectedState    = session()->get('oauth_state');
        $expectedProvider = session()->get('oauth_provider');

        if (
            ! $code
            || ! is_string($state) || $state === ''
            || ! is_string($expectedState) || $expectedState === ''
            || ! hash_equals($expectedState, $state)
            || $provider !== $expectedProvider
        ) {
            return redirect()->to('/auth/login')->with('error', '잘못된 요청입니다. 다시 시도해주세요.');
        }

        session()->remove(['oauth_state', 'oauth_provider']);

        try {
            $oauth   = OAuthFactory::make($provider);
            $token   = $oauth->getToken($code);

            if (! $token) {
                return redirect()->to('/auth/login')->with('error', '인증 토큰을 가져오지 못했습니다.');
            }

            $profile = $oauth->getProfile($token);

            if (! $profile) {
                return redirect()->to('/auth/login')->with('error', '사용자 정보를 가져오지 못했습니다.');
            }

        } catch (\Throwable $e) {
            log_message('error', '[SocialAuth] ' . $e->getMessage());
            return redirect()->to('/auth/login')->with('error', '소셜 로그인 중 오류가 발생했습니다.');
        }

        try {
            $user = $this->findOrCreateUser($provider, $profile, $token);
        } catch (SocialEmailNotVerifiedException $e) {
            log_message('warning', '[SocialAuth] ' . $e->getMessage());

            return redirect()->to('/auth/login')->with(
                'error',
                '이 소셜 계정의 이메일이 확인되지 않아 기존 계정과 연결할 수 없습니다. '
                . '기존 계정의 이메일·비밀번호로 로그인해주세요.',
            );
        }

        if (! $user) {
            return redirect()->to('/auth/login')->with('error', '계정 처리 중 오류가 발생했습니다.');
        }

        if (! $user['is_active']) {
            return redirect()->to('/auth/login')->with('error', '비활성화된 계정입니다.');
        }

        // 로그인 세션 등록
        session()->set([
            'user_id'       => $user['id'],
            'user_nickname' => $user['nickname'],
            'user_role'     => $user['role'],
            'user_grade'    => $user['grade'] ?? 'bronze',
        ]);

        $this->userModel->updateLastLogin((int) $user['id']);

        // 비로그인 세션 카트를 DB 카트로 병합
        new CartModel()->mergeAndClear((int) $user['id']);

        return redirect()->to(session()->getTempdata('redirect_url') ?? '/');
    }

    /**
     * 소셜 ID로 기존 유저 찾기, 없으면 자동 가입
     */
    /**
     * @param  array<string, mixed>      $profile
     * @return array<string, mixed>|null
     */
    private function findOrCreateUser(string $provider, array $profile, string $token): ?array
    {
        // 1. 같은 소셜 제공자 + ID로 기존 가입 확인
        $user = $this->userModel
            ->where('social_provider', $provider)
            ->where('social_id', $profile['social_id'])
            ->first();

        if ($user) {
            // 토큰 및 아바타 갱신
            $this->userModel->update($user['id'], [
                'social_token' => $token,
                'avatar'       => $profile['avatar'],
            ]);
            return $this->userModel->find($user['id']);
        }

        // 2. 같은 이메일로 일반 가입된 계정 있으면 소셜 연동
        //
        // 제공자가 이메일 소유를 검증했다고 명시한 경우에만 붙인다. 그렇지 않으면
        // 제공자에 남의 이메일을 설정한 것만으로 그 계정을 가져갈 수 있다. 이 앱의
        // 로컬 가입도 인증 메일로 소유를 증명받으므로 기준을 맞춘다. (이슈 #137)
        if (! empty($profile['email']) && ($profile['email_verified'] ?? false) === true) {
            $existing = $this->userModel->where('email', $profile['email'])->first();
            if ($existing) {
                $this->userModel->update($existing['id'], [
                    'social_provider' => $provider,
                    'social_id'       => $profile['social_id'],
                    'social_token'    => $token,
                    'avatar'          => $profile['avatar'],
                ]);
                return $this->userModel->find($existing['id']);
            }
        }

        // 검증되지 않은 이메일이 기존 계정과 겹치면 연동도 신규 생성도 할 수 없다.
        // (users.email 이 UNIQUE 라 3번에서 어차피 실패한다 — 원인을 분명히 알린다.)
        if (! empty($profile['email']) && $this->userModel->where('email', $profile['email'])->first()) {
            throw new SocialEmailNotVerifiedException($provider, (string) $profile['email']);
        }

        // 3. 신규 유저 자동 생성
        $nickname = $this->resolveNickname($profile['nickname']);
        $email    = $profile['email'] ?? $provider . '_' . $profile['social_id'] . '@social.local';

        $id = $this->userModel->insert([
            'username'        => $email,
            'email'           => $email,
            'password'        => null,  // 소셜 전용 계정
            'nickname'        => $nickname,
            'role'            => 'member',
            'social_provider' => $provider,
            'social_id'       => $profile['social_id'],
            'social_token'    => $token,
            'avatar'          => $profile['avatar'],
            'is_active'       => 1,
        ]);

        if (! $id) {
            return null;
        }

        // 소셜 로그인 신규 가입 즉시 보너스 지급
        try {
            $settings = cache()->get('site_settings') ?? [];
            new GradeService()->awardSignupBonus((int) $id, $settings);
        } catch (\Throwable $e) {
            log_message('error', 'GradeService::awardSignupBonus (social) failed: ' . $e->getMessage());
        }

        return $this->userModel->find($id);
    }

    /**
     * 닉네임 중복 시 숫자 접미사 추가
     */
    private function resolveNickname(string $nickname): string
    {
        $base    = mb_substr($nickname, 0, 18);
        $attempt = $base;
        $i       = 2;

        while ($this->userModel->where('nickname', $attempt)->countAllResults() > 0) {
            $attempt = $base . $i;
            $i++;
        }

        return $attempt;
    }
}
