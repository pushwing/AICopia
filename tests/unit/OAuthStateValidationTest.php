<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\SocialAuthController;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * SocialAuthController::callback() state 검증 (이슈 #121)
 *
 * `$state !== session()->get('oauth_state')` 만으로는 양쪽이 모두 null 일 때
 * `null !== null` 이 false 라 검증을 그대로 통과한다. 정상 플로우 완료 후
 * oauth_state 를 제거하므로 대부분의 세션이 이 상태에 있다.
 *
 * 거부 여부는 플래시 메시지로 판정한다 — 통과해서 토큰 교환까지 갔다면
 * 다른 메시지가 나온다.
 */
final class OAuthStateValidationTest extends CIUnitTestCase
{
    /** state 검증에서 거부됐을 때의 메시지 */
    private const string REJECTED = '잘못된 요청입니다. 다시 시도해주세요.';

    protected function setUp(): void
    {
        parent::setUp();
        session()->destroy();
    }

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** @param array<string, string> $query */
    private function callbackWith(array $query, string $provider = 'kakao'): ?string
    {
        service('request')->setGlobal('get', $query);

        $controller = new SocialAuthController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $controller->callback($provider);

        return session()->getFlashdata('error');
    }

    // ── 거부되어야 하는 경우 ──────────────────────────────────────────────────

    public function testRejectsWhenBothQueryAndSessionStateAreAbsent(): void
    {
        // 세션에 oauth_state 가 없는 상태(정상 플로우 후 또는 OAuth 를 시작한 적 없음)에서
        // state 없이 code 만 들고 들어오는 요청 — null !== null 우회
        $error = $this->callbackWith(['code' => 'attacker-authorization-code']);

        $this->assertSame(self::REJECTED, $error, 'state 가 양쪽 모두 비어 있는데 검증을 통과했다');
    }

    public function testRejectsWhenQueryStateIsAbsentButSessionHasOne(): void
    {
        session()->set(['oauth_state' => str_repeat('a', 32), 'oauth_provider' => 'kakao']);

        $error = $this->callbackWith(['code' => 'c']);

        $this->assertSame(self::REJECTED, $error);
    }

    public function testRejectsWhenQueryStateIsEmptyString(): void
    {
        $error = $this->callbackWith(['code' => 'c', 'state' => '']);

        $this->assertSame(self::REJECTED, $error);
    }

    public function testRejectsWhenStateMismatches(): void
    {
        session()->set(['oauth_state' => str_repeat('a', 32), 'oauth_provider' => 'kakao']);

        $error = $this->callbackWith(['code' => 'c', 'state' => str_repeat('b', 32)]);

        $this->assertSame(self::REJECTED, $error);
    }

    public function testRejectsWhenProviderDoesNotMatchTheOneThatStartedTheFlow(): void
    {
        // kakao 로 시작한 플로우의 state 를 google 콜백에 제출
        $state = str_repeat('a', 32);
        session()->set(['oauth_state' => $state, 'oauth_provider' => 'kakao']);

        $error = $this->callbackWith(['code' => 'c', 'state' => $state], 'google');

        $this->assertSame(self::REJECTED, $error, '플로우를 시작한 provider 와 콜백 provider 가 달라도 통과했다');
    }

    public function testRejectsWhenCodeIsMissing(): void
    {
        $state = str_repeat('a', 32);
        session()->set(['oauth_state' => $state, 'oauth_provider' => 'kakao']);

        $error = $this->callbackWith(['state' => $state]);

        $this->assertSame(self::REJECTED, $error);
    }

    // ── 통과해야 하는 경우 ────────────────────────────────────────────────────

    public function testValidStateAndProviderPassStateCheck(): void
    {
        $state = str_repeat('a', 32);
        session()->set(['oauth_state' => $state, 'oauth_provider' => 'kakao']);

        $error = $this->callbackWith(['code' => 'c', 'state' => $state]);

        // 토큰 교환은 네트워크가 필요해 실패하지만, state 검증 자체는 통과해야 한다
        $this->assertNotSame(
            self::REJECTED,
            $error,
            '정상 state·provider 인데 state 검증에서 막혔다',
        );
    }

    public function testUserCancelledIsReportedSeparately(): void
    {
        $error = $this->callbackWith(['error' => 'access_denied']);

        $this->assertSame('소셜 로그인이 취소되었습니다.', $error);
    }
}
