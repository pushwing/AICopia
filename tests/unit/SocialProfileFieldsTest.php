<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\SocialAuthController;
use App\Libraries\OAuth\KakaoProvider;
use App\Libraries\OAuth\NaverProvider;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionMethod;

/**
 * 소셜 로그인으로 받은 휴대폰번호·성별·생년월일이 users 테이블에 저장되는지 검증.
 *
 * 네이버 프로필 API 는 동의 항목에 따라 mobile / gender / birthday / birthyear 를
 * 함께 내려주지만, 기존 구현은 이 값을 프로필 배열에 담지도(NaverProvider),
 * DB 에 쓰지도(SocialAuthController) 않아 전부 버려지고 있었다.
 *
 * 카카오도 kakao_account 로 같은 항목을 내려주며 표기 형식만 다르다
 * (전화번호 '+82 10-…', 성별 'male'/'female', 생일 'MMDD'). 저장 로직은
 * 제공자와 무관하므로 제공자별 매핑이 같은 모양으로 맞춰졌는지 검증한다.
 */
final class SocialProfileFieldsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        if ($this->cleanup !== []) {
            db_connect()->table('users')->whereIn('id', $this->cleanup)->delete();
            $this->cleanup = [];
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /**
     * get() 을 스텁으로 대체한 NaverProvider 로 프로필을 매핑한다.
     *
     * @param  array<string, mixed>     $response 네이버 API 의 response 객체
     * @return array<string, mixed>|null
     */
    private function naverProfile(array $response): ?array
    {
        $provider = new class () extends NaverProvider {
            /** @var array<string, mixed> */
            public array $stub = [];

            protected function get(string $url, array $headers = []): array
            {
                return $this->stub;
            }
        };

        $provider->stub = ['response' => $response];

        return $provider->getProfile('dummy-token');
    }

    /** @return array<string, mixed> 네이버가 모든 항목을 내려준 기본 응답 */
    private function fullNaverResponse(): array
    {
        return [
            'id'            => 'nid' . substr(uniqid(), -8),
            'nickname'      => '네이버사용자',
            'email'         => 'spf-' . substr(uniqid(), -8) . '@example.test',
            'profile_image' => 'https://example.test/avatar.png',
            'mobile'        => '010-1234-5678',
            'mobile_e164'   => '+821012345678',
            'gender'        => 'M',
            'birthday'      => '10-01',
            'birthyear'     => '1990',
        ];
    }

    /**
     * get() 을 스텁으로 대체한 KakaoProvider 로 프로필을 매핑한다.
     *
     * @param  array<string, mixed>     $account 카카오 API 의 kakao_account 객체
     * @return array<string, mixed>|null
     */
    private function kakaoProfile(array $account, string $id = 'kid-1'): ?array
    {
        $provider = new class () extends KakaoProvider {
            /** @var array<string, mixed> */
            public array $stub = [];

            protected function get(string $url, array $headers = []): array
            {
                return $this->stub;
            }
        };

        $provider->stub = ['id' => $id, 'kakao_account' => $account];

        return $provider->getProfile('dummy-token');
    }

    /** @return array<string, mixed> 카카오가 모든 항목을 내려준 기본 kakao_account */
    private function fullKakaoAccount(): array
    {
        return [
            'profile' => [
                'nickname'          => '카카오사용자',
                'profile_image_url' => 'https://example.test/kakao.png',
            ],
            'email'             => 'spf-k-' . substr(uniqid(), -8) . '@example.test',
            'is_email_verified' => true,
            'is_email_valid'    => true,
            'has_phone_number'  => true,
            'phone_number'      => '+82 10-1234-5678',
            'has_gender'        => true,
            'gender'            => 'male',
            'has_birthday'      => true,
            'birthday'          => '1001',
            'birthday_type'     => 'SOLAR',
            'has_birthyear'     => true,
            'birthyear'         => '1990',
        ];
    }

    /**
     * @param  array<string, mixed>      $profile
     * @return array<string, mixed>|null
     */
    private function findOrCreate(array $profile, string $provider = 'naver'): ?array
    {
        $controller = new SocialAuthController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $result = new ReflectionMethod($controller, 'findOrCreateUser')
            ->invoke($controller, $provider, $profile, 'dummy-token');

        if (is_array($result) && isset($result['id'])) {
            $this->cleanup[] = (int) $result['id'];
        }

        return $result;
    }

