<?php

declare(strict_types=1);

namespace App\Libraries\OAuth;

/**
 * 네이버 로그인
 * 앱 등록: https://developers.naver.com/apps/#/register
 * 필요 권한: 이메일, 별명, 프로필 사진
 *
 * 휴대전화번호·성별·생일·출생연도는 앱 설정의 "제공 정보 선택"에서 추가로
 * 지정한 경우에만 프로필 응답에 포함된다(미동의 시 키 자체가 없다).
 */
class NaverProvider extends AbstractOAuthProvider
{
    /** users.phone 컬럼 길이 — 초과 값은 잘라 넣지 않고 버린다 */
    private const int PHONE_MAX_LENGTH = 20;

    public function __construct()
    {
        parent::__construct('naver');
    }

    /** @return array<string, mixed>|null */
    public function getProfile(string $token): ?array
    {
        $data = $this->get($this->config['profile_url'], [
            'Authorization: Bearer ' . $token,
        ]);

        if (empty($data['response'])) {
            return null;
        }

        $r = $data['response'];

        return [
            'social_id' => (string) $r['id'],
            'email'     => $r['email'] ?? null,
            // 네이버 프로필 API 는 이메일 검증 여부를 알려주지 않는다. 증명이 없으므로
            // 미검증으로 취급해 기존 계정 자동 연동 대상에서 제외한다 (이슈 #137)
            'email_verified' => false,
            'nickname'       => $r['nickname'] ?? $r['name'] ?? '네이버유저',
            'avatar'         => $r['profile_image'] ?? null,
            'phone'          => $this->normalizePhone($r['mobile'] ?? null, $r['mobile_e164'] ?? null),
            'gender'         => $this->normalizeGender($r['gender'] ?? null),
            'birthday'       => $this->normalizeBirthday($r['birthyear'] ?? null, $r['birthday'] ?? null),
        ];
    }

    /**
     * 휴대전화번호 — 하이픈 표기(010-0000-0000)를 우선 쓰고, 없으면 E.164 표기로 대체
     */
    private function normalizePhone(mixed $mobile, mixed $e164): ?string
    {
        foreach ([$mobile, $e164] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $value = trim($candidate);

            if ($value !== '' && strlen($value) <= self::PHONE_MAX_LENGTH) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 성별 — 네이버는 M/F 외에 확인 불가를 뜻하는 U 를 보낼 수 있다.
     * users.gender 는 ENUM('M','F') 이므로 그 외 값은 미입력으로 처리한다.
     */
    private function normalizeGender(mixed $gender): ?string
    {
        return in_array($gender, ['M', 'F'], true) ? $gender : null;
    }

    /**
     * 생년월일 — 네이버는 연도(birthyear, YYYY)와 월일(birthday, MM-DD)을 따로 준다.
     * users.birthday 는 DATE 라 둘 다 있어야 저장할 수 있고, 실제 존재하는 날짜여야 한다.
     */
    private function normalizeBirthday(mixed $birthyear, mixed $monthDay): ?string
    {
        if (! is_string($birthyear) && ! is_int($birthyear)) {
            return null;
        }

        if (! is_string($monthDay) || preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $m) !== 1) {
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
