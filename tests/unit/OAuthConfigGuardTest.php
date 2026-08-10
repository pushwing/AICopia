<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\SocialAuthController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 소셜 로그인 앱 키 가드
 *
 * 키(.env 의 oauth.{provider}.client_id 등)가 비어 있어도 그대로 제공자로
 * 리다이렉트하면, 사용자에게는 원인을 알 수 없는 제공자 오류 페이지만 보인다.
 * (네이버: `inform_404.html?error=invalid_request&error_description=client_id is missing`)
 *
 * 키가 없거나 관리자가 꺼둔 제공자는 사이트를 벗어나기 전에 막고,
 * 로그인 페이지에서 안내 메시지를 보여줘야 한다.
 */
final class OAuthConfigGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** 키 미설정 제공자를 눌렀을 때의 메시지 */
    private const string NOT_CONFIGURED = '해당 소셜 로그인은 아직 사용할 수 없습니다. 관리자에게 문의해주세요.';

    /** 관리자가 비활성화한 제공자를 눌렀을 때의 메시지 */
    private const string DISABLED = '현재 사용할 수 없는 로그인 방식입니다.';

    /** @var list<int> 테스트가 삽입한 settings 행 id */
    private array $cleanup = [];

    /** @var array<string, string|null> 테스트 전 $_ENV 백업 */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();

        foreach ($this->envKeys() as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        cache()->delete('site_settings');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->envBackup = [];

        if ($this->cleanup !== []) {
            db_connect()->table('settings')->whereIn('id', $this->cleanup)->delete();
            $this->cleanup = [];
        }

        cache()->delete('site_settings');
        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function envKeys(): array
    {
        $keys = [];
        foreach (['naver', 'kakao', 'google'] as $provider) {
            $keys[] = "oauth.{$provider}.client_id";
            $keys[] = "oauth.{$provider}.client_secret";
        }
        return $keys;
    }

    private function setKeys(string $provider, ?string $clientId, ?string $clientSecret): void
    {
        foreach (['client_id' => $clientId, 'client_secret' => $clientSecret] as $name => $value) {
            $key = "oauth.{$provider}.{$name}";
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    /** SocialAuthController::redirect() 실행 후 [Location, 플래시 에러] 반환 */
    private function startLogin(string $provider): RedirectResponse
    {
        $controller = new SocialAuthController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->redirect($provider);
    }

    private function insertSetting(string $key, string $value): void
    {
        $db = db_connect();
        $db->table('settings')->insert([
            'group'      => 'oauth',
            'key'        => $key,
            'value'      => $value,
            'label'      => '테스트 (' . $key . ')',
            'type'       => 'boolean',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->cleanup[] = (int) $db->insertID();
        cache()->delete('site_settings');
    }

    // ── 키 미설정 가드 ────────────────────────────────────────────────────────

    public function testDoesNotLeaveTheSiteWhenClientIdIsMissing(): void
    {
        $this->setKeys('naver', null, null);

        $response = $this->startLogin('naver');

        $this->assertStringNotContainsString(
            'nid.naver.com',
            $response->getHeaderLine('Location'),
            'client_id 가 비어 있는데도 네이버로 리다이렉트했다 — 사용자는 client_id is missing 오류 페이지를 보게 된다',
        );
        $this->assertStringContainsString('/auth/login', $response->getHeaderLine('Location'));
        $this->assertSame(self::NOT_CONFIGURED, session()->getFlashdata('error'));
    }

    public function testEmptyStringKeyIsTreatedAsMissing(): void
    {
        // .env 에 `oauth.naver.client_id =` 처럼 키만 있고 값이 비어 있는 상태
        $this->setKeys('naver', '', '');

        $response = $this->startLogin('naver');

        $this->assertSame(self::NOT_CONFIGURED, session()->getFlashdata('error'));
        $this->assertStringNotContainsString('nid.naver.com', $response->getHeaderLine('Location'));
    }

    public function testClientSecretMissingIsAlsoTreatedAsUnconfigured(): void
    {
        // client_secret 이 없으면 인가는 통과해도 토큰 교환에서 실패한다
        $this->setKeys('naver', 'test-client-id', null);

        $this->startLogin('naver');

        $this->assertSame(self::NOT_CONFIGURED, session()->getFlashdata('error'));
    }

    public function testKakaoNeedsOnlyClientIdBecauseSecretIsOptional(): void
    {
        // 카카오 Client Secret 은 앱 설정에서 선택 사항이다
        $this->setKeys('kakao', 'test-rest-api-key', null);

        $response = $this->startLogin('kakao');

        $this->assertStringContainsString('kauth.kakao.com', $response->getHeaderLine('Location'));
        $this->assertNull(session()->getFlashdata('error'));
    }

    public function testRedirectsToProviderWhenKeysAreConfigured(): void
    {
        $this->setKeys('naver', 'test-client-id', 'test-client-secret');

        $response = $this->startLogin('naver');
        $location = $response->getHeaderLine('Location');

        $this->assertStringContainsString('nid.naver.com/oauth2.0/authorize', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertNull(session()->getFlashdata('error'), '정상 설정인데 가드에 걸렸다');
    }

    // ── 비활성화 제공자 가드 ──────────────────────────────────────────────────

    public function testDisabledProviderIsRejectedEvenOnDirectUrlAccess(): void
    {
        // 관리자가 /admin/settings/oauth 에서 껐으면 로그인 화면에 버튼이 안 보이지만,
        // /auth/social/naver 로 직접 들어오는 것까지 막히지는 않았다
        $this->setKeys('naver', 'test-client-id', 'test-client-secret');
        $this->insertSetting('oauth_enabled_naver', '0');

        $response = $this->startLogin('naver');

        $this->assertStringNotContainsString('nid.naver.com', $response->getHeaderLine('Location'));
        $this->assertSame(self::DISABLED, session()->getFlashdata('error'));
    }

    public function testEnabledProviderStillWorks(): void
    {
        $this->setKeys('naver', 'test-client-id', 'test-client-secret');
        $this->insertSetting('oauth_enabled_naver', '1');

        $response = $this->startLogin('naver');

        $this->assertStringContainsString('nid.naver.com', $response->getHeaderLine('Location'));
    }

    // ── 관리자 화면 키 설정 배지 ──────────────────────────────────────────────

    public function testAdminOauthViewMarksUnconfiguredProviders(): void
    {
        $html = view('admin/settings/oauth', $this->oauthViewData([
            'naver'  => false,
            'kakao'  => true,
            'google' => false,
        ]));

        // 배지 문구는 안내문에도 등장하므로, 제공자별 title 로 판정한다
        $this->assertStringContainsString('oauth.naver.client_id 가 비어 있습니다', $html);
        $this->assertStringContainsString('oauth.google.client_id 가 비어 있습니다', $html);
        $this->assertStringNotContainsString(
            'oauth.kakao.client_id 가 비어 있습니다',
            $html,
            '키가 설정된 카카오에 미설정 배지가 붙었다',
        );
        $this->assertStringContainsString('키 설정됨', $html);
    }

    /**
     * @param  array<string, bool>  $configured
     * @return array<string, mixed>
     */
    private function oauthViewData(array $configured): array
    {
        return [
            // BaseController::initController() 전역 데이터
            'settings'        => ['site_name' => '테스트몰'],
            'menus'           => [],
            'authUser'        => ['id' => 1, 'nickname' => '관리자', 'role' => 'admin', 'grade' => 'bronze', 'loggedIn' => true],
            'unreadInquiries' => 0,
            'unansweredQna'   => 0,
            'lowStockCount'   => 0,
            'pendingOrders'   => 0,
            'subLeftBanners'  => [],
            'activePopups'    => [],
            'cartCount'       => 0,
            'categories'      => [],
            // SettingController::index('oauth') 페이지 데이터
            'group'           => 'oauth',
            'oauthConfigured' => $configured,
        ];
    }
}
