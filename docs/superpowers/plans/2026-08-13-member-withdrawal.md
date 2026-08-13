# 회원탈퇴 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원이 스스로 탈퇴할 수 있게 하고, 탈퇴 시 개인정보를 `withdrawn_users`로 옮긴 뒤 `users` 행은 마스킹해 남기며, 보관 기간이 지난 개인정보는 배치가 자동 파기한다.

**Architecture:** `users` 행을 삭제하지 않고 마스킹하는 tombstone 방식이다. 이 DB에는 외래키 제약이 하나도 없어서 `users` 행을 지우면 `orders`·`product_reviews`·`posts` 등 8개 테이블의 `user_id`가 조용히 고아가 되기 때문이다. 개인정보 원본은 `withdrawn_users` 한 테이블에 스냅샷으로 모아 두고, 배치는 그 테이블만 파기하면 된다. 탈퇴 유스케이스 전부는 `App\Libraries\WithdrawalService` 하나에 담고 회원 탈퇴·관리자 강제 탈퇴가 같은 경로를 쓴다.

**Tech Stack:** CodeIgniter 4 / PHP 8.5 / MySQL / PHPUnit(`CIUnitTestCase` + `DatabaseTestTrait`)

**Spec:** [docs/superpowers/specs/2026-08-13-member-withdrawal-design.md](../specs/2026-08-13-member-withdrawal-design.md)

## Global Constraints

- 모든 PHP 파일 첫 줄에 `declare(strict_types=1);` — PHP-CS-Fixer가 강제한다.
- PSR-12. 메서드·프로퍼티에 반환 타입 포함 타입 선언 완전 적용(PHPStan 레벨 5).
- 모든 응답·주석·커밋 메시지는 한국어. 커밋은 이모지 + Conventional Commits 접두어.
- **새 시각 컬럼은 반드시 `TIMESTAMP`.** `DATETIME`을 쓰면 `DatabaseTimezoneTest::testNoDatetimeColumnsRemainInSchema()`가 실패한다. 순수 날짜(`birthday`)만 `DATE`.
- 타임존 변환은 MySQL이 전담한다 — PHP에서는 `date('Y-m-d H:i:s')`를 평소대로 쓰고 UTC 변환을 직접 하지 않는다.
- DB 접근은 Query Builder / 바인딩만. 문자열 조합 raw SQL 금지.
- 모든 POST 폼에 `<?= csrf_field() ?>`. 뷰의 모든 출력에 `esc()`.
- 모델에 `$allowedFields` 명시 필수.
- 테스트는 `$DBGroup = 'tests'`, `$migrate = false`, `$refresh = false`. 미리 마이그레이션된 tests DB를 가정하고, 삽입한 행은 `tearDown()`에서 직접 지운다.
- 비즈니스 로직은 컨트롤러가 아니라 `app/Libraries/`의 서비스에 둔다. 컨트롤러는 검증 → 위임 → 응답만.

## 작업 전 준비

이 계획은 마이그레이션을 3개 추가한다. Task 1 커밋 후 반드시 로컬 DB와 테스트 DB에 반영해야 이후 태스크의 테스트가 돈다:

```bash
php spark migrate
```

## 파일 구조

| 파일 | 책임 |
|---|---|
| `app/Database/Migrations/2026-08-13-000001_CreateWithdrawnUsers.php` | 개인정보 스냅샷 테이블 생성 |
| `app/Database/Migrations/2026-08-13-000002_AddWithdrawnAtToUsers.php` | `users.withdrawn_at` tombstone 표식 |
| `app/Database/Migrations/2026-08-13-000003_SeedWithdrawalSettings.php` | 보관일수·스케줄 설정 3행 시드 |
| `app/Models/WithdrawnUserModel.php` | `withdrawn_users` 데이터 접근 — 스냅샷 저장·목록·파기 |
| `app/Exceptions/WithdrawalBlockedException.php` | 차단 사유를 담는 도메인 예외 |
| `app/Libraries/WithdrawalService.php` | 탈퇴 유스케이스 전부 — 차단 판정·탈퇴 실행·파기·소멸자산 집계 |
| `app/Commands/PurgeWithdrawnUsers.php` | 기간 경과 개인정보 파기 배치 |
| `app/Controllers/Front/AuthController.php` (수정) | 탈퇴 탭 데이터 주입 + 탈퇴 처리 |
| `app/Views/auth/profile.php` (수정) | 탈퇴 탭 UI |
| `app/Models/UserModel.php` (수정) | `activeBuilder()` — tombstone 제외 빌더 |
| `app/Controllers/Admin/UserController.php` (수정) | tombstone 목록 제외 + 탈퇴회원 목록 + 삭제→강제탈퇴 교체 |
| `app/Views/admin/users/withdrawn.php` | 관리자 탈퇴회원 목록 |
| `app/Config/Routes.php` (수정) | 탈퇴 POST · 관리자 탈퇴회원 목록 라우트 |
| `app/Config/Tasks.php` (수정) | 배치 잡 매핑 |
| `app/Views/layouts/admin.php` (수정) | 사이드바에 탈퇴회원 링크 |
| `tests/unit/WithdrawalServiceTest.php` | 차단·마스킹·스냅샷·부수정리 검증 |
| `tests/unit/WithdrawalPurgeTest.php` | 파기·멱등성·재가입 검증 |
| `tests/unit/PurgeWithdrawnUsersCommandTest.php` | 배치 커맨드의 설정 게이트·파기 동작 |
| `tests/unit/AdminWithdrawnUserTest.php` | tombstone 목록 제외·탈퇴회원 목록 |
| `tests/unit/AuthProfileTabViewTest.php` (수정) | 탈퇴 탭 렌더링 — 기존 "탭 바 숨김" 케이스가 깨지므로 함께 고친다 |

---

### Task 1: 스키마 — 테이블·컬럼·설정

**Files:**
- Create: `app/Database/Migrations/2026-08-13-000001_CreateWithdrawnUsers.php`
- Create: `app/Database/Migrations/2026-08-13-000002_AddWithdrawnAtToUsers.php`
- Create: `app/Database/Migrations/2026-08-13-000003_SeedWithdrawalSettings.php`

**Interfaces:**
- Consumes: 없음
- Produces: `withdrawn_users` 테이블, `users.withdrawn_at` 컬럼, `settings`의 `withdrawal_retention_days`·`schedule_users_purge_withdrawn_enabled`·`schedule_users_purge_withdrawn_cron` 3행

- [ ] **Step 1: `withdrawn_users` 마이그레이션 작성**

`app/Database/Migrations/2026-08-13-000001_CreateWithdrawnUsers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 탈퇴회원 개인정보 스냅샷 테이블
 *
 * users 행은 삭제하지 않고 마스킹만 하므로(주문·리뷰의 user_id 참조 유지),
 * 개인정보 원본은 이 테이블에 옮겨 보관하다가 보관기간 경과 시 파기한다.
 * 파기는 행 삭제가 아니라 개인정보 컬럼만 NULL 로 비우는 방식이다 —
 * 탈퇴 사유·시점 통계는 남아야 하기 때문이다.
 */
class CreateWithdrawnUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],

            // ── 파기 대상 개인정보 ──
            'username'        => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'nickname'        => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'gender'          => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'birthday'        => ['type' => 'DATE', 'null' => true],
            'avatar'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'social_provider' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'social_id'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'reason_text'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],

            // ── 통계용 메타 (파기하지 않음) ──
            'grade'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'point_balance' => ['type' => 'INT', 'default' => 0],
            'coupon_count'  => ['type' => 'INT', 'default' => 0],
            'order_count'   => ['type' => 'INT', 'default' => 0],
            'joined_at'     => ['type' => 'TIMESTAMP', 'null' => true],

            // ── 탈퇴 정보 (파기하지 않음) ──
            'reason_code'  => [
                'type'       => 'ENUM',
                'constraint' => ['unused', 'price', 'service', 'privacy', 'rejoin', 'admin', 'etc'],
                'default'    => 'etc',
            ],
            'withdrawn_by' => ['type' => 'ENUM', 'constraint' => ['member', 'admin'], 'default' => 'member'],
            'withdrawn_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'purged_at'    => ['type' => 'TIMESTAMP', 'null' => true],

            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id', false, false, 'idx_withdrawn_users_user_id');
        $this->forge->addKey('withdrawn_at', false, false, 'idx_withdrawn_users_withdrawn_at');
        $this->forge->addKey('purged_at', false, false, 'idx_withdrawn_users_purged_at');
        $this->forge->createTable('withdrawn_users');
    }

    public function down(): void
    {
        $this->forge->dropTable('withdrawn_users', true);
    }
}
```

- [ ] **Step 2: `users.withdrawn_at` 마이그레이션 작성**

`app/Database/Migrations/2026-08-13-000002_AddWithdrawnAtToUsers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * users 에 탈퇴 표식 추가
 *
 * is_active=0 은 이미 '이메일 미인증' 계정이 쓰고 있어(Admin\UserController::index()
 * 의 status 필터 참고) 탈퇴 판별에 재사용하면 두 상태가 섞인다. 별도 컬럼을 둔다.
 */
class AddWithdrawnAtToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'withdrawn_at' => ['type' => 'TIMESTAMP', 'null' => true, 'default' => null, 'after' => 'last_login'],
        ]);
        $this->db->query('ALTER TABLE users ADD INDEX idx_users_withdrawn_at (withdrawn_at)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE users DROP INDEX idx_users_withdrawn_at');
        $this->forge->dropColumn('users', 'withdrawn_at');
    }
}
```

