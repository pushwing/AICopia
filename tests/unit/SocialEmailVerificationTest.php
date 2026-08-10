<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\SocialAuthController;
use App\Exceptions\SocialEmailNotVerifiedException;
use App\Libraries\OAuth\GoogleProvider;
use App\Libraries\OAuth\KakaoProvider;
use App\Libraries\OAuth\NaverProvider;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use ReflectionMethod;

/**
 * 소셜 로그인 이메일 검증 (이슈 #137)
 *
 * 제공자가 준 이메일이 기존 계정과 같다는 이유만으로 소셜 계정을 붙이면,
 * 미검증 이메일을 설정할 수 있는 제공자(Kakao)를 통해 남의 계정을 탈취할 수 있다.
 * 제공자가 "검증됐다"고 명시한 경우에만 연동해야 한다.
 */
final class SocialEmailVerificationTest extends CIUnitTestCase
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

    private function insertLocalUser(string $email, string $role = 'admin'): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'   => $email,
            'email'      => $email,
            'password'   => password_hash('victim-password', PASSWORD_DEFAULT),
            'nickname'   => 'SEV' . substr(uniqid(), -6),
            'role'       => $role,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup[] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    private function findOrCreate(array $profile, string $provider = 'kakao'): ?array
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

    /** @return array<string, mixed> */
    private function profile(string $email, ?bool $verified): array
    {
        $p = [
            'social_id' => 'sid' . substr(uniqid(), -8),
            'email'     => $email,
            'nickname'  => '공격자',
            'avatar'    => null,
        ];
        if ($verified !== null) {
            $p['email_verified'] = $verified;
        }

        return $p;
    }

    // ── 연동 거부 ─────────────────────────────────────────────────────────────

    public function testUnverifiedEmailDoesNotLinkToExistingAccount(): void
    {
        $email    = 'sev-victim-' . substr(uniqid(), -8) . '@example.test';
        $victimId = $this->insertLocalUser($email, 'admin');

        try {
            $this->findOrCreate($this->profile($email, false));
            $this->fail('미검증 이메일로 기존 계정에 연동됐다');
        } catch (SocialEmailNotVerifiedException $e) {
            $this->assertSame('kakao', $e->provider);
            $this->assertSame($email, $e->email);
        }

        $row = db_connect()->table('users')->where('id', $victimId)->get()->getRowArray();
        $this->assertNull($row['social_provider'], '피해자 계정에 social_provider 가 붙었다');
        $this->assertNull($row['social_id'], '피해자 계정에 social_id 가 붙었다');
    }

    public function testMissingVerifiedFlagIsTreatedAsUnverified(): void
    {
        // 플래그를 아예 안 주는 제공자(Naver)도 안전한 쪽으로 판단해야 한다
        $email    = 'sev-noflag-' . substr(uniqid(), -8) . '@example.test';
        $victimId = $this->insertLocalUser($email, 'member');

        $this->expectException(SocialEmailNotVerifiedException::class);

        try {
            $this->findOrCreate($this->profile($email, null), 'naver');
        } finally {
            $row = db_connect()->table('users')->where('id', $victimId)->get()->getRowArray();
            $this->assertNull($row['social_id'], '검증 플래그가 없는데 연동됐다');
        }
    }

    // ── 연동 허용 ─────────────────────────────────────────────────────────────

    public function testVerifiedEmailLinksToExistingAccount(): void
    {
        $email  = 'sev-ok-' . substr(uniqid(), -8) . '@example.test';
        $userId = $this->insertLocalUser($email, 'member');

        $result = $this->findOrCreate($this->profile($email, true));

        $this->assertIsArray($result, '검증된 이메일인데 연동이 막혔다');
        $this->assertSame($userId, (int) $result['id']);

        $row = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame('kakao', $row['social_provider']);
    }

    public function testNewAccountIsCreatedWhenNoEmailMatch(): void
    {
        $result = $this->findOrCreate(
            $this->profile('sev-new-' . substr(uniqid(), -8) . '@example.test', true),
        );

        $this->assertIsArray($result, '신규 소셜 가입이 막혔다');
        $this->assertSame('kakao', $result['social_provider']);
    }

    public function testExistingSocialIdStillLogsInRegardlessOfEmailFlag(): void
    {
        // 이미 연동을 마친 사용자는 1번 분기(social_id 매칭)로 처리되어 영향받지 않는다
        $profile = $this->profile('sev-linked-' . substr(uniqid(), -8) . '@example.test', true);
        $first   = $this->findOrCreate($profile);
        $this->assertIsArray($first);

        $profile['email_verified'] = false;
        $second = $this->findOrCreate($profile);

        $this->assertIsArray($second, '기존 연동 사용자의 재로그인이 막혔다');
        $this->assertSame((int) $first['id'], (int) $second['id']);
    }

    // ── provider 매핑 ─────────────────────────────────────────────────────────

    public function testGoogleProviderPropagatesVerifiedFlag(): void
    {
        $p = new class () extends GoogleProvider {
            /** @var array<string, mixed> */
            public array $stub = [];

            protected function get(string $url, array $headers = []): array
            {
                return $this->stub;
            }
        };

        $p->stub = ['sub' => '1', 'email' => 'a@b.c', 'email_verified' => true, 'name' => 'n'];
        $this->assertTrue($p->getProfile('t')['email_verified']);

        $p->stub = ['sub' => '1', 'email' => 'a@b.c', 'email_verified' => false, 'name' => 'n'];
        $this->assertFalse($p->getProfile('t')['email_verified']);

        $p->stub = ['sub' => '1', 'email' => 'a@b.c', 'name' => 'n'];
        $this->assertFalse($p->getProfile('t')['email_verified'], '클레임이 없으면 미검증이어야 한다');
    }

    public function testKakaoProviderRequiresBothVerifiedAndValid(): void
    {
        $p = new class () extends KakaoProvider {
            /** @var array<string, mixed> */
            public array $stub = [];

            protected function get(string $url, array $headers = []): array
            {
                return $this->stub;
            }
        };

        $mk = static fn (array $account): array => ['id' => 1, 'kakao_account' => $account];

        $p->stub = $mk(['email' => 'a@b.c', 'is_email_verified' => true, 'is_email_valid' => true]);
        $this->assertTrue($p->getProfile('t')['email_verified']);

        $p->stub = $mk(['email' => 'a@b.c', 'is_email_verified' => false, 'is_email_valid' => true]);
        $this->assertFalse($p->getProfile('t')['email_verified'], '미검증인데 통과했다');

        $p->stub = $mk(['email' => 'a@b.c', 'is_email_verified' => true, 'is_email_valid' => false]);
        $this->assertFalse($p->getProfile('t')['email_verified'], '유효하지 않은 이메일인데 통과했다');

        $p->stub = $mk(['email' => 'a@b.c']);
        $this->assertFalse($p->getProfile('t')['email_verified'], '클레임이 없으면 미검증이어야 한다');
    }

    public function testNaverProviderReportsUnverified(): void
    {
        $p = new class () extends NaverProvider {
            /** @var array<string, mixed> */
            public array $stub = [];

            protected function get(string $url, array $headers = []): array
            {
                return $this->stub;
            }
        };

        $p->stub = ['response' => ['id' => '1', 'email' => 'a@b.c', 'nickname' => 'n']];

        $this->assertFalse(
            $p->getProfile('t')['email_verified'],
            'Naver 는 검증 클레임을 제공하지 않으므로 미검증으로 보고해야 한다',
        );
    }
}