    // ── NaverProvider 매핑 ───────────────────────────────────────────────────

    public function testNaverProfileMapsPhoneGenderAndBirthday(): void
    {
        $profile = $this->naverProfile($this->fullNaverResponse());

        $this->assertIsArray($profile);
        $this->assertSame('010-1234-5678', $profile['phone']);
        $this->assertSame('M', $profile['gender']);
        $this->assertSame('1990-10-01', $profile['birthday'], 'birthyear + birthday 를 DATE 로 합쳐야 한다');
    }

    public function testUnknownGenderBecomesNull(): void
    {
        // 네이버는 확인 불가일 때 'U' 를 주지만 users.gender 는 ENUM('M','F') 이다
        $response           = $this->fullNaverResponse();
        $response['gender'] = 'U';

        $this->assertNull($this->naverProfile($response)['gender']);
    }

    public function testBirthdayIsNullWithoutBirthyear(): void
    {
        // 출생연도 미동의 시 MM-DD 만 온다 — DATE 컬럼에 넣을 수 없다
        $response = $this->fullNaverResponse();
        unset($response['birthyear']);

        $this->assertNull($this->naverProfile($response)['birthday']);
    }

    public function testInvalidBirthdayIsNull(): void
    {
        $response              = $this->fullNaverResponse();
        $response['birthday']  = '02-30';
        $response['birthyear'] = '1990';

        $this->assertNull($this->naverProfile($response)['birthday'], '존재하지 않는 날짜는 버려야 한다');
    }

    public function testPhoneFallsBackToE164(): void
    {
        $response = $this->fullNaverResponse();
        unset($response['mobile']);

        $this->assertSame('+821012345678', $this->naverProfile($response)['phone']);
    }

    public function testMissingOptionalFieldsAreNull(): void
    {
        // 동의 항목을 하나도 받지 못한 앱 — 기존 동작이 깨지면 안 된다
        $profile = $this->naverProfile([
            'id'       => 'nid-minimal',
            'nickname' => '최소사용자',
        ]);

        $this->assertIsArray($profile);
        $this->assertNull($profile['phone']);
        $this->assertNull($profile['gender']);
        $this->assertNull($profile['birthday']);
    }

    // ── KakaoProvider 매핑 ───────────────────────────────────────────────────

    public function testKakaoProfileMapsPhoneGenderAndBirthday(): void
    {
        $profile = $this->kakaoProfile($this->fullKakaoAccount());

        $this->assertIsArray($profile);
        $this->assertSame('010-1234-5678', $profile['phone'], '국가번호 표기를 국내 표기로 맞춰야 한다');
        $this->assertSame('M', $profile['gender']);
        $this->assertSame('1990-10-01', $profile['birthday'], 'birthyear + birthday(MMDD) 를 DATE 로 합쳐야 한다');
    }

    public function testKakaoFemaleMapsToF(): void
    {
        $account           = $this->fullKakaoAccount();
        $account['gender'] = 'female';

        $this->assertSame('F', $this->kakaoProfile($account)['gender']);
    }

    public function testKakaoUnexpectedGenderBecomesNull(): void
    {
        // users.gender 는 ENUM('M','F') 이므로 male/female 외 값은 미입력으로 둔다
        $account           = $this->fullKakaoAccount();
        $account['gender'] = 'unknown';

        $this->assertNull($this->kakaoProfile($account)['gender']);
    }

    public function testKakaoBirthdayIsNullWithoutBirthyear(): void
    {
        // 출생연도 미동의 시 MMDD 만 온다 — DATE 컬럼에 넣을 수 없다
        $account = $this->fullKakaoAccount();
        unset($account['birthyear']);

        $this->assertNull($this->kakaoProfile($account)['birthday']);
    }

    public function testKakaoInvalidBirthdayIsNull(): void
    {
        $account             = $this->fullKakaoAccount();
        $account['birthday'] = '0230';

        $this->assertNull($this->kakaoProfile($account)['birthday'], '존재하지 않는 날짜는 버려야 한다');
    }

    public function testKakaoOverseasPhoneIsKeptAsIs(): void
    {
        // +82 가 아닌 번호는 국내 표기 규칙을 적용할 수 없어 받은 그대로 둔다
        $account                 = $this->fullKakaoAccount();
        $account['phone_number'] = '+1 415-555-0100';

        $this->assertSame('+1 415-555-0100', $this->kakaoProfile($account)['phone']);
    }

