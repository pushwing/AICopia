<?php

namespace Tests\Unit;

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * UserController::json() 엔드포인트 데이터 레이어 검증
 *
 * 검증 항목:
 *  - 반환 필드 구조 (id, nickname, email, phone, role, grade, social_provider,
 *    is_active, email_verify_token, created_at, last_login)
 *  - email_verify_token 마스킹 ('1' / '' — 실제 토큰 노출 금지)
 *  - 타입 캐스팅 (id → int, is_active → int)
 *  - ID DESC 정렬
 *  - grade null → 'bronze' 기본값 처리
 *  - social_provider null → '' 처리
 */
final class UserJsonApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = ['users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'UJT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->cleanup['users'] !== []) {
            db_connect()->table('users')->whereIn('id', $this->cleanup['users'])->delete();
        }
        $this->cleanup = ['users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertUser(array $overrides = []): int
    {
        $uid  = uniqid();
        $data = array_merge([
            'username'   => 'ujt_' . $uid,
            'email'      => $this->prefix . $uid . '@test.com',
            'password'   => password_hash('test!', PASSWORD_DEFAULT),
            'nickname'   => $this->prefix . $uid,
            'role'       => 'member',
            'grade'      => 'bronze',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);

        $db = db_connect();
        $db->table('users')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;
        return $id;
    }

    /** json() 내부와 동일한 변환 로직 */
    private function fetchJsonData(array $whereIn = []): array
    {
        $builder = (new UserModel())->builder()
            ->select('id, nickname, email, phone, role, grade, social_provider, is_active, email_verify_token, created_at, last_login')
            ->orderBy('id', 'DESC');

        if ($whereIn !== []) {
            $builder->whereIn('id', $whereIn);
        }

        $rows = $builder->get()->getResultArray();

        return array_map(fn($u) => [
            'id'                 => (int) $u['id'],
            'nickname'           => $u['nickname'],
            'email'              => $u['email'],
            'phone'              => $u['phone'] ?? '',
            'role'               => $u['role'],
            'grade'              => $u['grade'] ?? 'bronze',
            'social_provider'    => $u['social_provider'] ?? '',
            'is_active'          => (int) $u['is_active'],
            'email_verify_token' => $u['email_verify_token'] ? '1' : '',
            'created_at'         => $u['created_at'],
            'last_login'         => $u['last_login'] ?? '',
        ], $rows);
    }

    // ── 필드 구조 ──────────────────────────────────────────────────────────────

    public function testRequiredFieldsArePresent(): void
    {
        $id   = $this->insertUser();
        $rows = $this->fetchJsonData([$id]);

        $this->assertNotEmpty($rows);
        $expected = ['id', 'nickname', 'email', 'phone', 'role', 'grade',
                     'social_provider', 'is_active', 'email_verify_token',
                     'created_at', 'last_login'];
        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $rows[0], "필드 '{$field}' 누락");
        }
    }

    // ── 타입 캐스팅 ───────────────────────────────────────────────────────────

    public function testIdIsInteger(): void
    {
        $id   = $this->insertUser();
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['id'], 'id 는 int 여야 한다');
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testIsActiveIsInteger(): void
    {
        $id   = $this->insertUser(['is_active' => 1]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_active'], 'is_active 는 int 여야 한다');
        $this->assertSame(1, $rows[0]['is_active']);
    }

    public function testIsActiveZeroIsInteger(): void
    {
        $id   = $this->insertUser(['is_active' => 0]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertIsInt($rows[0]['is_active']);
        $this->assertSame(0, $rows[0]['is_active']);
    }

    // ── email_verify_token 마스킹 ─────────────────────────────────────────────

    public function testVerifyTokenMaskedAsOneWhenPresent(): void
    {
        $id = $this->insertUser([
            'is_active'          => 0,
            'email_verify_token' => bin2hex(random_bytes(16)),
        ]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('1', $rows[0]['email_verify_token'],
            '미인증 사용자의 email_verify_token 은 "1" 로 마스킹돼야 한다');
    }

    public function testVerifyTokenEmptyWhenNull(): void
    {
        $id   = $this->insertUser(['is_active' => 1, 'email_verify_token' => null]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['email_verify_token'],
            '인증 완료 사용자의 email_verify_token 은 빈 문자열이어야 한다');
    }

    public function testActualTokenValueIsNotExposed(): void
    {
        $realToken = bin2hex(random_bytes(16));
        $id        = $this->insertUser([
            'is_active'          => 0,
            'email_verify_token' => $realToken,
        ]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertNotSame($realToken, $rows[0]['email_verify_token'],
            '실제 토큰 값이 노출되면 안 된다');
    }

    // ── 기본값 처리 ───────────────────────────────────────────────────────────

    public function testGradeNullDefaultsToBronze(): void
    {
        $id = $this->insertUser();
        // grade 를 직접 NULL 로 업데이트
        db_connect()->table('users')->where('id', $id)->update(['grade' => null]);

        $rows = $this->fetchJsonData([$id]);
        $this->assertSame('bronze', $rows[0]['grade'],
            'grade NULL 은 bronze 로 기본값 처리돼야 한다');
    }

    public function testSocialProviderNullBecomesEmptyString(): void
    {
        $id   = $this->insertUser(['social_provider' => null]);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('', $rows[0]['social_provider'],
            'social_provider NULL 은 빈 문자열로 변환돼야 한다');
    }

    public function testSocialProviderIsPreserved(): void
    {
        $id   = $this->insertUser(['social_provider' => 'google']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('google', $rows[0]['social_provider']);
    }

    // ── 정렬 ──────────────────────────────────────────────────────────────────

    public function testOrderedByIdDesc(): void
    {
        $id1 = $this->insertUser();
        $id2 = $this->insertUser();
        $id3 = $this->insertUser();

        $rows = $this->fetchJsonData([$id1, $id2, $id3]);
        $ids  = array_column($rows, 'id');

        $this->assertGreaterThan($ids[1], $ids[0], '첫 번째 행이 두 번째보다 ID 가 커야 한다 (DESC)');
        $this->assertGreaterThan($ids[2], $ids[1], '두 번째 행이 세 번째보다 ID 가 커야 한다 (DESC)');
    }

    // ── 역할 ──────────────────────────────────────────────────────────────────

    public function testAdminRoleIsPreserved(): void
    {
        $id   = $this->insertUser(['role' => 'admin']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('admin', $rows[0]['role']);
    }

    public function testMemberRoleIsPreserved(): void
    {
        $id   = $this->insertUser(['role' => 'member']);
        $rows = $this->fetchJsonData([$id]);

        $this->assertSame('member', $rows[0]['role']);
    }

    // ── last_login null 처리 ──────────────────────────────────────────────────

    public function testLastLoginNullBecomesEmptyString(): void
    {
        $id = $this->insertUser();
        db_connect()->table('users')->where('id', $id)->update(['last_login' => null]);

        $rows = $this->fetchJsonData([$id]);
        $this->assertSame('', $rows[0]['last_login'],
            'last_login NULL 은 빈 문자열로 변환돼야 한다');
    }
}