- [ ] **Step 3: 설정 시드 마이그레이션 작성**

`app/Database/Migrations/2026-08-13-000003_SeedWithdrawalSettings.php` — 기존 `2026-06-17-000043_SeedScheduleCronSettings`의 삽입 패턴(중복 검사 후 insert)을 그대로 따른다:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedWithdrawalSettings extends Migration
{
    /** @var list<array<string, string>> */
    private array $rows = [
        [
            'group' => 'member',
            'key'   => 'withdrawal_retention_days',
            'value' => '30',
            'label' => '탈퇴회원 개인정보 보관일수',
            'type'  => 'text',
        ],
        [
            'group' => 'schedule',
            'key'   => 'schedule_users_purge_withdrawn_enabled',
            'value' => '1',
            'label' => '탈퇴회원 개인정보 파기',
            'type'  => 'boolean',
        ],
        [
            // 등급 승급(0 3 * * *)과 겹치지 않게 04시
            'group' => 'schedule',
            'key'   => 'schedule_users_purge_withdrawn_cron',
            'value' => '0 4 * * *',
            'label' => '탈퇴회원 개인정보 파기 — 크론 주기',
            'type'  => 'text',
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->rows as $row) {
            if (! $this->db->table('settings')->where('key', $row['key'])->countAllResults()) {
                $this->db->table('settings')->insert([
                    'group'      => $row['group'],
                    'key'        => $row['key'],
                    'value'      => $row['value'],
                    'label'      => $row['label'],
                    'type'       => $row['type'],
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('settings')->whereIn('key', array_column($this->rows, 'key'))->delete();
    }
}
```

- [ ] **Step 4: 마이그레이션 실행**

Run: `php spark migrate`
Expected: 3개 마이그레이션이 `Running:` 으로 표시되고 에러 없이 완료

- [ ] **Step 5: 타임존 스키마 회귀 테스트로 검증**

Run: `composer test -- --filter DatabaseTimezoneTest`
Expected: PASS. 실패하면 `DATETIME` 컬럼을 남긴 것이므로 Step 1~2로 돌아가 `TIMESTAMP`로 고친다.

- [ ] **Step 6: 커밋**

```bash
git add app/Database/Migrations/2026-08-13-00000*.php
git commit -m "✨ feat: 탈퇴회원 테이블·users.withdrawn_at·보관기간 설정 추가"
```

---

### Task 2: 모델과 도메인 예외

**Files:**
- Create: `app/Models/WithdrawnUserModel.php`
- Create: `app/Exceptions/WithdrawalBlockedException.php`

**Interfaces:**
- Consumes: Task 1의 `withdrawn_users` 테이블
- Produces:
  - `WithdrawnUserModel::snapshot(array $user, array $meta, string $reasonCode, ?string $reasonText, string $by): int`
  - `WithdrawnUserModel::purgeOlderThan(int $days): int`
  - `WithdrawnUserModel::findByUserId(int $userId): ?array`
  - `WithdrawalBlockedException::__construct(list<string> $reasons)` + `public readonly array $reasons`

- [ ] **Step 1: 도메인 예외 작성**

`app/Exceptions/WithdrawalBlockedException.php` — 기존 `SocialEmailNotVerifiedException`의 readonly 프로퍼티 패턴을 따른다:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 탈퇴 차단 조건에 걸렸을 때 발생
 *
 * 폼을 그릴 때 canWithdraw() 로 한 번 걸러도, 제출 사이에 주문이 생기거나
 * 상태가 바뀔 수 있다. withdraw() 는 트랜잭션 안에서 재검사하고 이 예외를 던진다.
 */
class WithdrawalBlockedException extends \RuntimeException
{
    /** @param list<string> $reasons 사용자에게 보여줄 차단 사유 목록 */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
```

- [ ] **Step 2: 모델 작성**

`app/Models/WithdrawnUserModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class WithdrawnUserModel extends Model
{
    /** 보관기간 경과 시 NULL 로 비우는 개인정보 컬럼 */
    public const PERSONAL_COLUMNS = [
        'username', 'email', 'nickname', 'phone', 'gender', 'birthday',
        'avatar', 'social_provider', 'social_id', 'reason_text',
    ];

    protected $table         = 'withdrawn_users';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id',
        'username', 'email', 'nickname', 'phone', 'gender', 'birthday',
        'avatar', 'social_provider', 'social_id', 'reason_text',
        'grade', 'point_balance', 'coupon_count', 'order_count', 'joined_at',
        'reason_code', 'withdrawn_by', 'withdrawn_at', 'purged_at',
    ];

    /**
     * 탈퇴 회원의 개인정보 스냅샷 저장
     *
     * @param array<string, mixed> $user 마스킹 전 users 행
     * @param array{point_balance: int, coupon_count: int, order_count: int} $meta
     *
     * @return int 생성된 withdrawn_users.id
     */
    public function snapshot(array $user, array $meta, string $reasonCode, ?string $reasonText, string $by): int
    {
        $this->insert([
            'user_id'         => (int) $user['id'],
            'username'        => $user['username'] ?? null,
            'email'           => $user['email'] ?? null,
            'nickname'        => $user['nickname'] ?? null,
            'phone'           => $user['phone'] ?? null,
            'gender'          => $user['gender'] ?? null,
            'birthday'        => $user['birthday'] ?? null,
            'avatar'          => $user['avatar'] ?? null,
            'social_provider' => $user['social_provider'] ?? null,
            'social_id'       => $user['social_id'] ?? null,
            'reason_text'     => $reasonText,
            'grade'           => $user['grade'] ?? null,
            'point_balance'   => $meta['point_balance'],
            'coupon_count'    => $meta['coupon_count'],
            'order_count'     => $meta['order_count'],
            'joined_at'       => $user['created_at'] ?? null,
            'reason_code'     => $reasonCode,
            'withdrawn_by'    => $by,
            'withdrawn_at'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->getInsertID();
    }

    /** @return array<string, mixed>|null */
    public function findByUserId(int $userId): ?array
    {
        return $this->where('user_id', $userId)->orderBy('id', 'DESC')->first();
    }

    /**
     * 보관기간이 지난 행의 개인정보 컬럼을 NULL 로 비우고 purged_at 기록
     *
     * 행 자체는 지우지 않는다 — 탈퇴 사유·시점 통계는 남아야 한다.
     *
     * @return int 파기한 행 수
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $update = array_fill_keys(self::PERSONAL_COLUMNS, null);
        $update['purged_at'] = date('Y-m-d H:i:s');
        $update['updated_at'] = date('Y-m-d H:i:s');

        $builder = $this->builder()
            ->where('withdrawn_at <', $cutoff)
            ->where('purged_at IS NULL');

        $builder->update($update);

        return $this->db->affectedRows();
    }

    /**
     * 관리자 목록 — 검색어는 이메일·닉네임에만 건다(파기된 행은 NULL 이라 검색되지 않는다)
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginateList(string $keyword, int $page, int $perPage): array
    {
        $builder = $this->builder();

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('email', $keyword)
                ->orLike('nickname', $keyword)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('withdrawn_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }
}
```

- [ ] **Step 3: 정적 분석 통과 확인**

Run: `composer analyse`
Expected: 새 파일에 대한 오류 없음

- [ ] **Step 4: 커밋**

```bash
git add app/Models/WithdrawnUserModel.php app/Exceptions/WithdrawalBlockedException.php
git commit -m "✨ feat: WithdrawnUserModel·WithdrawalBlockedException 추가"
```

---

### Task 3: `WithdrawalService::canWithdraw()` — 차단 판정

**Files:**
- Create: `app/Libraries/WithdrawalService.php`
- Test: `tests/unit/WithdrawalServiceTest.php`

**Interfaces:**
- Consumes: Task 2의 `WithdrawnUserModel`
- Produces: `WithdrawalService::canWithdraw(array $user): array{allowed: bool, reasons: list<string>}`
  - `WithdrawalService::BLOCKING_ORDER_STATUSES` (진행 중 주문 상태 목록)
  - `WithdrawalService::BLOCKING_CLAIM_STATUSES` (반품·교환·환불 상태 목록)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/WithdrawalServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 회원탈퇴 서비스 — 차단 판정과 탈퇴 실행 검증
 *
 * 테스트는 트랜잭션 롤백이 아니라 실제 커밋 + tearDown 수동 정리를 쓴다
 * (ParaTest worker 별 DB 분리 전제 — .claude/rules/testing.md 참고).
 */
final class WithdrawalServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private WithdrawalService $service;

    /** @var array<string, list<int>> */
    private array $cleanup = [
        'withdrawn_users' => [],
        'point_logs'      => [],
        'orders'          => [],
        'users'           => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WithdrawalService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['withdrawn_users', 'point_logs', 'orders', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['withdrawn_users' => [], 'point_logs' => [], 'orders' => [], 'users' => []];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function insertUser(array $extra = []): int
    {
        $uid = 'WD' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert(array_merge([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'grade'      => 'bronze',
            'phone'      => '01012345678',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function insertOrder(int $userId, string $status): int
    {
        // receiver_name·receiver_phone·zipcode·address1 은 NOT NULL 에 기본값이 없다 — 반드시 채운다
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'        => $userId,
            'order_number'   => 'WD' . strtoupper(substr(uniqid(), -10)),
            'status'         => $status,
            'total_amount'   => 10000,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'zipcode'        => '06134',
            'address1'       => '서울시 강남구 테헤란로',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['orders'][] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function reload(int $userId): array
    {
        return (array) new UserModel()->find($userId);
    }

    // ── canWithdraw() ────────────────────────────────────────────────────────

    public function testAdminCannotWithdraw(): void
    {
        $id     = $this->insertUser(['role' => 'admin']);
        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('관리자', implode(' ', $result['reasons']));
    }

    public function testMemberWithNoOrdersCanWithdraw(): void
    {
        $id     = $this->insertUser();
        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testInProgressOrderBlocksWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'shipped');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('진행 중인 주문', implode(' ', $result['reasons']));
    }

    public function testReturnRequestBlocksWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'return_requested');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('반품', implode(' ', $result['reasons']));
    }

    public function testDeliveredOrderDoesNotBlockWithdrawal(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'delivered');

        $result = $this->service->canWithdraw($this->reload($id));

        $this->assertTrue($result['allowed']);
    }
}
```

- [ ] **Step 2: 테스트 실행 — 실패 확인**

Run: `composer test -- --filter WithdrawalServiceTest`
Expected: FAIL — `Class "App\Libraries\WithdrawalService" not found`

- [ ] **Step 3: 서비스 최소 구현**

`app/Libraries/WithdrawalService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\WithdrawnUserModel;

/**
 * 회원탈퇴 유스케이스
 *
 * users 행은 삭제하지 않고 마스킹한다. 이 DB 에는 외래키 제약이 없어서
 * 행을 지워도 에러는 안 나지만 orders·product_reviews·posts 등 8개 테이블의
 * user_id 가 조용히 고아가 되기 때문이다.
 */
class WithdrawalService
{
    /** 결제·배송이 진행 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_ORDER_STATUSES = [
        'pending', 'awaiting_payment', 'paid', 'preparing', 'shipped',
    ];

    /** 반품·교환·환불 처리 중이라 탈퇴를 막아야 하는 주문 상태 */
    public const BLOCKING_CLAIM_STATUSES = [
        'refund_requested', 'return_requested', 'return_approved',
        'exchange_requested', 'exchange_approved',
    ];

    private readonly WithdrawnUserModel $withdrawnUserModel;

    public function __construct()
    {
        $this->withdrawnUserModel = new WithdrawnUserModel();
    }

    /**
     * 탈퇴 가능 여부 판정
     *
     * @param array<string, mixed> $user users 행
     *
     * @return array{allowed: bool, reasons: list<string>}
     */
    public function canWithdraw(array $user): array
    {
        $reasons = [];

        if (($user['role'] ?? 'member') === 'admin') {
            $reasons[] = '관리자 계정은 탈퇴할 수 없습니다.';
        }

        $counts = $this->countBlockingOrders((int) $user['id']);

        if ($counts['order'] > 0) {
            $reasons[] = "진행 중인 주문이 {$counts['order']}건 있습니다. 배송 완료 후 탈퇴해 주세요.";
        }
        if ($counts['claim'] > 0) {
            $reasons[] = "처리 중인 반품·교환·환불이 {$counts['claim']}건 있습니다.";
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * 차단 대상 주문을 한 번의 조회로 상태별 집계 (N+1 방지)
     *
     * @return array{order: int, claim: int}
     */
    private function countBlockingOrders(int $userId): array
    {
        $all = array_merge(self::BLOCKING_ORDER_STATUSES, self::BLOCKING_CLAIM_STATUSES);

        $rows = db_connect()->table('orders')
            ->select('status, COUNT(*) AS cnt')
            ->where('user_id', $userId)
            ->whereIn('status', $all)
            ->groupBy('status')
            ->get()->getResultArray();

        $order = 0;
        $claim = 0;
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            if (in_array($row['status'], self::BLOCKING_ORDER_STATUSES, true)) {
                $order += $cnt;
            } else {
                $claim += $cnt;
            }
        }

        return ['order' => $order, 'claim' => $claim];
    }
}
```

- [ ] **Step 4: 테스트 실행 — 통과 확인**

Run: `composer test -- --filter WithdrawalServiceTest`
Expected: PASS (5개 테스트)

- [ ] **Step 5: 커밋**

```bash
git add app/Libraries/WithdrawalService.php tests/unit/WithdrawalServiceTest.php
git commit -m "✨ feat: 탈퇴 차단 조건 판정(canWithdraw) 구현"
```

---

### Task 4: `WithdrawalService::withdraw()` — 탈퇴 실행

**Files:**
- Modify: `app/Libraries/WithdrawalService.php`
- Test: `tests/unit/WithdrawalServiceTest.php` (테스트 추가)

**Interfaces:**
- Consumes: Task 3의 `canWithdraw()`, Task 2의 `WithdrawnUserModel::snapshot()`
- Produces:
  - `WithdrawalService::withdraw(int $userId, string $reasonCode, ?string $reasonText = null, string $by = 'member'): void`
  - `WithdrawalService::forfeitSummary(int $userId): array{point: int, coupon: int}`
  - `WithdrawalService::REASON_CODES` (사유 코드 → 한국어 라벨 맵)

- [ ] **Step 1: 실패하는 테스트 추가**

`tests/unit/WithdrawalServiceTest.php`의 `testDeliveredOrderDoesNotBlockWithdrawal()` 아래에 추가:

```php
    // ── withdraw() ───────────────────────────────────────────────────────────

    public function testWithdrawSnapshotsPersonalDataAndMasksUser(): void
    {
        $id       = $this->insertUser();
        $original = $this->reload($id);

        $this->service->withdraw($id, 'unused', '자주 안 써서요');

        // 스냅샷에 원본 개인정보가 그대로 있다
        $snapshot = new \App\Models\WithdrawnUserModel()->findByUserId($id);
        $this->assertNotNull($snapshot);
        $this->cleanup['withdrawn_users'][] = (int) $snapshot['id'];
        $this->assertSame($original['email'], $snapshot['email']);
        $this->assertSame('01012345678', $snapshot['phone']);
        $this->assertSame('bronze', $snapshot['grade']);
        $this->assertSame('unused', $snapshot['reason_code']);
        $this->assertSame('자주 안 써서요', $snapshot['reason_text']);
        $this->assertSame('member', $snapshot['withdrawn_by']);
        $this->assertNotNull($snapshot['withdrawn_at']);
        $this->assertNull($snapshot['purged_at']);

        // users 행은 남아 있고 마스킹돼 있다
        $masked = $this->reload($id);
        $this->assertNotSame([], $masked);
        $this->assertSame("withdrawn_{$id}@deleted.local", $masked['email']);
        $this->assertSame("withdrawn_{$id}", $masked['username']);
        $this->assertSame('탈퇴회원', $masked['nickname']);
        $this->assertNull($masked['phone']);
        $this->assertNull($masked['social_provider']);
        $this->assertNull($masked['social_id']);
        $this->assertSame(0, (int) $masked['is_active']);
        $this->assertNotNull($masked['withdrawn_at']);
    }

    public function testWithdrawnUserCannotLogIn(): void
    {
        $id       = $this->insertUser();
        $original = $this->reload($id);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $this->assertNull(new UserModel()->findByEmail($original['email']));
    }

    public function testWithdrawnSocialUserCannotLogIn(): void
    {
        $id = $this->insertUser(['social_provider' => 'google', 'social_id' => 'g-' . uniqid()]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $found = db_connect()->table('users')
            ->where('social_provider', 'google')
            ->where('id', $id)
            ->get()->getRowArray();
        $this->assertNull($found);
    }

    public function testWithdrawWipesPasswordSoVerifyFails(): void
    {
        $id = $this->insertUser();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $masked = $this->reload($id);
        $this->assertFalse(password_verify('pw1234', $masked['password']));
    }

    public function testWithdrawForfeitsPointsAndLogsIt(): void
    {
        $id = $this->insertUser(['point_balance' => 5000]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $masked = $this->reload($id);
        $this->assertSame(0, (int) $masked['point_balance']);

        $log = db_connect()->table('point_logs')
            ->where('user_id', $id)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->cleanup['point_logs'][] = (int) $log['id'];
        $this->assertSame(-5000, (int) $log['amount']);
        $this->assertSame('admin', $log['type']);
    }

    public function testWithdrawIsBlockedWhenOrderInProgress(): void
    {
        $id = $this->insertUser();
        $this->insertOrder($id, 'paid');

        $this->expectException(\App\Exceptions\WithdrawalBlockedException::class);
        $this->service->withdraw($id, 'etc', null);
    }

    public function testWithdrawClearsCartWishlistAndAddresses(): void
    {
        $id = $this->insertUser();
        $db = db_connect();

        $db->table('wishlists')->insert([
            'user_id'    => $id,
            'product_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('shipping_addresses')->insert([
            'user_id'        => $id,
            'receiver_name'  => '홍길동',
            'receiver_phone' => '01012345678',
            'zipcode'        => '06134',
            'address1'       => '서울시 강남구',
            'address2'       => '101호',
            'is_default'     => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $this->assertSame(0, $db->table('wishlists')->where('user_id', $id)->countAllResults());
        $this->assertSame(0, $db->table('shipping_addresses')->where('user_id', $id)->countAllResults());
        $this->assertSame(0, $db->table('cart_items')->where('user_id', $id)->countAllResults());
    }

    public function testWithdrawExpiresUnusedCouponsWithoutDeletingRows(): void
    {
        $id = $this->insertUser();
        $db = db_connect();

        $db->table('coupons')->insert([
            'code'             => 'WDC-' . strtoupper(substr(uniqid(), -8)),
            'name'             => '탈퇴테스트쿠폰',
            'type'             => 'fixed',
            'discount_value'   => 3000,
            'min_order_amount' => 0,
            'is_active'        => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $couponId = (int) $db->insertID();

        $db->table('user_coupons')->insert([
            'user_id'    => $id,
            'coupon_id'  => $couponId,
            'status'     => 'issued',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $userCouponId = (int) $db->insertID();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();

        // 행을 지우면 uniq(user_id, coupon_id) 가 지탱하는 재발급 이력이 깨진다
        $this->assertNotNull($uc, '쿠폰 행은 삭제하지 않고 상태만 바꿔야 한다');
        $this->assertSame('expired', $uc['status']);

        $db->table('user_coupons')->where('id', $userCouponId)->delete();
        $db->table('coupons')->where('id', $couponId)->delete();
    }

    public function testOrderReferenceSurvivesWithdrawal(): void
    {
        $id      = $this->insertUser();
        $orderId = $this->insertOrder($id, 'delivered');

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);

        $row = db_connect()->table('orders')
            ->select('orders.id, users.nickname')
            ->join('users', 'users.id = orders.user_id')
            ->where('orders.id', $orderId)
            ->get()->getRowArray();

        $this->assertNotNull($row, '탈퇴 후에도 주문→회원 조인이 살아 있어야 한다');
        $this->assertSame('탈퇴회원', $row['nickname']);
    }

    public function testSecondWithdrawIsNoOp(): void
    {
        $id = $this->insertUser();

        $this->service->withdraw($id, 'etc', null);
        $this->trackSnapshot($id);
        $this->service->withdraw($id, 'etc', null);   // 예외 없이 조용히 통과

        $count = db_connect()->table('withdrawn_users')->where('user_id', $id)->countAllResults();
        $this->assertSame(1, $count, '중복 탈퇴로 스냅샷이 두 번 쌓이면 안 된다');
    }
```

그리고 헬퍼 영역(`reload()` 아래)에 정리용 헬퍼를 추가한다:

```php
    /** 탈퇴로 생성된 스냅샷·포인트 로그를 tearDown 정리 목록에 등록 */
    private function trackSnapshot(int $userId): void
    {
        $db  = db_connect();
        $row = $db->table('withdrawn_users')->where('user_id', $userId)->get()->getRowArray();
        if ($row !== null) {
            $this->cleanup['withdrawn_users'][] = (int) $row['id'];
        }
        foreach ($db->table('point_logs')->where('user_id', $userId)->get()->getResultArray() as $log) {
            $this->cleanup['point_logs'][] = (int) $log['id'];
        }
    }
```

- [ ] **Step 2: 테스트 실행 — 실패 확인**

Run: `composer test -- --filter WithdrawalServiceTest`
Expected: FAIL — `Call to undefined method App\Libraries\WithdrawalService::withdraw()`

- [ ] **Step 3: `withdraw()`·`forfeitSummary()` 구현**

`app/Libraries/WithdrawalService.php`의 `use` 절에 예외를 추가하고:

```php
use App\Exceptions\WithdrawalBlockedException;
use App\Models\UserModel;
```

클래스 상단 상수에 사유 라벨을 추가:

```php
    /** 탈퇴 사유 코드 → 화면 라벨 */
    public const REASON_CODES = [
        'unused'  => '이용하지 않아서',
        'price'   => '가격·혜택이 아쉬워서',
        'service' => '서비스·상품이 만족스럽지 않아서',
        'privacy' => '개인정보가 걱정되어서',
        'rejoin'  => '다른 계정으로 재가입하려고',
        'admin'   => '관리자 처리',
        'etc'     => '기타',
    ];
```

생성자에 `UserModel`을 추가하고, `countBlockingOrders()` 위에 다음 메서드들을 넣는다:

```php
    /**
     * 탈퇴 처리 — 개인정보 스냅샷 → users 마스킹 → 부수 데이터 정리
     *
     * 세션 파기는 하지 않는다. 관리자 강제 탈퇴에서도 쓰이므로 호출자의
     * 세션을 건드리면 안 된다 — 세션 처리는 컨트롤러 책임이다.
     *
     * @throws WithdrawalBlockedException 차단 조건에 걸린 경우
     */
    public function withdraw(int $userId, string $reasonCode, ?string $reasonText = null, string $by = 'member'): void
    {
        $db = db_connect();
        $db->transStart();

        // 폼을 그린 시점과 제출 시점 사이에 주문이 생겼을 수 있다 — 트랜잭션 안에서 재검사
        $user = $this->userModel->find($userId);
        if (! is_array($user)) {
            $db->transComplete();

            return;
        }

        // 이미 탈퇴한 회원의 재요청은 조용히 통과 (멱등)
        if (! empty($user['withdrawn_at'])) {
            $db->transComplete();

            return;
        }

        $check = $this->canWithdraw($user);
        if (! $check['allowed']) {
            $db->transRollback();

            throw new WithdrawalBlockedException($check['reasons']);
        }

        $forfeit = $this->forfeitSummary($userId);
        $meta    = [
            'point_balance' => $forfeit['point'],
            'coupon_count'  => $forfeit['coupon'],
            'order_count'   => $db->table('orders')->where('user_id', $userId)->countAllResults(),
        ];

        $this->withdrawnUserModel->snapshot($user, $meta, $reasonCode, $reasonText, $by);

        $this->maskUser($userId);
        $this->cleanupPersonalData($userId, $forfeit['point']);

        $db->transComplete();
    }

    /**
     * 탈퇴 시 소멸되는 자산 (화면 경고용)
     *
     * @return array{point: int, coupon: int}
     */
    public function forfeitSummary(int $userId): array
    {
        $db   = db_connect();
        $user = $db->table('users')->select('point_balance')->where('id', $userId)->get()->getRowArray();

        return [
            'point'  => (int) ($user['point_balance'] ?? 0),
            'coupon' => $db->table('user_coupons')
                ->where('user_id', $userId)
                ->where('status', 'issued')
                ->countAllResults(),
        ];
    }

    /**
     * users 행 마스킹
     *
     * email 은 UNIQUE 이므로 id 기반 고유값으로 바꾼다. 그러면 원래 이메일이
     * 해방되어 재가입도 가능해진다. social_* 와 email_verify_token 은
     * 각각 unique_social·uq_email_verify_token UNIQUE 에 걸려 있어 NULL 로 비운다
     * (MySQL 은 UNIQUE 인덱스에서 NULL 중복을 허용한다).
     */
    private function maskUser(int $userId): void
    {
        db_connect()->table('users')->where('id', $userId)->update([
            'email'                 => "withdrawn_{$userId}@deleted.local",
            'username'              => "withdrawn_{$userId}",
            'nickname'              => '탈퇴회원',
            'password'              => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'phone'                 => null,
            'gender'                => null,
            'birthday'              => null,
            'avatar'                => null,
            'social_provider'       => null,
            'social_id'             => null,
            'social_token'          => null,
            'email_verify_token'    => null,
            'email_verify_token_at' => null,
            'point_balance'         => 0,
            'is_active'             => 0,
            'withdrawn_at'          => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    /** 개인정보가 남는 부수 테이블 정리 + 포인트·쿠폰 소멸 */
    private function cleanupPersonalData(int $userId, int $pointBalance): void
    {
        $db = db_connect();

        // 배송지·장바구니·찜·재입고알림에는 개인정보나 취향 정보가 남는다
        foreach (['cart_items', 'wishlists', 'shipping_addresses', 'restock_alerts'] as $table) {
            $db->table($table)->where('user_id', $userId)->delete();
        }

        // 쿠폰은 행을 지우지 않는다 — uniq(user_id, coupon_id) 가 재발급 이력을 지탱한다
        $db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('status', 'issued')
            ->update(['status' => 'expired']);

        // 포인트 소멸 기록. point_logs.type ENUM 에 'withdraw' 가 없어 'admin' 을 쓴다
        if ($pointBalance > 0) {
            $db->table('point_logs')->insert([
                'user_id'    => $userId,
                'type'       => 'admin',
                'amount'     => -$pointBalance,
                'note'       => '회원탈퇴로 인한 포인트 소멸',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
```

생성자를 다음으로 교체:

```php
    private readonly WithdrawnUserModel $withdrawnUserModel;
    private readonly UserModel $userModel;

    public function __construct()
    {
        $this->withdrawnUserModel = new WithdrawnUserModel();
        $this->userModel          = new UserModel();
    }
```

- [ ] **Step 4: 테스트 실행 — 통과 확인**

Run: `composer test -- --filter WithdrawalServiceTest`
Expected: PASS (13개 테스트)

- [ ] **Step 5: 커밋**

```bash
git add app/Libraries/WithdrawalService.php tests/unit/WithdrawalServiceTest.php
git commit -m "✨ feat: 탈퇴 실행(withdraw) — 스냅샷·마스킹·부수데이터 정리"
```

---

### Task 5: 개인정보 파기 + 재가입

**Files:**
- Modify: `app/Libraries/WithdrawalService.php`
- Test: `tests/unit/WithdrawalPurgeTest.php`

**Interfaces:**
- Consumes: Task 4의 `withdraw()`, Task 2의 `WithdrawnUserModel::purgeOlderThan()`
- Produces: `WithdrawalService::purgeExpired(int $retentionDays): int`

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/WithdrawalPurgeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 보관기간 경과 개인정보 파기 검증
 *
 * 파기는 행 삭제가 아니라 개인정보 컬럼만 NULL 로 비우는 방식이다.
 * 탈퇴 사유·시점 통계는 남아야 한다.
 */
final class WithdrawalPurgeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private WithdrawalService $service;

    /** @var array<string, list<int>> */
    private array $cleanup = ['withdrawn_users' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WithdrawalService();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['withdrawn_users', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['withdrawn_users' => [], 'users' => []];
        parent::tearDown();
    }

    /** 탈퇴한 지 $daysAgo 일 된 스냅샷 행을 직접 만든다 */
    private function insertSnapshot(int $daysAgo): int
    {
        $uid = 'WP' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('withdrawn_users')->insert([
            'user_id'      => 900000 + random_int(1, 99999),
            'username'     => $uid,
            'email'        => $uid . '@example.test',
            'nickname'     => $uid,
            'phone'        => '01098765432',
            'reason_text'  => '개인적인 사유입니다',
            'grade'        => 'gold',
            'reason_code'  => 'privacy',
            'withdrawn_by' => 'member',
            'withdrawn_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['withdrawn_users'][] = $id;

        return $id;
    }

    public function testExpiredSnapshotIsPurged(): void
    {
        $id = $this->insertSnapshot(40);

        $purged = $this->service->purgeExpired(30);
        $this->assertGreaterThanOrEqual(1, $purged);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['nickname']);
        $this->assertNull($row['reason_text']);
        $this->assertNotNull($row['purged_at']);
    }

    public function testStatsSurvivePurge(): void
    {
        $id = $this->insertSnapshot(40);
        $this->service->purgeExpired(30);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertSame('privacy', $row['reason_code'], '탈퇴 사유는 통계용이라 남아야 한다');
        $this->assertSame('gold', $row['grade']);
        $this->assertNotNull($row['withdrawn_at']);
    }

    public function testRecentSnapshotIsNotPurged(): void
    {
        $id = $this->insertSnapshot(5);
        $this->service->purgeExpired(30);

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNotNull($row['email'], '보관기간 안이면 파기하지 않는다');
        $this->assertNull($row['purged_at']);
    }

    public function testPurgeIsIdempotent(): void
    {
        $id = $this->insertSnapshot(40);

        $this->service->purgeExpired(30);
        $first = new WithdrawnUserModel()->find($id)['purged_at'];

        $second = $this->service->purgeExpired(30);

        $this->assertSame(0, $second, '이미 파기된 행을 다시 세면 안 된다');
        $this->assertSame($first, new WithdrawnUserModel()->find($id)['purged_at']);
    }

    public function testRejoinWithSameEmailSucceeds(): void
    {
        $email = 'WP' . substr(uniqid(), -8) . '@example.test';
        $db    = db_connect();

        $db->table('users')->insert([
            'username'   => 'rejoin1',
            'email'      => $email,
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => 'rejoin1',
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $firstId = (int) $db->insertID();
        $this->cleanup['users'][] = $firstId;

        $this->service->withdraw($firstId, 'rejoin', null);
        $snap = $db->table('withdrawn_users')->where('user_id', $firstId)->get()->getRowArray();
        $this->cleanup['withdrawn_users'][] = (int) $snap['id'];

        // 같은 이메일로 재가입 — UNIQUE 충돌이 나면 안 된다
        $db->table('users')->insert([
            'username'   => 'rejoin2',
            'email'      => $email,
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => 'rejoin2',
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $secondId = (int) $db->insertID();
        $this->cleanup['users'][] = $secondId;

        $this->assertGreaterThan(0, $secondId);
        $this->assertNotSame($firstId, $secondId);
    }
}
```

- [ ] **Step 2: 테스트 실행 — 실패 확인**

Run: `composer test -- --filter WithdrawalPurgeTest`
Expected: FAIL — `Call to undefined method App\Libraries\WithdrawalService::purgeExpired()`

- [ ] **Step 3: `purgeExpired()` 구현**

`app/Libraries/WithdrawalService.php`의 `forfeitSummary()` 아래에 추가:

```php
    /**
     * 보관기간이 지난 탈퇴회원의 개인정보 파기
     *
     * @return int 파기한 행 수
     */
    public function purgeExpired(int $retentionDays): int
    {
        return $this->withdrawnUserModel->purgeOlderThan($retentionDays);
    }
```

- [ ] **Step 4: 테스트 실행 — 통과 확인**

Run: `composer test -- --filter WithdrawalPurgeTest`
Expected: PASS (5개 테스트)

- [ ] **Step 5: 커밋**

```bash
git add app/Libraries/WithdrawalService.php tests/unit/WithdrawalPurgeTest.php
git commit -m "✨ feat: 보관기간 경과 개인정보 파기(purgeExpired) 구현"
```

---

### Task 6: 회원 탈퇴 화면

**Files:**
- Modify: `app/Controllers/Front/AuthController.php:248-254` (`profile()`), 파일 끝에 `withdrawProcess()` 추가
- Modify: `app/Views/auth/profile.php:13-46` (탭 바), 파일 끝에 탈퇴 탭 블록 추가
- Modify: `app/Config/Routes.php:27` 부근

**Interfaces:**
- Consumes: `WithdrawalService::canWithdraw()`·`forfeitSummary()`·`withdraw()`·`REASON_CODES`
- Produces: `POST /auth/withdraw` 엔드포인트, `GET /auth/profile?tab=withdraw` 화면

- [ ] **Step 1: 라우트 추가**

`app/Config/Routes.php`의 `auth/profile` POST 라우트(27번째 줄) 바로 아래에 추가:

```php
$routes->post('auth/withdraw', 'Front\AuthController::withdrawProcess', ['filter' => 'auth:member']);
```

`?tab=withdraw`는 기존 `GET auth/profile`이 처리하므로 GET 라우트는 필요 없다.

- [ ] **Step 2: 컨트롤러 — `profile()` 수정**

`app/Controllers/Front/AuthController.php`의 `profile()`을 다음으로 교체한다:

```php
    public function profile(): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $user      = $this->userModel->find(session()->get('user_id'));
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
```

`use` 절에 추가: `use App\Libraries\WithdrawalService;`

- [ ] **Step 3: 컨트롤러 — `withdrawProcess()` 추가**

`AuthController` 클래스 끝(마지막 메서드 뒤)에 추가:

```php
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
```

`use` 절에 추가: `use App\Exceptions\WithdrawalBlockedException;`

- [ ] **Step 4: 뷰 — 탭 바 수정**

`app/Views/auth/profile.php`의 PHP 헤더 블록(13~21번째 줄 주변)을 교체한다. 기존에는 소셜 계정이면 탭이 하나뿐이라 탭 바 자체를 숨겼지만, 이제 탈퇴 탭이 항상 있으므로 **탭 바는 항상 그린다**:

```php
// 소셜 로그인 계정은 비밀번호가 없어 '비밀번호 변경' 탭을 제공하지 않는다.
$canChangePassword = empty($user['social_provider']);

$showPasswordTab = $canChangePassword && $activeTab === 'password';
$showWithdrawTab = $activeTab === 'withdraw';
$showInfoTab     = ! $showPasswordTab && ! $showWithdrawTab;
```

탭 바 블록(40~47번째 줄)을 교체:

```php
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $showInfoTab ? 'active' : '' ?>" href="/auth/profile">기본 정보</a>
        </li>
        <?php if ($canChangePassword): ?>
        <li class="nav-item">
            <a class="nav-link <?= $showPasswordTab ? 'active' : '' ?>" href="/auth/profile?tab=password">비밀번호 변경</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link text-danger <?= $showWithdrawTab ? 'active' : '' ?>" href="/auth/profile?tab=withdraw">회원 탈퇴</a>
        </li>
    </ul>
```

기존 기본정보 탭의 조건 `<?php if (! $showPasswordTab): ?>`를 `<?php if ($showInfoTab): ?>`로 바꾼다.

- [ ] **Step 5: 뷰 — 탈퇴 탭 블록 추가**

기존 비밀번호 탭 블록이 끝나는 지점(`<?php endif; ?>` 뒤, `</div>` 닫기 전)에 추가:

```php
    <?php if ($showWithdrawTab): ?>
    <!-- ── 회원 탈퇴 탭 ── -->
    <div class="card border-danger">
        <div class="card-body">
            <?php if (! ($withdrawal['allowed'] ?? false)): ?>
                <div class="alert alert-warning mb-0">
                    <h6 class="fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>지금은 탈퇴할 수 없습니다</h6>
                    <ul class="mb-0 ps-3">
                        <?php foreach (($withdrawal['reasons'] ?? []) as $reason): ?>
                        <li><?= esc($reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <h6 class="fw-bold"><i class="bi bi-exclamation-octagon me-2"></i>탈퇴 시 아래 항목이 소멸됩니다</h6>
                    <ul class="mb-0 ps-3">
                        <li>보유 포인트 <strong><?= esc(number_format($forfeit['point'])) ?>점</strong></li>
                        <li>미사용 쿠폰 <strong><?= esc((string) $forfeit['coupon']) ?>장</strong></li>
                        <li>장바구니 · 찜 목록 · 저장된 배송지</li>
                    </ul>
                    <hr>
                    <p class="mb-0 small">
                        주문 내역은 전자상거래법에 따라 5년간 보관되며, 회원정보는 일정 기간 후 파기됩니다.
                        탈퇴 후에는 복구할 수 없습니다.
                    </p>
                </div>

                <form method="post" action="/auth/withdraw">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label">탈퇴 사유</label>
                        <?php foreach (\App\Libraries\WithdrawalService::REASON_CODES as $code => $label): ?>
                            <?php if ($code === 'admin') { continue; } ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reason_code"
                                       id="reason_<?= esc($code) ?>" value="<?= esc($code) ?>"
                                       <?= $code === 'unused' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="reason_<?= esc($code) ?>"><?= esc($label) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="reason_text">상세 사유 (선택)</label>
                        <textarea class="form-control" id="reason_text" name="reason_text" rows="3"
                                  maxlength="500" placeholder="개선에 참고하겠습니다."></textarea>
                    </div>

                    <?php if (empty($user['social_provider'])): ?>
                    <div class="mb-3">
                        <label class="form-label" for="withdraw_password">비밀번호 확인</label>
                        <input type="password" class="form-control" id="withdraw_password" name="password" required>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_text">확인을 위해 <strong>탈퇴합니다</strong> 를 입력해 주세요</label>
                        <input type="text" class="form-control" id="confirm_text" name="confirm_text" required>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('정말 탈퇴하시겠습니까? 복구할 수 없습니다.')">
                        회원 탈퇴
                    </button>
                    <a href="/auth/profile" class="btn btn-outline-secondary">취소</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 6: 깨지는 기존 테스트 수정**

`tests/unit/AuthProfileTabViewTest.php:59`의 `testSocialAccountDoesNotRenderLoneTabBar()`는 **소셜 계정에 `nav-tabs`가 없어야 한다**고 단언한다. 탈퇴 탭이 항상 생기면서 탭이 최소 2개가 됐으므로 이 전제가 사라졌다 — 테스트를 지우지 말고 새 사실에 맞게 고친다:

```php
    public function testSocialAccountRendersTabBarWithoutPasswordTab(): void
    {
        // 탈퇴 탭이 생기면서 소셜 계정도 탭이 2개(기본 정보·회원 탈퇴)가 됐다.
        // 탭 바를 숨기던 예전 동작은 더 이상 맞지 않는다 — 비밀번호 탭만 없으면 된다.
        $html = view('auth/profile', $this->viewData('naver'));

        $this->assertStringContainsString('nav-tabs', $html);
        $this->assertStringContainsString('회원 탈퇴', $html);
        $this->assertStringNotContainsString('비밀번호 변경', $html);
    }
```

클래스 상단 독블록의 "탭이 하나뿐이면 탭 바 자체를 렌더링하지 않는다" 문장도 현재 동작에 맞게 고친다.

- [ ] **Step 7: 탈퇴 탭 렌더링 테스트 추가**

같은 파일의 `viewData()`는 탈퇴 탭에 필요한 `withdrawal`·`forfeit` 키를 주지 않는다. 헬퍼를 하나 더 만들고 케이스 3개를 추가한다:

```php
    /**
     * 탈퇴 탭용 뷰 데이터 (AuthController::profile()이 tab=withdraw 일 때 추가하는 키 포함)
     *
     * @param list<string> $blockReasons
     *
     * @return array<string, mixed>
     */
    private function withdrawViewData(?string $socialProvider, array $blockReasons = []): array
    {
        $data = $this->viewData($socialProvider, 'withdraw');
        $data['withdrawal'] = ['allowed' => $blockReasons === [], 'reasons' => $blockReasons];
        $data['forfeit']    = ['point' => 5000, 'coupon' => 2];

        return $data;
    }

    public function testWithdrawTabShowsBlockReasonsInsteadOfForm(): void
    {
        $html = view('auth/profile', $this->withdrawViewData(null, ['진행 중인 주문이 1건 있습니다.']));

        $this->assertStringContainsString('진행 중인 주문이 1건 있습니다.', $html);
        $this->assertStringNotContainsString('action="/auth/withdraw"', $html, '차단 상태에서 폼을 그리면 안 된다');
    }

    public function testWithdrawTabShowsForfeitSummaryAndForm(): void
    {
        $html = view('auth/profile', $this->withdrawViewData(null));

        $this->assertStringContainsString('action="/auth/withdraw"', $html);
        $this->assertStringContainsString('5,000점', $html);
        $this->assertStringContainsString('2장', $html);
        $this->assertStringContainsString('csrf', $html);
        $this->assertStringContainsString('name="password"', $html, '일반 계정은 비밀번호로 본인 확인한다');
    }

    public function testWithdrawTabAsksConfirmTextForSocialAccount(): void
    {
        $html = view('auth/profile', $this->withdrawViewData('kakao'));

        // 소셜 계정은 비밀번호가 랜덤이라 검증할 수 없다
        $this->assertStringContainsString('name="confirm_text"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }
```

- [ ] **Step 8: 테스트 실행 — 통과 확인**

Run: `composer test -- --filter AuthProfileTabViewTest`
Expected: PASS (기존 3개 수정분 + 신규 3개)

- [ ] **Step 9: 스타일·정적분석 확인 후 커밋**

```bash
composer cs-fix
composer analyse
git add app/Controllers/Front/AuthController.php app/Views/auth/profile.php app/Config/Routes.php tests/unit/AuthProfileTabViewTest.php
git commit -m "✨ feat: 마이페이지 회원 탈퇴 탭·탈퇴 처리 엔드포인트 추가"
```

---

### Task 7: 파기 배치 커맨드

**Files:**
- Create: `app/Commands/PurgeWithdrawnUsers.php`
- Modify: `app/Config/Tasks.php:17-21`
- Test: `tests/unit/PurgeWithdrawnUsersCommandTest.php`

**Interfaces:**
- Consumes: `WithdrawalService::purgeExpired()`, `settings`의 `withdrawal_retention_days`·`schedule_users_purge_withdrawn_enabled`
- Produces: `php spark users:purge-withdrawn [일수]` 명령

- [ ] **Step 1: 커맨드 작성**

`app/Commands/PurgeWithdrawnUsers.php` — `PurgeAccessLogs`의 구조를 그대로 따른다:

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WithdrawalService;
use App\Models\SettingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PurgeWithdrawnUsers extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'users:purge-withdrawn';
    protected $description = '보관 기간이 지난 탈퇴회원의 개인정보를 파기합니다.';
    protected $usage       = 'users:purge-withdrawn [일수]';

    /** @param array<int|string, string|null> $params */
    public function run(array $params): void
    {
        $settings = new SettingModel()->getAllAsMap();
        if (! (bool) ($settings['schedule_users_purge_withdrawn_enabled'] ?? 1)) {
            CLI::write('[users:purge-withdrawn] 비활성화됨 — 스킵', 'yellow');

            return;
        }

        $days = (int) ($params[0] ?? $settings['withdrawal_retention_days'] ?? 30);
        if ($days < 1) {
            CLI::write('[users:purge-withdrawn] 보관일수가 1 미만이라 중단합니다.', 'red');

            return;
        }

        $count = new WithdrawalService()->purgeExpired($days);

        CLI::write("[users:purge-withdrawn] {$count}건 개인정보 파기 완료 ({$days}일 초과)", 'green');
        log_message('info', "[users:purge-withdrawn] {$count}건 파기");
    }
}
```

- [ ] **Step 2: 스케줄러 매핑 추가**

`app/Config/Tasks.php`의 잡 매핑 배열(17~21번째 줄)에 한 줄 추가:

```php
        'schedule_users_purge_withdrawn' => 'users:purge-withdrawn',
```

- [ ] **Step 3: 실제로 실행해 확인**

Run: `php spark users:purge-withdrawn 30`
Expected: `[users:purge-withdrawn] N건 개인정보 파기 완료 (30일 초과)` 출력. 에러 없이 종료.

- [ ] **Step 4: 스케줄 화면에 노출되는지 확인**

Run: `php spark routes | grep schedule`
그리고 개발 서버(`php spark serve --port 8303`)에서 `/admin/schedule`을 열어 "탈퇴회원 개인정보 파기" 항목이 활성화 토글·크론 입력과 함께 보이는지 확인한다.

- [ ] **Step 5: 커맨드 테스트 작성**

`tests/unit/PurgeWithdrawnUsersCommandTest.php` — `ExpireOrdersCommandTest`처럼 모델을 직접 부르지 않고 `command()`로 실제 구동한다. 커맨드가 설정 확인을 빠뜨리는 퇴행을 잡아야 하기 때문이다:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * users:purge-withdrawn — 설정 게이트와 파기 동작 검증
 */
final class PurgeWithdrawnUsersCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var list<int> */
    private array $snapshotIds = [];
    private ?string $originalEnabled = null;

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->snapshotIds !== []) {
            $db->table('withdrawn_users')->whereIn('id', $this->snapshotIds)->delete();
            $this->snapshotIds = [];
        }
        if ($this->originalEnabled !== null) {
            $db->table('settings')
                ->where('key', 'schedule_users_purge_withdrawn_enabled')
                ->update(['value' => $this->originalEnabled]);
            $this->originalEnabled = null;
        }
        cache()->delete('site_settings');

        parent::tearDown();
    }

    private function setEnabled(string $value): void
    {
        $db  = db_connect();
        $row = $db->table('settings')
            ->where('key', 'schedule_users_purge_withdrawn_enabled')
            ->get()->getRowArray();

        $this->originalEnabled = (string) $row['value'];
        $db->table('settings')
            ->where('key', 'schedule_users_purge_withdrawn_enabled')
            ->update(['value' => $value]);
        cache()->delete('site_settings');
    }

    private function insertExpiredSnapshot(): int
    {
        $uid = 'PC' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('withdrawn_users')->insert([
            'user_id'      => 800000 + random_int(1, 99999),
            'email'        => $uid . '@example.test',
            'nickname'     => $uid,
            'reason_code'  => 'etc',
            'withdrawn_by' => 'member',
            'withdrawn_at' => date('Y-m-d H:i:s', strtotime('-90 days')),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->snapshotIds[] = $id;

        return $id;
    }

    public function testCommandSkipsWhenDisabled(): void
    {
        $this->setEnabled('0');
        $id = $this->insertExpiredSnapshot();

        command('users:purge-withdrawn 30');

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNotNull($row['email'], '비활성 상태에서는 파기하면 안 된다');
        $this->assertNull($row['purged_at']);
    }

    public function testCommandPurgesExpiredSnapshot(): void
    {
        $this->setEnabled('1');
        $id = $this->insertExpiredSnapshot();

        command('users:purge-withdrawn 30');

        $row = new WithdrawnUserModel()->find($id);
        $this->assertNull($row['email']);
        $this->assertNotNull($row['purged_at']);
        $this->assertSame('etc', $row['reason_code'], '통계용 사유는 남아야 한다');
    }
}
```

Run: `composer test -- --filter PurgeWithdrawnUsersCommandTest`
Expected: PASS (2개)

> `SettingModel::getAllAsMap()`이 `site_settings` 캐시를 쓰므로 설정을 바꾼 뒤 반드시 캐시를 지워야 한다. 위 테스트의 `cache()->delete('site_settings')`가 그 역할이다 — 빠뜨리면 커맨드가 옛 설정을 읽어 테스트가 간헐 실패한다.

- [ ] **Step 6: 커밋**

```bash
git add app/Commands/PurgeWithdrawnUsers.php app/Config/Tasks.php tests/unit/PurgeWithdrawnUsersCommandTest.php
git commit -m "✨ feat: 탈퇴회원 개인정보 파기 배치(users:purge-withdrawn) 추가"
```

---

### Task 8: 관리자 화면

**Files:**
- Modify: `app/Controllers/Admin/UserController.php:22-45` (`json()`), `:46-92` (`index()`), `:139-148` (`delete()`), 새 메서드 `withdrawn()`
- Create: `app/Views/admin/users/withdrawn.php`
- Modify: `app/Config/Routes.php:110-120`
- Modify: `app/Views/layouts/admin.php:122`
- Test: `tests/unit/AdminWithdrawnUserTest.php`

**Interfaces:**
- Consumes: `WithdrawalService::withdraw()`, `WithdrawnUserModel::paginateList()`, `WithdrawalService::REASON_CODES`
- Produces: `GET /admin/users/withdrawn` 화면

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/AdminWithdrawnUserTest.php` — 핵심은 **tombstone이 일반 회원 목록에서 빠지는지**다. 이건 스펙 작성 후 발견한 구멍이라 반드시 검증한다:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\WithdrawalService;
use App\Models\UserModel;
use App\Models\WithdrawnUserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 관리자 화면에서 탈퇴회원(tombstone)이 일반 회원 목록에 섞이지 않는지 검증
 *
 * 이 저장소는 컨트롤러를 HTTP 로 구동하는 feature 테스트를 쓰지 않는다.
 * 그래서 필터를 컨트롤러에 인라인으로 두면 검증할 방법이 없다 —
 * UserModel::activeBuilder() 로 추출하고 그 메서드를 테스트한다.
 */
final class AdminWithdrawnUserTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<string, list<int>> */
    private array $cleanup = ['withdrawn_users' => [], 'users' => []];

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['withdrawn_users', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['withdrawn_users' => [], 'users' => []];
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $uid = 'AW' . substr(uniqid(), -8);
        $db  = db_connect();
        $db->table('users')->insert([
            'username'   => $uid,
            'email'      => $uid . '@example.test',
            'password'   => password_hash('pw1234', PASSWORD_DEFAULT),
            'nickname'   => $uid,
            'role'       => 'member',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    private function withdraw(int $userId): void
    {
        new WithdrawalService()->withdraw($userId, 'etc', null);
        $row = db_connect()->table('withdrawn_users')->where('user_id', $userId)->get()->getRowArray();
        $this->cleanup['withdrawn_users'][] = (int) $row['id'];
    }

    public function testActiveBuilderExcludesWithdrawnUser(): void
    {
        $keptId = $this->insertUser();
        $goneId = $this->insertUser();
        $this->withdraw($goneId);

        $ids = array_map(
            'intval',
            array_column(
                new UserModel()->activeBuilder()->select('id')->get()->getResultArray(),
                'id'
            )
        );

        $this->assertContains($keptId, $ids);
        $this->assertNotContains($goneId, $ids, '탈퇴회원 tombstone 이 일반 회원 목록에 섞이면 안 된다');
    }

    public function testWithdrawnListReturnsSnapshot(): void
    {
        $id = $this->insertUser();
        $this->withdraw($id);

        $result = new WithdrawnUserModel()->paginateList('', 1, 20);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $userIds = array_column($result['rows'], 'user_id');
        $this->assertContains($id, array_map('intval', $userIds));
    }
}
```

- [ ] **Step 2: 테스트 실행 — 실패 확인**

Run: `composer test -- --filter AdminWithdrawnUserTest`
Expected: FAIL — `Call to undefined method App\Models\UserModel::activeBuilder()`

- [ ] **Step 3: `UserModel::activeBuilder()` 추가 후 두 목록에 적용**

`app/Models/UserModel.php`의 `findByEmail()` 위에 추가:

```php
    /**
     * 탈퇴회원(tombstone)을 제외한 회원 빌더
     *
     * 탈퇴 시 users 행은 삭제하지 않고 마스킹만 하므로, 필터를 걸지 않으면
     * '탈퇴회원' 닉네임의 행이 일반 회원 목록에 그대로 섞인다.
     */
    public function activeBuilder(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->builder()->where('withdrawn_at IS NULL');
    }
```

`Admin\UserController::json()`의 `$this->userModel->builder()`를 `$this->userModel->activeBuilder()`로 바꾼다:

```php
        $rows = $this->userModel->activeBuilder()
            ->select('id, nickname, email, phone, role, grade, social_provider, is_active, email_verify_token, created_at, last_login')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();
```

`index()`의 `$builder = $this->userModel->builder();`도 교체:

```php
        $builder = $this->userModel->activeBuilder();
```

- [ ] **Step 4: `withdrawn()` 메서드 추가**

`Admin\UserController`에 추가:

```php
    /** 탈퇴회원 목록 */
    public function withdrawn(): string
    {
        $keyword = (string) ($this->request->getGet('q') ?? '');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 20;

        $result = new WithdrawnUserModel()->paginateList($keyword, $page, $perPage);

        return $this->render('admin/users/withdrawn', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'currentPage' => $page,
            'totalPages'  => (int) ceil($result['total'] / $perPage),
            'keyword'     => $keyword,
        ]);
    }
```

`use` 절에 추가: `use App\Models\WithdrawnUserModel;`

- [ ] **Step 5: `delete()`를 강제 탈퇴로 교체**

`Admin\UserController::delete()`를 다음으로 교체한다. 현행 hard delete가 참조를 깨뜨리는 문제를 여기서 없앤다:

```php
    /**
     * 회원 삭제 → 강제 탈퇴로 처리
     *
     * users 행을 hard delete 하면 orders·product_reviews·posts 등의 user_id 가
     * 고아가 된다(이 DB 에는 외래키 제약이 없어 에러 없이 통과한다). 회원 탈퇴와
     * 같은 경로를 써서 개인정보만 분리 보관하고 참조는 유지한다.
     */
    public function delete(int $id): \CodeIgniter\HTTP\RedirectResponse
    {
        if ($id === (int) session()->get('user_id')) {
            return redirect()->back()->with('error', '본인 계정은 삭제할 수 없습니다.');
        }

        try {
            new WithdrawalService()->withdraw($id, 'admin', '관리자 처리', 'admin');
        } catch (WithdrawalBlockedException $e) {
            return redirect()->back()->with('error', implode(' ', $e->reasons));
        } catch (\Throwable $e) {
            log_message('error', '[admin withdraw] 실패 user_id=' . $id . ' — ' . $e->getMessage());

            return redirect()->back()->with('error', '탈퇴 처리 중 오류가 발생했습니다.');
        }

        return redirect()->to('/admin/users')->with('success', '회원이 탈퇴 처리되었습니다.');
    }
```

`use` 절에 추가: `use App\Exceptions\WithdrawalBlockedException;`, `use App\Libraries\WithdrawalService;`

- [ ] **Step 6: 라우트 추가**

`app/Config/Routes.php`의 `$routes->get('users/json', ...)` 바로 위에 추가한다. `users/(:num)/edit`보다 반드시 앞이어야 한다:

```php
    $routes->get('users/withdrawn', 'Admin\UserController::withdrawn');
```

- [ ] **Step 7: 목록 뷰 작성**

`app/Views/admin/users/withdrawn.php`:

```php
<?= $this->extend('layouts/admin') ?>
<?php $pageTitle = '탈퇴회원 관리' ?>

<?= $this->section('content') ?>

<?php $reasonLabels = \App\Libraries\WithdrawalService::REASON_CODES; ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <form method="get" action="/admin/users/withdrawn" class="d-flex gap-2">
        <input type="text" name="q" value="<?= esc($keyword) ?>" class="form-control form-control-sm"
               style="max-width:240px" placeholder="이메일 / 닉네임 검색">
        <button class="btn btn-outline-secondary btn-sm">검색</button>
    </form>
    <a href="/admin/users" class="btn btn-outline-secondary btn-sm ms-auto">일반 회원 목록</a>
</div>

<div class="alert alert-info small">
    개인정보는 보관 기간(설정 → <code>withdrawal_retention_days</code>)이 지나면 자동 파기됩니다.
    파기된 항목은 <span class="text-muted">—</span> 로 표시됩니다.
</div>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
    <thead class="table-light">
        <tr>
            <th>회원ID</th><th>이메일</th><th>닉네임</th><th>등급</th>
            <th class="text-end">주문</th><th class="text-end">소멸 포인트</th><th class="text-end">소멸 쿠폰</th>
            <th>사유</th><th>경로</th><th>탈퇴일</th><th>파기</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rows === []): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">탈퇴회원이 없습니다.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= esc((string) $row['user_id']) ?></td>
            <td><?= $row['email'] !== null ? esc($row['email']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $row['nickname'] !== null ? esc($row['nickname']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= esc($row['grade'] ?? '-') ?></td>
            <td class="text-end"><?= esc((string) $row['order_count']) ?></td>
            <td class="text-end"><?= esc(number_format((int) $row['point_balance'])) ?></td>
            <td class="text-end"><?= esc((string) $row['coupon_count']) ?></td>
            <td><?= esc($reasonLabels[$row['reason_code']] ?? $row['reason_code']) ?></td>
            <td><?= $row['withdrawn_by'] === 'admin' ? '관리자' : '회원' ?></td>
            <td><?= esc(date('Y-m-d H:i', strtotime((string) $row['withdrawn_at']))) ?></td>
            <td>
                <?php if ($row['purged_at'] !== null): ?>
                    <span class="badge bg-secondary">파기됨</span>
                <?php else: ?>
                    <span class="badge bg-light text-dark">보관중</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<nav>
    <ul class="pagination pagination-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
            <a class="page-link" href="/admin/users/withdrawn?q=<?= esc($keyword, 'url') ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?= $this->endSection() ?>
```

`row['withdrawn_by']` 출력은 `'관리자'`/`'회원'` 리터럴이라 `esc()`가 필요 없다(사용자 입력이 아님).

- [ ] **Step 8: 사이드바 링크 추가**

`app/Views/layouts/admin.php`의 122번째 줄(`회원 관리` 링크) 바로 아래에 추가:

```php
            <a href="/admin/users/withdrawn" class="nav-sublink <?= str_starts_with($uri, 'admin/users/withdrawn') ? 'active' : '' ?>"><i class="bi bi-person-dash me-2"></i>탈퇴회원</a>
```

`회원 관리` 링크의 활성 조건이 `str_starts_with($uri, 'admin/users')`라 탈퇴회원 화면에서 둘 다 활성으로 보인다. `회원 관리` 조건을 다음으로 바꾼다:

```php
<?= $uri === 'admin/users' || str_starts_with($uri, 'admin/users/') && ! str_starts_with($uri, 'admin/users/withdrawn') ? 'active' : '' ?>
```

- [ ] **Step 9: 테스트 실행 — 통과 확인**

Run: `composer test -- --filter AdminWithdrawnUserTest`
Expected: PASS (2개)

- [ ] **Step 10: 화면 확인**

개발 서버에서 `/admin/users/withdrawn`을 열어 목록이 렌더링되고 사이드바 활성 표시가 올바른지 확인한다.

```bash
php spark serve --port 8303
```

- [ ] **Step 11: 커밋**

```bash
composer cs-fix
git add app/Controllers/Admin/UserController.php app/Views/admin/users/withdrawn.php app/Views/layouts/admin.php app/Config/Routes.php tests/unit/AdminWithdrawnUserTest.php
git commit -m "✨ feat: 관리자 탈퇴회원 목록 추가·회원 삭제를 강제 탈퇴로 교체"
```

---

### Task 9: 문서화와 최종 검증

**Files:**
- Modify: `CLAUDE.md` (스케줄 명령 표 · DB 스키마 요약)
- Modify: `docs/manual.md`

**Interfaces:**
- Consumes: Task 1~8 전부
- Produces: 없음 (배포 가능 상태)

- [ ] **Step 1: `CLAUDE.md` 스케줄 명령 표에 행 추가**

"스케줄 / 배치 명령" 표에 추가:

```markdown
| `php spark users:purge-withdrawn [일수]` | `PurgeWithdrawnUsers` | 보관 기간 경과 탈퇴회원 개인정보 파기 |
```

같은 절의 잡→명령 매핑 문장에 `schedule_users_purge_withdrawn`을 추가한다.

- [ ] **Step 2: `CLAUDE.md` DB 스키마 요약에 행 추가**

`users` 행 아래에 추가:

```
withdrawn_users      — 탈퇴회원 개인정보 스냅샷 (보관기간 경과 시 개인정보 컬럼만 NULL 파기)
```

`users` 설명에 `withdrawn_at`을 덧붙인다.

- [ ] **Step 3: `docs/manual.md`에 탈퇴 흐름 추가**

고객 편에 "회원 탈퇴"(마이페이지 → 내 정보 수정 → 회원 탈퇴 탭), 관리자 편에 "탈퇴회원 관리"(`/admin/users/withdrawn`) 항목을 기존 문서 형식에 맞춰 추가한다. 진행 중 주문이 있으면 탈퇴가 막힌다는 점과 보관 기간 설정 위치를 명시한다.

- [ ] **Step 4: 전체 품질 게이트 실행**

```bash
composer check
```

Expected: cs · analyse · test 전부 PASS. 실패하면 원인을 고친다 — `@phpstan-ignore`로 덮지 않는다.

- [ ] **Step 5: 커밋 후 PR**

```bash
git add CLAUDE.md docs/manual.md
git commit -m "📝 docs: 회원탈퇴 기능 문서화 (스케줄 명령·스키마·매뉴얼)"
git push -u origin claude/member-withdrawal-process-0fc1dd
```

```bash
gh pr create --base dev --title "✨ feat: 회원탈퇴 — 탈퇴회원 테이블 이동 및 개인정보 기간 경과 파기" --body "$(cat <<'EOF'
## 개요

회원탈퇴 기능을 신규 구현했다. 탈퇴 시 개인정보를 `withdrawn_users`로 스냅샷 이관하고, `users` 행은 삭제하지 않고 마스킹해 남긴다. 보관 기간이 지난 개인정보는 배치가 자동 파기한다.

설계: [docs/superpowers/specs/2026-08-13-member-withdrawal-design.md](docs/superpowers/specs/2026-08-13-member-withdrawal-design.md)

## `users` 행을 지우지 않은 이유

이 DB에는 외래키 제약이 하나도 없다. `users` 행을 hard delete 해도 에러는 나지 않지만 `orders`·`product_reviews`·`posts` 등 8개 테이블의 `user_id`가 조용히 고아가 되어, 관리자 주문 화면에서 주문자를 조회할 수 없게 된다. 마스킹 tombstone은 참조를 지탱하면서 개인정보만 제거한다.

## 주요 변경

- `withdrawn_users` 테이블 + `users.withdrawn_at` 추가 (마이그레이션 3건)
- `WithdrawalService` — 차단 판정 / 탈퇴 실행 / 개인정보 파기
- 마이페이지 → 내 정보 수정 → **회원 탈퇴** 탭
- `/admin/users/withdrawn` 탈퇴회원 목록
- `php spark users:purge-withdrawn` 배치 + `/admin/schedule` 연동

## 동작 변경 (운영자 확인 필요)

- **관리자 회원 삭제가 강제 탈퇴로 바뀐다.** 기존에는 행이 사라졌지만, 이제 마스킹된 tombstone이 남고 탈퇴회원 목록에 나타난다. 진행 중 주문이 있으면 관리자 삭제도 차단된다.
- 진행 중 주문·반품/교환 건이 있는 회원, 관리자 계정은 탈퇴할 수 없다.

## 배포 시 주의

- **마이그레이션 3건**이 포함된다. 일반 배포는 마이그레이션을 실행하지 않으므로 Actions에서 `workflow_dispatch` → `run_migration = true`로 배포하거나 서버에서 `php spark migrate`를 직접 실행해야 한다.
- 개인정보 보관 기간 기본값은 **30일**이다. 배포 후 `/admin/settings`의 `withdrawal_retention_days`에서 정책에 맞게 조정한다.

## 검증

`composer check` (cs · PHPStan · PHPUnit) 전체 통과.
EOF
)"
```

---

## 배포 주의사항

- **마이그레이션 3건이 포함된다.** `dev → main` 배포는 마이그레이션을 자동 실행하지 않으므로(`.github/workflows/deploy.yml`), Actions에서 `workflow_dispatch` → `run_migration = true`로 배포하거나 서버에서 직접 `php spark migrate`를 실행해야 한다.
- **관리자 회원 삭제 동작이 바뀐다.** 기존에는 행이 사라졌지만 이제 마스킹된 tombstone이 남고 `/admin/users/withdrawn`에 나타난다. 운영자에게 알려야 한다.
- **`withdrawal_retention_days` 기본값은 30일**이다. 배포 후 `/admin/settings`에서 정책에 맞게 조정한다.
