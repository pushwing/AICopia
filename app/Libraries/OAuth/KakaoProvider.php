<?php

declare(strict_types=1);

namespace App\Libraries\OAuth;

/**
 * 카카오 로그인
 * 앱 등록: https://developers.kakao.com/console/app
 * 동의 항목: 닉네임, 프로필 사진, 카카오계정(이메일)
 *
 * 전화번호·성별·생일·출생연도는 카카오 콘솔에서 해당 동의 항목을 켠 경우에만
 * kakao_account 에 포함된다(미동의 시 키 자체가 없다). 전화번호는 비즈니스 앱
 * 전환이 추가로 필요하다. 자세한 설정은 Config\OAuth 의 kakao 주석 참고.
 */
class KakaoProvider extends AbstractOAuthProvider
{
    /** users.phone 컬럼 길이 — 초과 값은 잘라 넣지 않고 버린다 */
    private const int PHONE_MAX_LENGTH = 20;

    /** 카카오 Client Secret 은 앱 보안 설정에서 선택 사항이다 */
    protected bool $requiresSecret = false;

    public function __construct()
    {
        parent::__construct('kakao');
    }

    /** @return array<string, mixed>|null */
    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['id'])) {
            return null;
        }

        $account  = $data['kakao_account'] ?? [];
        $profile  = $account['profile']    ?? [];

        return [
            'social_id' => (string) $data['id'],
            'email'     => $account['email'] ?? null,
            // 카카오 계정 이메일은 사용자가 바꿀 수 있어, 검증·유효 두 플래그가 모두
            // 참일 때만 소유가 증명된 것으로 본다 (이슈 #137)
            'email_verified' => ($account['is_email_verified'] ?? false) === true
                             && ($account['is_email_valid'] ?? false) === true,
            'nickname' => $profile['nickname'] ?? '카카오유저',
            'avatar'   => $profile['profile_image_url'] ?? null,
            'phone'    => $this->normalizePhone($account['phone_number'] ?? null),
            'gender'   => $this->normalizeGender($account['gender'] ?? null),
            'birthday' => $this->normalizeBirthday($account['birthyear'] ?? null, $account['birthday'] ?? null),
        ];
    }

    /**
     * 전화번호 — 카카오는 국내 번호를 '+82 10-1234-5678' 처럼 국가번호 표기로 준다.
     * 이 앱의 일반 가입·네이버 로그인은 '010-1234-5678' 로 저장하므로 국내 표기로 맞춘다.
     * 해외 번호는 형식이 정해져 있지 않아 받은 그대로 두고, users.phone 길이를
     * 넘어가면(해외 번호 등) 잘라서 잘못된 번호를 남기지 않고 버린다.
     */
    private function normalizePhone(mixed $phoneNumber): ?string
    {
        if (! is_string($phoneNumber)) {
            return null;
        }

        $value = trim($phoneNumber);

        if (preg_match('/^\+82\s*(.+)$/', $value, $m) === 1) {
            // 국가번호를 떼고 국내 접두 0 을 붙인다 ('10-1234-5678' → '010-1234-5678')
            $value = '0' . ltrim((string) preg_replace('/\s+/', '', $m[1]), '0');
        }

        if ($value === '' || strlen($value) > self::PHONE_MAX_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * 성별 — 카카오는 'male'/'female' 로 준다.
     * users.gender 는 ENUM('M','F') 이므로 그 외 값은 미입력으로 처리한다.
     */
    private function normalizeGender(mixed $gender): ?string
    {
        return match ($gender) {
            'male'   => 'M',
            'female' => 'F',
            default  => null,
        };
    }

    /**
     * 생년월일 — 카카오는 연도(birthyear, YYYY)와 월일(birthday, MMDD)을 따로 준다.
     * users.birthday 는 DATE 라 둘 다 있어야 저장할 수 있고, 실제 존재하는 날짜여야 한다.
     *
     * birthday_type 이 'LUNAR'(음력)여도 사용자가 등록한 날짜 그대로 저장한다 —
     * 이 앱에는 음력 개념이 없고(일반 가입도 동일), 양력 환산은 별도 데이터가 필요하다.
     */
    private function normalizeBirthday(mixed $birthyear, mixed $monthDay): ?string
    {
        if (! is_string($birthyear) && ! is_int($birthyear)) {
            return null;
        }

        if (! is_string($monthDay) || preg_match('/^(\d{2})(\d{2})$/', $monthDay, $m) !== 1) {
            return null;
        }

        $year = (string) $birthyear;

        if (preg_match('/^\d{4}$/', $year) !== 1) {
            return null;
        }

        // 윤년이 아닌 해의 2월 29일 등 존재하지 않는 날짜를 걸러낸다
        if (! checkdate((int) $m[1], (int) $m[2], (int) $year)) {
            return null;
        }

        return sprintf('%s-%s-%s', $year, $m[1], $m[2]);
    }
}