    public function testKakaoTooLongPhoneIsDropped(): void
    {
        // users.phone 은 VARCHAR(20) — 잘라서 잘못된 번호를 남기느니 버린다
        $account                 = $this->fullKakaoAccount();
        $account['phone_number'] = '+123 4567-8901-2345-6789';

        $this->assertNull($this->kakaoProfile($account)['phone']);
    }

    public function testKakaoMissingOptionalFieldsAreNull(): void
    {
        // 동의 항목을 하나도 켜지 않은 앱 — 기존 동작이 깨지면 안 된다
        $profile = $this->kakaoProfile([
            'profile' => ['nickname' => '최소카카오'],
        ]);

        $this->assertIsArray($profile);
        $this->assertSame('최소카카오', $profile['nickname']);
        $this->assertNull($profile['phone']);
        $this->assertNull($profile['gender']);
        $this->assertNull($profile['birthday']);
    }

    // ── DB 저장 ──────────────────────────────────────────────────────────────

    public function testNewSocialUserPersistsProfileFields(): void
    {
        $profile = $this->naverProfile($this->fullNaverResponse());
        $user    = $this->findOrCreate($profile);

        $this->assertIsArray($user, '신규 소셜 가입이 실패했다');

        $row = db_connect()->table('users')->where('id', $user['id'])->get()->getRowArray();
        $this->assertSame('010-1234-5678', $row['phone']);
        $this->assertSame('M', $row['gender']);
        $this->assertSame('1990-10-01', $row['birthday']);
    }

    public function testNewKakaoSocialUserPersistsProfileFields(): void
    {
        // 저장 로직은 제공자와 무관하다 — 카카오 매핑도 그대로 DB 까지 도달해야 한다
        $profile = $this->kakaoProfile($this->fullKakaoAccount(), 'kid' . substr(uniqid(), -8));
        $user    = $this->findOrCreate($profile, 'kakao');

        $this->assertIsArray($user, '신규 소셜 가입이 실패했다');

        $row = db_connect()->table('users')->where('id', $user['id'])->get()->getRowArray();
        $this->assertSame('010-1234-5678', $row['phone']);
        $this->assertSame('M', $row['gender']);
        $this->assertSame('1990-10-01', $row['birthday']);
    }

    public function testExistingSocialUserGetsEmptyFieldsFilled(): void
    {
        // 이 기능이 없던 시절 가입한 계정은 세 값이 비어 있다 — 재로그인 때 채워야 한다
        $response = $this->fullNaverResponse();

        $first = $this->findOrCreate($this->naverProfile([
            'id'       => $response['id'],
            'nickname' => $response['nickname'],
            'email'    => $response['email'],
        ]));
        $this->assertIsArray($first);

        $db = db_connect();
        $this->assertNull($db->table('users')->where('id', $first['id'])->get()->getRowArray()['phone']);

        $second = $this->findOrCreate($this->naverProfile($response));

        $this->assertIsArray($second);
        $this->assertSame((int) $first['id'], (int) $second['id'], '같은 계정으로 재로그인해야 한다');

        $row = $db->table('users')->where('id', $first['id'])->get()->getRowArray();
        $this->assertSame('010-1234-5678', $row['phone']);
        $this->assertSame('M', $row['gender']);
        $this->assertSame('1990-10-01', $row['birthday']);
    }

    public function testExistingValuesAreNotOverwritten(): void
    {
        // 사용자가 마이페이지에서 직접 고친 값을 소셜 값으로 덮어쓰면 안 된다
        $response = $this->fullNaverResponse();
        $first    = $this->findOrCreate($this->naverProfile($response));
        $this->assertIsArray($first);

        $db = db_connect();
        $db->table('users')->where('id', $first['id'])->update([
            'phone'    => '010-9999-0000',
            'gender'   => 'F',
            'birthday' => '1985-05-05',
        ]);

        $this->findOrCreate($this->naverProfile($response));

        $row = $db->table('users')->where('id', $first['id'])->get()->getRowArray();
        $this->assertSame('010-9999-0000', $row['phone'], '사용자가 수정한 휴대폰번호가 덮어써졌다');
        $this->assertSame('F', $row['gender'], '사용자가 수정한 성별이 덮어써졌다');
        $this->assertSame('1985-05-05', $row['birthday'], '사용자가 수정한 생년월일이 덮어써졌다');
    }
}
