# 주문 시도(order_attempts) 분리 — PR1 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 결제 완료 전 주문을 `orders`가 아닌 신규 `order_attempts`에 기록해, 주문내역에 결제가 확정된 주문만 남게 한다.

**Architecture:** 주문서 제출은 `OrderAttemptModel::createAttempt()`로 `order_attempts` 행을 만들고 쿠폰·포인트를 선점한다. 무료·무통장은 즉시, PG 결제는 승인 콜백 시 `OrderModel::convertAttempt()`가 `orders` + `order_items`를 만든다. 전환은 `order_attempts.status`에 대한 조건부 UPDATE로 원자적 클레임을 잡아 멱등성을 보장한다.

**Tech Stack:** PHP 8.5, CodeIgniter 4.7, MySQL 8, PHPUnit 12

## Global Constraints

- 모든 PHP 파일 첫 줄에 `declare(strict_types=1);`. PSR-12. 주석·커밋 메시지는 한국어.
- 메서드·프로퍼티에 타입 선언 완전 적용(반환 타입 포함). PHPStan 레벨 5 통과 필수.
- DB 접근은 Query Builder 또는 바인딩된 `$this->db->query()`만. 문자열 조합 raw SQL 금지.
- Model에는 `$allowedFields` 명시 필수.
- 커밋 메시지 = 이모지 + Conventional Commits 접두어 + 한국어 설명.
- 테스트는 `tests/unit/`에 `CIUnitTestCase` + `DatabaseTestTrait`, `protected $DBGroup = 'tests'; protected $migrate = false; protected $refresh = false;`. 트랜잭션 격리가 아니라 실제 커밋 + `tearDown()` 수동 정리 방식이다.
- 테스트에서 `assertContains`/`assertNotContains`로 DB의 id를 비교할 때는 반드시 `array_map('intval', ...)`로 정수 변환한다. MySQL 드라이버가 문자열로 돌려주기 때문에 PHPUnit 12의 엄격 비교가 실패한다.
- 검증 명령: `composer cs-fix` → `composer ci`(= CS Fixer + PHPStan + PHPUnit). `composer check`는 CS Fixer를 빠뜨리므로 쓰지 않는다.
- 단일 테스트 실행: `vendor/bin/phpunit --filter <테스트메서드명> tests/unit/<파일>.php`

## 참조 스펙

[docs/superpowers/specs/2026-08-11-order-attempts-design.md](../specs/2026-08-11-order-attempts-design.md)

---

## File Structure

**신규 생성**

| 파일 | 책임 |
|---|---|
| `app/Database/Migrations/2026-08-11-000002_CreateOrderAttempts.php` | `order_attempts` 테이블 + `user_coupons`·`point_logs`의 `order_attempt_id` 컬럼 |
| `app/Models/OrderAttemptModel.php` | 결제 확정 **이전** 단계 전담 — attempt 생성·클레임·실패·만료, 쿠폰·포인트 선점과 복구 |
| `tests/unit/OrderAttemptModelTest.php` | attempt 생성·선점·실패·만료 |
| `tests/unit/OrderAttemptConversionTest.php` | attempt → orders 전환·멱등성·보상 |

**수정**

| 파일 | 변경 |
|---|---|
| `app/Models/OrderModel.php` | `convertAttempt()` 추가, `getByUser()`·`adminGetAll()`에서 `pending` 제외, `createPending()` 제거(Task 10) |
| `app/Controllers/Front/OrderController.php` | `create()`를 attempt 기반으로 전환 |
| `app/Controllers/Front/PaymentController.php` | 콜백을 `attempt_id` 기반으로 전환(+ 레거시 `order_id` 호환) |
| `app/Libraries/PG/{TossPayments,Inicis,NicePay,KakaoPay,NaverPay,Payco}Adapter.php` | 콜백 URL `order_id=` → `attempt_id=` |
| `app/Commands/ExpireOrders.php` | attempt 만료 + 레거시 `orders.pending` 만료 병행 |
| `app/Controllers/Admin/OrderController.php` | `STATUS_LABELS`에서 `pending`·`expired` 제거 |
| `app/Controllers/Admin/UserController.php` | 회원 상세 주문 탭에서 `pending`·`expired` 제외 |
| `app/Libraries/OrderAnomalyService.php` | `ACTIVE_STATUSES`에서 `pending` 제거 |
| `app/Views/shop/orders/list.php` | 취소 가능 상태에서 `pending` 제거 |
| 기존 테스트 8개 파일 | `createPending()` 호출부를 헬퍼로 치환(Task 10) |

`OrderAttemptModel`은 "결제 확정 이전", `OrderModel`은 "결제 확정 이후"를 담당한다. 두 모델의 유일한 접점은 `convertAttempt()`에 넘기는 attempt 배열이다.

---

## Task 1: 마이그레이션 — order_attempts 테이블

**Files:**
- Create: `app/Database/Migrations/2026-08-11-000002_CreateOrderAttempts.php`
- Test: `tests/unit/OrderAttemptModelTest.php`

**Interfaces:**
- Consumes: 없음
- Produces: `order_attempts` 테이블, `user_coupons.order_attempt_id`, `point_logs.order_attempt_id` 컬럼

- [ ] **Step 1: 마이그레이션 파일 작성**

`app/Database/Migrations/2026-08-11-000002_CreateOrderAttempts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrderAttempts extends Migration
{
    public function up()
    {
        // 결제 확정 전 주문 시도. 결제가 확정되면 orders 로 전환되고 order_id 가 채워진다.
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'                => ['type' => 'INT', 'unsigned' => true],
            'order_number'           => ['type' => 'VARCHAR', 'constraint' => 30],
            'status'                 => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'converted', 'failed', 'expired'],
                'default'    => 'pending',
            ],
            // 금액 스냅샷 — PG 승인 금액 검증에 쓰인다.
            'total_product_price'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'shipping_fee'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'total_amount'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'coupon_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'coupon_discount_amount' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'point_used_amount'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'point_earned_amount'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'payable_amount'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            // 배송지 스냅샷
            'receiver_name'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'receiver_phone'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'zipcode'                => ['type' => 'VARCHAR', 'constraint' => 10],
            'address1'               => ['type' => 'VARCHAR', 'constraint' => 200],
            'address2'               => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'delivery_memo'          => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            // order_items 로 그대로 전환 가능한 라인 배열
            'items_snapshot'         => ['type' => 'JSON', 'null' => true],
            // payments.pg_provider 와 달리 ENUM 이 아니다. PG 추가 시 두 테이블 ENUM 을
            // 함께 늘리는 결합을 만들지 않기 위해서다.
            'pg_provider'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'order_id'               => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'fail_reason'            => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'converted_at'           => ['type' => 'DATETIME', 'null' => true],
            'failed_at'              => ['type' => 'DATETIME', 'null' => true],
            'expired_at'             => ['type' => 'DATETIME', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => true],
            'updated_at'             => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('order_number', 'uniq_order_attempts_order_number');
        $this->forge->addKey('user_id', false, false, 'idx_order_attempts_user_id');
        $this->forge->addKey('order_id', false, false, 'idx_order_attempts_order_id');
        // 만료 스윕(status='pending' AND created_at < cutoff)과 로그 목록 정렬을 함께 커버한다.
        $this->forge->addKey(['status', 'created_at'], false, false, 'idx_order_attempts_status_created');
        $this->forge->createTable('order_attempts');

        // 쿠폰·포인트 선점의 소유자. 전환 전에는 attempt 를, 전환 후에는 order_id 를 함께 가리킨다.
        $this->forge->addColumn('user_coupons', [
            'order_attempt_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'order_id'],
        ]);
        $this->forge->addColumn('point_logs', [
            'order_attempt_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'order_id'],
        ]);
        $this->db->query('ALTER TABLE user_coupons ADD INDEX idx_user_coupons_order_attempt_id (order_attempt_id)');
        $this->db->query('ALTER TABLE point_logs ADD INDEX idx_point_logs_order_attempt_id (order_attempt_id)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE point_logs DROP INDEX idx_point_logs_order_attempt_id');
        $this->db->query('ALTER TABLE user_coupons DROP INDEX idx_user_coupons_order_attempt_id');
        $this->forge->dropColumn('point_logs', 'order_attempt_id');
        $this->forge->dropColumn('user_coupons', 'order_attempt_id');
        $this->forge->dropTable('order_attempts', true);
    }
}
```

- [ ] **Step 2: 테스트 DB에 마이그레이션 적용**

Run:
```bash
php spark migrate --all -n
```
Expected: `Running all new migrations...` 다음에 `CreateOrderAttempts` 가 실행되고 `Migrations complete.` 로 끝난다.

> 이 저장소는 `.env` 의 `database.default` 와 `database.tests` 가 같은 MySQL DB(`aicopia_test`)를 가리키도록 설정돼 있다. `default` 그룹에 마이그레이션하면 `tests` 그룹이 같은 스키마를 읽는다.

- [ ] **Step 3: 스키마 확인 테스트 작성**

`tests/unit/OrderAttemptModelTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * OrderAttemptModel — 주문 시도 생명주기
 * 이슈 #214
 */
final class OrderAttemptModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    public function testOrderAttemptsTableExists(): void
    {
        $db = db_connect();

        $this->assertTrue($db->tableExists('order_attempts'));
        $this->assertTrue($db->fieldExists('items_snapshot', 'order_attempts'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'user_coupons'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'point_logs'));
    }
}
```

- [ ] **Step 4: 테스트 실행**

Run:
```bash
vendor/bin/phpunit --filter testOrderAttemptsTableExists tests/unit/OrderAttemptModelTest.php
```
Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 5: 커밋**

```bash
git add app/Database/Migrations/2026-08-11-000002_CreateOrderAttempts.php tests/unit/OrderAttemptModelTest.php
git commit -m "✨ feat: order_attempts 테이블 및 쿠폰·포인트 선점 참조 컬럼 추가"
```

---

## Task 2: OrderAttemptModel::createAttempt() — 시도 생성 + 쿠폰·포인트 선점

`OrderModel::createPending()`([app/Models/OrderModel.php:94](../../../app/Models/OrderModel.php))의 로직을 옮기되, `orders`·`order_items` INSERT 대신 `order_attempts` 1행 + `items_snapshot` JSON을 쓴다. 쿠폰 잠금과 포인트 차감은 **한 글자도 약화하지 않고** 그대로 가져온다.

**Files:**
- Create: `app/Models/OrderAttemptModel.php`
- Test: `tests/unit/OrderAttemptModelTest.php`

**Interfaces:**
- Consumes: Task 1의 `order_attempts` 테이블
- Produces:
  - `OrderAttemptModel::createAttempt(int $userId, array $shippingData, array $cartItems, ?int $couponId = null, ?int $userCouponId = null, int $couponDiscountAmount = 0, int $pointUsed = 0, int $pointEarned = 0, ?string $pgProvider = null): int` — attempt id, 실패 시 `0`
  - `OrderAttemptModel::withItems(array $attempt): array` — `items_snapshot`을 디코드해 `items` 키로 붙인 배열
  - `OrderAttemptModel::STATUS_LABELS` 상수

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/OrderAttemptModelTest.php` 의 `testOrderAttemptsTableExists()` 아래에 헬퍼와 테스트를 추가한다. 파일 상단 `use` 에 `use App\Models\OrderAttemptModel;` 를 추가하고, 클래스 본문을 아래로 교체한다:

```php
    private OrderAttemptModel $model;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'user_coupons'   => [],
        'coupons'        => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        foreach (['user_coupons', 'coupons', 'products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    private function insertUser(int $pointBalance = 0): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'oatest_' . $uid,
            'email'         => 'oa-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OAUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => $pointBalance,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OAProd_' . uniqid(),
            'slug'           => 'oa-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertCoupon(array $extra = []): array
    {
        $db   = db_connect();
        $code = 'OAC-' . strtoupper(uniqid());
        $db->table('coupons')->insert(array_merge([
            'code'                => $code,
            'name'                => 'OA Coupon',
            'type'                => 'fixed',
            'discount_value'      => 3000,
            'min_order_amount'    => 0,
            'max_discount_amount' => 0,
            'total_qty'           => null,
            'used_count'          => 0,
            'per_user_limit'      => 1,
            'is_active'           => 1,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ], $extra));
        $id = (int) $db->insertID();
        $this->cleanup['coupons'][] = $id;

        return ['id' => $id, 'code' => $code];
    }

    private function insertUserCoupon(int $userId, int $couponId): int
    {
        $db = db_connect();
        $db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'order_id'   => null,
            'source'     => 'admin',
            'status'     => 'issued',
            'issued_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['user_coupons'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function makeCartItem(array $product, int $qty = 1): array
    {
        return [
            'product_id'     => $product['id'],
            'name'           => $product['name'],
            'price'          => $product['price'],
            'discount_price' => null,
            'qty'            => $qty,
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
        ];
    }

    /** @return array<string, mixed> */
    private function shippingData(): array
    {
        return [
            'receiver_name'  => '테스트',
            'receiver_phone' => '010-0000-0000',
            'zipcode'        => '12345',
            'address1'       => '서울시 테스트구',
            'address2'       => null,
            'delivery_memo'  => null,
        ];
    }

    private function createAttempt(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0
    ): int {
        $id = $this->model->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product, $qty)],
            $couponId,
            $userCouponId,
            $couponDiscount,
            $pointUsed,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    public function testOrderAttemptsTableExists(): void
    {
        $db = db_connect();

        $this->assertTrue($db->tableExists('order_attempts'));
        $this->assertTrue($db->fieldExists('items_snapshot', 'order_attempts'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'user_coupons'));
        $this->assertTrue($db->fieldExists('order_attempt_id', 'point_logs'));
    }

    /** A-01: attempt 생성 시 orders 에는 아무것도 만들지 않는다 */
    public function testCreateAttempt_doesNotTouchOrdersTable(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);

        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('pending', $attempt['status']);
        $this->assertSame(10000, (int) $attempt['payable_amount']);
        $this->assertStringStartsWith('ORD-', $attempt['order_number']);
    }

    /** A-02: items_snapshot 에 order_items 전환용 라인이 담긴다 */
    public function testCreateAttempt_storesItemsSnapshot(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 2);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $items   = json_decode((string) $attempt['items_snapshot'], true);

        $this->assertCount(1, $items);
        $this->assertSame((int) $product['id'], (int) $items[0]['product_id']);
        $this->assertSame(2, (int) $items[0]['qty']);
        $this->assertSame(20000, (int) $items[0]['subtotal']);
        $this->assertArrayHasKey('cost_price', $items[0]);
        $this->assertArrayNotHasKey('order_id', $items[0]);
    }

    /** A-03: 쿠폰 선점 — used_count 증가 + user_coupons 가 attempt 를 가리킨다 */
    public function testCreateAttempt_preemptsCouponAgainstAttempt(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertGreaterThan(0, $attemptId);
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('used', $uc['status']);
        $this->assertSame($attemptId, (int) $uc['order_attempt_id']);
        $this->assertNull($uc['order_id']);
    }

    /** A-04: 포인트 선점 — 잔액 차감 + point_logs 가 attempt 를 가리킨다 */
    public function testCreateAttempt_preemptsPointsAgainstAttempt(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertSame(0, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);

        $log = $db->table('point_logs')->where('order_attempt_id', $attemptId)->where('type', 'use')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(-5000, (int) $log['amount']);
        $this->assertNull($log['order_id']);
    }

    /** A-05: 포인트 잔액 부족이면 attempt 를 만들지 않는다 */
    public function testCreateAttempt_insufficientPoints_returnsZero(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(1000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertSame(0, $attemptId);
        $this->assertSame(0, $db->table('order_attempts')->where('user_id', $userId)->countAllResults());
        $this->assertSame(1000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
    }
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run:
```bash
vendor/bin/phpunit --filter testCreateAttempt tests/unit/OrderAttemptModelTest.php
```
Expected: FAIL — `Class "App\Models\OrderAttemptModel" not found`

- [ ] **Step 3: OrderAttemptModel 작성**

`app/Models/OrderAttemptModel.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\ItemPricing;
use CodeIgniter\Model;

/**
 * 주문 시도 — 결제가 확정되기 전 단계를 전담한다.
 *
 * orders 에는 결제가 확정된 주문만 남긴다(이슈 #214). 주문서 제출 시점의
 * 금액·배송지·상품 스냅샷은 이 테이블에 쌓이고, 결제가 확정되는 순간
 * OrderModel::convertAttempt() 가 orders 로 옮긴다.
 *
 * 쿠폰·포인트 선점은 orders 시절과 동일하게 이 시점에 일어난다. 결제창이 떠
 * 있는 동안 같은 쿠폰이 다른 창에서 또 쓰이는 걸 막아야 하기 때문이다(이슈 #123).
 * 소유자 키만 order_id 에서 order_attempt_id 로 바뀌었다.
 */
class OrderAttemptModel extends Model
{
    protected $table         = 'order_attempts';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id', 'order_number', 'status',
        'total_product_price', 'shipping_fee', 'total_amount',
        'coupon_id', 'coupon_discount_amount', 'point_used_amount', 'point_earned_amount', 'payable_amount',
        'receiver_name', 'receiver_phone', 'zipcode', 'address1', 'address2', 'delivery_memo',
        'items_snapshot', 'pg_provider', 'order_id', 'fail_reason',
        'converted_at', 'failed_at', 'expired_at',
    ];

    public const STATUS_LABELS = [
        'pending'   => '결제 진행 중',
        'converted' => '주문 생성됨',
        'failed'    => '결제 실패·이탈',
        'expired'   => '시간 초과',
    ];

    /**
     * 주문 시도 생성 + 쿠폰·포인트 선점.
     *
     * @param array<string, mixed>             $shippingData
     * @param array<int, array<string, mixed>> $cartItems
     *
     * @return int attempt id. 선점 실패(쿠폰 소진·포인트 부족)나 금액 불일치면 0.
     */
    public function createAttempt(
        int $userId,
        array $shippingData,
        array $cartItems,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscountAmount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0,
        ?string $pgProvider = null
    ): int {
        $orderModel = new OrderModel();

        // 옵션 추가금까지 포함한 실제 단가로 합산한다 — items_snapshot 과
        // total_product_price 가 어긋나지 않아야 한다. (이슈 #124)
        $totalProduct  = ItemPricing::totalProductPrice($cartItems);
        $shippingFee   = $orderModel->calculateShippingFee($cartItems, $totalProduct);
        $totalAmount   = $totalProduct + $shippingFee;
        $payableAmount = max(0, $totalAmount - $couponDiscountAmount - $pointUsed);
        $orderNumber   = $this->generateOrderNumber();
        $now           = date('Y-m-d H:i:s');

        $items = $this->buildItemsSnapshot($cartItems);

        // 총액과 라인 합계는 정의상 같아야 한다. 어긋나면 청구액과 기록이 따로
        // 노는 상태이므로 시도 자체를 만들지 않는다. (이슈 #124)
        if (array_sum(array_column($items, 'subtotal')) !== $totalProduct) {
            log_message('critical', "[OrderAttempt] 금액 정합성 불일치 — order_number={$orderNumber}");

            return 0;
        }

        $this->db->transStart();

        $attemptId = (int) $this->insert([
            'user_id'                => $userId,
            'order_number'           => $orderNumber,
            'status'                 => 'pending',
            'total_product_price'    => $totalProduct,
            'shipping_fee'           => $shippingFee,
            'total_amount'           => $totalAmount,
            'coupon_id'              => $couponId,
            'coupon_discount_amount' => $couponDiscountAmount,
            'point_used_amount'      => $pointUsed,
            'point_earned_amount'    => $pointEarned,
            'payable_amount'         => $payableAmount,
            'receiver_name'          => $shippingData['receiver_name'],
            'receiver_phone'         => $shippingData['receiver_phone'],
            'zipcode'                => $shippingData['zipcode'],
            'address1'               => $shippingData['address1'],
            'address2'               => $shippingData['address2'] ?? null,
            'delivery_memo'          => $shippingData['delivery_memo'] ?? null,
            'items_snapshot'         => json_encode($items, JSON_UNESCAPED_UNICODE),
            'pg_provider'            => $pgProvider,
        ], true);

        if (! $this->preemptCoupon($attemptId, $userId, $couponId, $userCouponId, $now)) {
            $this->db->transRollback();

            return 0;
        }

        if (! $this->preemptPoints($attemptId, $userId, $pointUsed, $now)) {
            $this->db->transRollback();

            return 0;
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $attemptId : 0;
    }

    /**
     * order_items 로 그대로 전환 가능한 라인 배열을 만든다(order_id 만 비어 있다).
     *
     * @param array<int, array<string, mixed>> $cartItems
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItemsSnapshot(array $cartItems): array
    {
        $productIds = array_column($cartItems, 'product_id');
        $costMap    = [];
        if ($productIds !== []) {
            $rows = $this->db->table('products')
                ->select('id, cost_price')
                ->whereIn('id', $productIds)
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $costMap[(int) $row['id']] = (float) $row['cost_price'];
            }
        }

        $items = [];
        foreach ($cartItems as $item) {
            $price = ItemPricing::unitPrice($item);
            $qty   = (int) $item['qty'];

            $items[] = [
                'product_id'        => (int) $item['product_id'],
                'sku_id'            => isset($item['sku_id']) && $item['sku_id'] ? (int) $item['sku_id'] : null,
                'parent_product_id' => isset($item['parent_product_id']) && $item['parent_product_id'] ? (int) $item['parent_product_id'] : null,
                'sku_option_label'  => ($item['sku_label'] ?? '') ?: null,
                'product_name'      => $item['name'],
                'product_price'     => $price,
                'cost_price'        => $costMap[(int) $item['product_id']] ?? 0,
                'qty'               => $qty,
                'subtotal'          => $price * $qty,
            ];
        }

        return $items;
    }

    /**
     * 쿠폰 선점. 트랜잭션 안에서 호출해야 한다.
     *
     * coupons 에 대한 조건부 UPDATE 가 행 잠금을 잡아 동일 쿠폰의 동시 요청을
     * 직렬화한다 — 이 잠금이 이슈 #123(쿠폰 이중 사용)의 방어선이다.
     */
    private function preemptCoupon(int $attemptId, int $userId, ?int $couponId, ?int $userCouponId, string $now): bool
    {
        if (! $couponId) {
            return true;
        }

        $claimed = $this->runGuardedUpdate(
            'UPDATE coupons SET used_count = used_count + 1
             WHERE id = ? AND (total_qty IS NULL OR used_count < total_qty)',
            [$couponId]
        );
        if (! $claimed) {
            return false;
        }

        if ($userCouponId) {
            return $this->runGuardedUpdate(
                'UPDATE user_coupons SET status = ?, order_attempt_id = ?, used_at = ?, updated_at = ?
                 WHERE id = ? AND user_id = ? AND status = ?',
                ['used', $attemptId, $now, $now, $userCouponId, $userId, 'issued']
            );
        }

        // 코드 입력 — issued 상태 쿠폰이 있으면 사용 처리, 없으면 신규 INSERT
        $existing = $this->db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->where('status', 'issued')
            ->get()->getRowArray();

        if ($existing) {
            return $this->runGuardedUpdate(
                'UPDATE user_coupons SET status = ?, order_attempt_id = ?, used_at = ?, updated_at = ?
                 WHERE id = ? AND status = ?',
                ['used', $attemptId, $now, $now, $existing['id'], 'issued']
            );
        }

        $this->db->table('user_coupons')->insert([
            'user_id'          => $userId,
            'coupon_id'        => $couponId,
            'order_id'         => null,
            'order_attempt_id' => $attemptId,
            'source'           => 'code',
            'status'           => 'used',
            'issued_at'        => $now,
            'used_at'          => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return true;
    }

    /** 포인트 선점 (FOR UPDATE 락 + 조건부 UPDATE). 트랜잭션 안에서 호출해야 한다. */
    private function preemptPoints(int $attemptId, int $userId, int $pointUsed, string $now): bool
    {
        if ($pointUsed <= 0) {
            return true;
        }

        $this->db->query('SELECT point_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
        $this->db->query(
            'UPDATE users SET point_balance = point_balance - ? WHERE id = ? AND point_balance >= ?',
            [$pointUsed, $userId, $pointUsed]
        );
        if ($this->db->affectedRows() === 0) {
            return false;
        }

        $this->db->table('point_logs')->insert([
            'user_id'          => $userId,
            'type'             => 'use',
            'amount'           => -$pointUsed,
            'order_id'         => null,
            'order_attempt_id' => $attemptId,
            'note'             => '주문 포인트 사용',
            'created_at'       => $now,
        ]);

        return true;
    }

    /**
     * items_snapshot 을 디코드해 items 키로 붙인다. PG 어댑터가 기대하는
     * 주문 배열 형태(order_number·payable_amount·receiver_name·items)를 만족시킨다.
     *
     * @param array<string, mixed> $attempt
     *
     * @return array<string, mixed>
     */
    public function withItems(array $attempt): array
    {
        $attempt['items'] = json_decode((string) ($attempt['items_snapshot'] ?? '[]'), true) ?: [];

        return $attempt;
    }

    /** @param array<int, mixed> $bindings */
    private function runGuardedUpdate(string $sql, array $bindings): bool
    {
        $this->db->query($sql, $bindings);

        return $this->db->affectedRows() > 0;
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: 테스트 실행해 통과 확인**

Run:
```bash
vendor/bin/phpunit tests/unit/OrderAttemptModelTest.php
```
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: 커밋**

```bash
composer cs-fix
git add app/Models/OrderAttemptModel.php tests/unit/OrderAttemptModelTest.php
git commit -m "✨ feat: OrderAttemptModel 주문 시도 생성 및 쿠폰·포인트 선점"
```

---

## Task 3: 쿠폰 이중사용 방어 테스트 이전

기존 `tests/unit/CouponDoubleSpendTest.php`가 지키던 불변식을 attempt 기준으로 옮긴다. **이 태스크가 통과하기 전에는 다음으로 넘어가지 않는다.**

**Files:**
- Modify: `tests/unit/OrderAttemptModelTest.php`

**Interfaces:**
- Consumes: Task 2의 `createAttempt()`
- Produces: 없음(회귀 방어)

- [ ] **Step 1: 실패하는 테스트 추가**

`tests/unit/OrderAttemptModelTest.php` 의 마지막 테스트 아래에 추가:

```php
    /** A-06: 수량 1장짜리 쿠폰은 두 번째 attempt 에서 선점에 실패한다 */
    public function testCreateAttempt_exhaustedCoupon_returnsZero(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $coupon  = $this->insertCoupon(['total_qty' => 1]);

        $firstUc  = $this->insertUserCoupon($userId, $coupon['id']);
        $secondUc = $this->insertUserCoupon($userId, $coupon['id']);

        $first  = $this->createAttempt($userId, $product, 1, $coupon['id'], $firstUc, 3000);
        $second = $this->createAttempt($userId, $product, 1, $coupon['id'], $secondUc, 3000);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '소진된 쿠폰으로 두 번째 시도가 만들어지면 안 된다');

        // 두 번째 시도가 롤백됐으므로 used_count 는 1 에서 멈춰야 한다.
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);
        $this->assertSame('issued', $db->table('user_coupons')->where('id', $secondUc)->get()->getRowArray()['status']);
    }

    /** A-07: 이미 사용된 user_coupon 은 재선점되지 않는다 */
    public function testCreateAttempt_alreadyUsedUserCoupon_returnsZero(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser();
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $first  = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);
        $second = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);
    }
```

- [ ] **Step 2: 테스트 실행**

Run:
```bash
vendor/bin/phpunit --filter "testCreateAttempt_exhaustedCoupon|testCreateAttempt_alreadyUsedUserCoupon" tests/unit/OrderAttemptModelTest.php
```
Expected: `OK (2 tests, ...)` — Task 2의 `preemptCoupon()`이 이미 조건부 UPDATE를 쓰므로 바로 통과해야 한다. **실패하면 `preemptCoupon()`의 가드가 약해진 것이므로 즉시 고친다.**

- [ ] **Step 3: 커밋**

```bash
git add tests/unit/OrderAttemptModelTest.php
git commit -m "✅ test: 쿠폰 이중사용 방어를 attempt 기준으로 이전"
```

---

## Task 4: 원자적 클레임 + 실패·만료 복구

**Files:**
- Modify: `app/Models/OrderAttemptModel.php`
- Modify: `tests/unit/OrderAttemptModelTest.php`

**Interfaces:**
- Consumes: Task 2의 `createAttempt()`
- Produces:
  - `OrderAttemptModel::claimForConversion(int $attemptId): ?array` — 성공 시 attempt 배열, 이미 처리됐으면 `null`
  - `OrderAttemptModel::linkOrder(int $attemptId, int $orderId): void`
  - `OrderAttemptModel::markFailed(int $attemptId, string $reason): bool`
  - `OrderAttemptModel::expireStale(int $minutesOld = 30): int`
  - `OrderAttemptModel::findPendingForUser(int $attemptId, int $userId): ?array`

- [ ] **Step 1: 실패하는 테스트 추가**

`tests/unit/OrderAttemptModelTest.php` 마지막에 추가:

```php
    /** A-08: 클레임은 한 번만 성공한다 (결제 멱등성의 핵심) */
    public function testClaimForConversion_isIdempotent(): void
    {
        $userId    = $this->insertUser();
        $product   = $this->insertProduct();
        $attemptId = $this->createAttempt($userId, $product);

        $first  = $this->model->claimForConversion($attemptId);
        $second = $this->model->claimForConversion($attemptId);

        $this->assertNotNull($first);
        $this->assertSame($attemptId, (int) $first['id']);
        $this->assertNull($second, '두 번째 클레임은 반드시 실패해야 한다');
    }

    /** A-09: markFailed 는 쿠폰·포인트를 복구한다 */
    public function testMarkFailed_restoresCouponAndPoints(): void
    {
        $db           = db_connect();
        $userId       = $this->insertUser(5000);
        $product      = $this->insertProduct();
        $coupon       = $this->insertCoupon();
        $userCouponId = $this->insertUserCoupon($userId, $coupon['id']);

        $attemptId = $this->createAttempt($userId, $product, 1, $coupon['id'], $userCouponId, 3000, 5000);

        $this->assertTrue($this->model->markFailed($attemptId, '사용자 결제창 이탈'));

        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(0, (int) $db->table('coupons')->where('id', $coupon['id'])->get()->getRowArray()['used_count']);

        $uc = $db->table('user_coupons')->where('id', $userCouponId)->get()->getRowArray();
        $this->assertSame('issued', $uc['status']);
        $this->assertNull($uc['order_attempt_id']);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('failed', $attempt['status']);
        $this->assertSame('사용자 결제창 이탈', $attempt['fail_reason']);
    }

    /** A-10: markFailed 를 두 번 불러도 포인트는 한 번만 환급된다 */
    public function testMarkFailed_twice_refundsPointsOnce(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);

        $this->assertTrue($this->model->markFailed($attemptId, '1차'));
        $this->assertFalse($this->model->markFailed($attemptId, '2차'), '이미 처리된 시도는 다시 복구하면 안 된다');

        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(
            1,
            $db->table('point_logs')->where('order_attempt_id', $attemptId)->where('type', 'refund')->countAllResults()
        );
    }

    /** A-11: 클레임된 시도는 실패 처리되지 않는다 */
    public function testMarkFailed_afterClaim_returnsFalse(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);
        $this->model->claimForConversion($attemptId);

        $this->assertFalse($this->model->markFailed($attemptId, '경합'));
        $this->assertSame(0, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
    }

    /** A-12: 30분 초과 pending 은 만료되고 복구된다. orders 는 생기지 않는다 */
    public function testExpireStale_expiresAndRestores(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser(5000);
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product, 1, null, null, 0, 5000);
        $db->table('order_attempts')->where('id', $attemptId)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime('-40 minutes')),
        ]);

        $count = $this->model->expireStale(30);

        $this->assertSame(1, $count);
        $this->assertSame('expired', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
        $this->assertSame(5000, (int) $db->table('users')->where('id', $userId)->get()->getRowArray()['point_balance']);
        $this->assertSame(0, $db->table('orders')->where('user_id', $userId)->countAllResults());
    }

    /** A-13: 29분 지난 시도는 만료 대상이 아니다 */
    public function testExpireStale_recentAttempt_untouched(): void
    {
        $db      = db_connect();
        $userId  = $this->insertUser();
        $product = $this->insertProduct();

        $attemptId = $this->createAttempt($userId, $product);
        $db->table('order_attempts')->where('id', $attemptId)->update([
            'created_at' => date('Y-m-d H:i:s', strtotime('-29 minutes')),
        ]);

        $this->model->expireStale(30);

        $this->assertSame('pending', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
    }
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run:
```bash
vendor/bin/phpunit --filter "testClaimForConversion|testMarkFailed|testExpireStale" tests/unit/OrderAttemptModelTest.php
```
Expected: FAIL — `Call to undefined method App\Models\OrderAttemptModel::claimForConversion()`

- [ ] **Step 3: 메서드 구현**

`app/Models/OrderAttemptModel.php`의 `withItems()` 메서드 **위**에 다음을 추가한다:

```php
    /**
     * 전환을 위한 원자적 클레임.
     *
     * 조건부 UPDATE 가 행 잠금을 잡아 동시 콜백을 직렬화한다. 두 번째 요청은
     * affectedRows 가 0 이라 null 을 받는다 — orders.status='pending' 을 조건으로
     * 걸던 기존 confirmPaid() 의 멱등성 가드를 그대로 옮긴 것이다.
     *
     * @return array<string, mixed>|null 클레임에 성공한 attempt. 이미 처리됐으면 null.
     */
    public function claimForConversion(int $attemptId): ?array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE order_attempts SET status = ?, converted_at = ?, updated_at = ?
             WHERE id = ? AND status = ?',
            ['converted', $now, $now, $attemptId, 'pending']
        );

        if ($this->db->affectedRows() === 0) {
            return null;
        }

        $attempt = $this->db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();

        return $attempt === null ? null : $this->withItems($attempt);
    }

    /** 전환으로 만들어진 주문을 시도에 연결한다. */
    public function linkOrder(int $attemptId, int $orderId): void
    {
        $this->db->table('order_attempts')->where('id', $attemptId)->update([
            'order_id'   => $orderId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 결제 실패·이탈 확정 + 쿠폰·포인트 복구.
     *
     * 상태 전이를 조건부 UPDATE 로 먼저 확정하고, 성공했을 때만 복구를 돌린다.
     * 그래서 두 번 불려도 포인트가 두 번 환급되지 않는다.
     *
     * @return bool 이번 호출이 실제로 복구를 수행했으면 true
     */
    public function markFailed(int $attemptId, string $reason): bool
    {
        return $this->finalizeStale($attemptId, 'failed', $reason);
    }

    /**
     * N분 이상 지난 pending 시도를 만료 처리한다.
     *
     * 결제 실패 콜백이 오지 않은 경우의 안전망이다. 정상 이탈은 markFailed() 가
     * 즉시 걷어가므로 여기까지 오는 건 콜백 자체가 유실된 경우다.
     *
     * @return int 실제로 만료 처리된 건수
     */
    public function expireStale(int $minutesOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutesOld} minutes"));

        $rows = $this->db->table('order_attempts')
            ->select('id')
            ->where('status', 'pending')
            ->where('created_at <', $cutoff)
            ->get()->getResultArray();

        $count = 0;
        foreach ($rows as $row) {
            if ($this->finalizeStale((int) $row['id'], 'expired', '결제 미완료 자동 만료')) {
                $count++;
            }
        }

        return $count;
    }

    /** 사용자 소유의 진행 중 시도 1건. 콜백에서 쓴다. */
    /** @return array<string, mixed>|null */
    public function findPendingForUser(int $attemptId, int $userId): ?array
    {
        $attempt = $this->db->table('order_attempts')
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->get()->getRowArray();

        return $attempt === null ? null : $this->withItems($attempt);
    }

    /**
     * pending 시도를 종료 상태로 확정하고 쿠폰·포인트를 되돌린다.
     *
     * 상태 전이가 이번 호출에서 실제로 일어났을 때만 복구한다 — 이 순서가
     * 이중 환급을 구조적으로 막는다.
     */
    private function finalizeStale(int $attemptId, string $status, string $reason): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->transStart();

        $column = $status === 'failed' ? 'failed_at' : 'expired_at';
        $this->db->query(
            "UPDATE order_attempts SET status = ?, {$column} = ?, fail_reason = ?, updated_at = ?
             WHERE id = ? AND status = ?",
            [$status, $now, $reason, $now, $attemptId, 'pending']
        );

        if ($this->db->affectedRows() === 0) {
            $this->db->transComplete();

            return false;
        }

        $attempt = $this->db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->restoreCoupon($attempt);
        $this->restorePoints($attempt, $status);

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * 선점한 쿠폰을 되돌린다.
     *
     * @param array<string, mixed> $attempt
     */
    private function restoreCoupon(array $attempt): void
    {
        if (! $attempt['coupon_id'] || (int) $attempt['coupon_discount_amount'] === 0) {
            return;
        }

        $this->db->query(
            'UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE id = ?',
            [$attempt['coupon_id']]
        );

        $uc = $this->db->table('user_coupons')
            ->where('coupon_id', $attempt['coupon_id'])
            ->where('order_attempt_id', $attempt['id'])
            ->where('status', 'used')
            ->get()->getRowArray();

        if (! $uc) {
            return;
        }

        // 코드 입력으로 그 자리에서 만들어진 행은 되돌릴 원래 상태가 없다.
        if ($uc['source'] === 'code') {
            $this->db->table('user_coupons')->where('id', $uc['id'])->delete();

            return;
        }

        $this->db->table('user_coupons')->where('id', $uc['id'])->update([
            'status'           => 'issued',
            'order_attempt_id' => null,
            'used_at'          => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 선점한 포인트를 환급한다.
     *
     * @param array<string, mixed> $attempt
     */
    private function restorePoints(array $attempt, string $status): void
    {
        $used = (int) $attempt['point_used_amount'];
        if ($used <= 0) {
            return;
        }

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$used, $attempt['user_id']]
        );

        $this->db->table('point_logs')->insert([
            'user_id'          => $attempt['user_id'],
            'type'             => 'refund',
            'amount'           => $used,
            'order_id'         => null,
            'order_attempt_id' => $attempt['id'],
            'note'             => $status === 'expired' ? '주문 만료 포인트 환급' : '결제 미완료 포인트 환급',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }
```

- [ ] **Step 4: 테스트 실행해 통과 확인**

Run:
```bash
vendor/bin/phpunit tests/unit/OrderAttemptModelTest.php
```
Expected: `OK (14 tests, ...)`

- [ ] **Step 5: 커밋**

```bash
composer cs-fix
git add app/Models/OrderAttemptModel.php tests/unit/OrderAttemptModelTest.php
git commit -m "✨ feat: 주문 시도 원자적 클레임과 실패·만료 복구 추가"
```

---

## Task 5: OrderModel::convertAttempt() — 시도를 주문으로 전환

**Files:**
- Modify: `app/Models/OrderModel.php`
- Create: `tests/unit/OrderAttemptConversionTest.php`

**Interfaces:**
- Consumes: Task 4의 `claimForConversion()`, `linkOrder()`
- Produces:
  - `OrderModel::convertAttempt(int $attemptId, string $targetStatus, string $pgProvider, ?string $pgTid, string $method, array $rawResponse): int` — 생성된 order id, 실패 시 `0`

    `$targetStatus`는 `'paid'`(무료·PG 승인) 또는 `'awaiting_payment'`(무통장)다.

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/OrderAttemptConversionTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\OrderAttemptModel;
use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 주문 시도 → 주문 전환
 * 이슈 #214
 */
final class OrderAttemptConversionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private OrderModel        $orderModel;
    private OrderAttemptModel $attemptModel;

    /** @var array<string, array<int, int>> */
    private array $cleanup = [
        'order_attempts' => [],
        'orders'         => [],
        'products'       => [],
        'users'          => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['orders'] !== []) {
            foreach (['order_status_logs', 'point_logs', 'payments', 'order_items'] as $table) {
                $db->table($table)->whereIn('order_id', $this->cleanup['orders'])->delete();
            }
            $db->table('orders')->whereIn('id', $this->cleanup['orders'])->delete();
        }
        if ($this->cleanup['order_attempts'] !== []) {
            $db->table('point_logs')->whereIn('order_attempt_id', $this->cleanup['order_attempts'])->delete();
            $db->table('order_attempts')->whereIn('id', $this->cleanup['order_attempts'])->delete();
        }
        foreach (['products', 'users'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }

        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'octest_' . $uid,
            'email'         => 'oc-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'OCUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['users'][] = $id;

        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function insertProduct(array $extra = []): array
    {
        $db   = db_connect();
        $data = array_merge([
            'name'           => 'OCProd_' . uniqid(),
            'slug'           => 'oc-prod-' . uniqid(),
            'price'          => 10000,
            'cost_price'     => 0,
            'stock'          => 10,
            'status'         => 'on_sale',
            'shipping_type'  => 'free',
            'shipping_fee'   => 0,
            'free_threshold' => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $extra);
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return array_merge(['id' => $id], $data);
    }

    /** @param array<string, mixed> $product */
    private function createAttempt(int $userId, array $product, int $qty = 1): int
    {
        $id = $this->attemptModel->createAttempt(
            $userId,
            [
                'receiver_name'  => '테스트',
                'receiver_phone' => '010-0000-0000',
                'zipcode'        => '12345',
                'address1'       => '서울시 테스트구',
                'address2'       => null,
                'delivery_memo'  => null,
            ],
            [[
                'product_id'     => $product['id'],
                'name'           => $product['name'],
                'price'          => $product['price'],
                'discount_price' => null,
                'qty'            => $qty,
                'shipping_type'  => $product['shipping_type'],
                'shipping_fee'   => $product['shipping_fee'],
                'free_threshold' => $product['free_threshold'],
            ]],
            null,
            null,
            0,
            0,
            0,
            'toss'
        );
        if ($id > 0) {
            $this->cleanup['order_attempts'][] = $id;
        }

        return $id;
    }

    private function track(int $orderId): int
    {
        if ($orderId > 0) {
            $this->cleanup['orders'][] = $orderId;
        }

        return $orderId;
    }

    /** C-01: 전환하면 orders + order_items 가 생기고 재고가 차감된다 */
    public function testConvertAttempt_createsOrderAndDeductsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 3);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $orderId);

        $order = $db->table('orders')->where('id', $orderId)->get()->getRowArray();
        $this->assertSame('paid', $order['status']);
        $this->assertSame(30000, (int) $order['total_amount']);
        $this->assertNotNull($order['paid_at']);

        $items = $db->table('order_items')->where('order_id', $orderId)->get()->getResultArray();
        $this->assertCount(1, $items);
        $this->assertSame(3, (int) $items[0]['qty']);
        $this->assertSame(30000, (int) $items[0]['subtotal']);

        $this->assertSame(7, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $attempt = $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray();
        $this->assertSame('converted', $attempt['status']);
        $this->assertSame($orderId, (int) $attempt['order_id']);
    }

    /** C-02: 같은 시도를 두 번 전환해도 주문은 1건만 생긴다 (결제 멱등성) */
    public function testConvertAttempt_twice_createsSingleOrder(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product);

        $first  = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-A-' . uniqid(), 'card', []));
        $second = $this->track($this->orderModel->convertAttempt($attemptId, 'paid', 'toss', 'TID-B-' . uniqid(), 'card', []));

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '두 번째 전환은 반드시 거부돼야 한다');
        $this->assertSame(1, $db->table('orders')->where('user_id', $userId)->countAllResults());
        // 재고도 한 번만 차감돼야 한다.
        $this->assertSame(9, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
    }

    /** C-03: 무통장은 awaiting_payment 로 전환되고 재고는 아직 차감하지 않는다 */
    public function testConvertAttempt_bankTransfer_keepsStock(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 10]);
        $attemptId = $this->createAttempt($userId, $product, 2);

        $orderId = $this->track($this->orderModel->convertAttempt($attemptId, 'awaiting_payment', 'bank_transfer', null, '무통장입금', []));

        $this->assertGreaterThan(0, $orderId);
        $this->assertSame('awaiting_payment', $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status']);
        $this->assertSame(10, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);

        $payment = $db->table('payments')->where('order_id', $orderId)->get()->getRowArray();
        $this->assertSame('pending', $payment['status']);
    }

    /** C-04: 재고가 모자라면 주문을 취소 상태로 남겨 환불 추적이 가능하게 한다 */
    public function testConvertAttempt_insufficientStock_leavesCancelledOrderWithCharge(): void
    {
        $db        = db_connect();
        $userId    = $this->insertUser();
        $product   = $this->insertProduct(['stock' => 1]);
        $attemptId = $this->createAttempt($userId, $product, 5);
        $tid       = 'TID-FAIL-' . uniqid();

        $orderId = $this->orderModel->convertAttempt($attemptId, 'paid', 'toss', $tid, 'card', []);

        $this->assertSame(0, $orderId, '전환은 실패로 보고돼야 한다');

        // 청구는 이미 일어났으므로 환불 추적용 흔적이 남아야 한다.
        $order = $db->table('orders')->where('user_id', $userId)->get()->getRowArray();
        $this->assertNotNull($order);
        $this->track((int) $order['id']);
        $this->assertSame('cancelled', $order['status']);

        $payment = $db->table('payments')->where('pg_tid', $tid)->get()->getRowArray();
        $this->assertNotNull($payment);
        $this->assertSame('paid', $payment['status']);

        $this->assertSame(1, (int) $db->table('products')->where('id', $product['id'])->get()->getRowArray()['stock']);
        $this->assertSame('failed', $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status']);
    }
}
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run:
```bash
vendor/bin/phpunit tests/unit/OrderAttemptConversionTest.php
```
Expected: FAIL — `Call to undefined method App\Models\OrderModel::convertAttempt()`

- [ ] **Step 3: convertAttempt() 구현**

`app/Models/OrderModel.php`의 `confirmPaid()` 메서드 **바로 위**(현재 342행의 docblock 앞)에 추가한다. 파일 상단 `use` 에 `use App\Models\OrderAttemptModel;` 는 같은 네임스페이스라 필요 없다.

```php
    /**
     * 주문 시도를 실제 주문으로 전환한다.
     *
     * orders 에는 결제가 확정된 주문만 남긴다(이슈 #214). 이 메서드가 그 유일한
     * 진입점이며, OrderAttemptModel::claimForConversion() 의 조건부 UPDATE 로
     * 원자적 클레임을 먼저 잡아 중복 콜백을 막는다.
     *
     * 재고 차감이 실패하면 전환 트랜잭션을 롤백한 뒤, 이미 일어난 PG 청구를
     * 추적할 수 있도록 취소 상태의 주문 + 결제행을 남긴다(→ findRefundPending()).
     * 주문 자체를 만들지 않으면 payments.order_id 를 채울 수 없어 환불 대상이
     * 관리자 화면에서 사라진다.
     *
     * @param string                $targetStatus 'paid'(무료·PG 승인) 또는 'awaiting_payment'(무통장)
     * @param array<string, mixed>  $rawResponse
     *
     * @return int 생성된 주문 id. 실패하면 0.
     */
    public function convertAttempt(
        int $attemptId,
        string $targetStatus,
        string $pgProvider,
        ?string $pgTid,
        string $method,
        array $rawResponse
    ): int {
        $attemptModel = new OrderAttemptModel();

        $attempt = $attemptModel->claimForConversion($attemptId);
        if ($attempt === null) {
            return 0;
        }

        $items = $attempt['items'];
        $now   = date('Y-m-d H:i:s');

        $this->db->transStart();

        $orderId = (int) $this->insert([
            'user_id'                => (int) $attempt['user_id'],
            'order_number'           => $attempt['order_number'],
            'status'                 => $targetStatus,
            'total_product_price'    => (int) $attempt['total_product_price'],
            'shipping_fee'           => (int) $attempt['shipping_fee'],
            'total_amount'           => (int) $attempt['total_amount'],
            'coupon_id'              => $attempt['coupon_id'],
            'coupon_discount_amount' => (int) $attempt['coupon_discount_amount'],
            'point_used_amount'      => (int) $attempt['point_used_amount'],
            'point_earned_amount'    => (int) $attempt['point_earned_amount'],
            'payable_amount'         => (int) $attempt['payable_amount'],
            'receiver_name'          => $attempt['receiver_name'],
            'receiver_phone'         => $attempt['receiver_phone'],
            'zipcode'                => $attempt['zipcode'],
            'address1'               => $attempt['address1'],
            'address2'               => $attempt['address2'],
            'delivery_memo'          => $attempt['delivery_memo'],
            'paid_at'                => $targetStatus === 'paid' ? $now : null,
        ], true);

        $rows = [];
        foreach ($items as $item) {
            $rows[] = array_merge($item, ['order_id' => $orderId, 'created_at' => $now]);
        }
        if ($rows !== []) {
            $this->db->table('order_items')->insertBatch($rows);
        }

        // 무통장은 입금이 확인되는 시점(confirmBankTransfer)에 재고를 뺀다.
        if ($targetStatus === 'paid') {
            foreach ($rows as $item) {
                if (! $this->deductItemStock($item)) {
                    $this->db->transRollback();
                    $this->compensateFailedConversion($attempt, '재고 부족으로 결제 확정 실패 — 주문 취소', $pgTid === null ? null : [
                        'pg_provider' => $pgProvider,
                        'pg_tid'      => $pgTid,
                        'method'      => $method,
                        'amount'      => (int) $attempt['payable_amount'],
                        'raw'         => $rawResponse,
                    ]);

                    return 0;
                }
            }
        }

        $paymentOk = $this->db->table('payments')->insert([
            'order_id'     => $orderId,
            'pg_provider'  => $pgProvider,
            'pg_tid'       => $pgTid,
            'method'       => $method,
            'amount'       => (int) $attempt['payable_amount'],
            'status'       => $targetStatus === 'paid' ? 'paid' : 'pending',
            'raw_response' => json_encode($rawResponse, JSON_UNESCAPED_UNICODE),
            'paid_at'      => $targetStatus === 'paid' ? $now : null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // pg_tid UNIQUE 위반 등 INSERT 실패 시 명시적 롤백
        if (! $paymentOk || $this->db->affectedRows() === 0) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            return 0;
        }

        // 선점해 둔 쿠폰·포인트를 실제 주문에 연결한다. attempt 참조는 추적용으로 남긴다.
        $this->db->table('user_coupons')->where('order_attempt_id', $attemptId)->update(['order_id' => $orderId]);
        $this->db->table('point_logs')->where('order_attempt_id', $attemptId)->update(['order_id' => $orderId]);

        // 주문에 담긴 장바구니 행만 정확히 비운다(같은 상품의 다른 옵션은 남긴다).
        foreach ($rows as $item) {
            $builder = $this->db->table('cart_items')
                ->where('user_id', (int) $attempt['user_id'])
                ->where('product_id', (int) $item['product_id']);
            if ($item['sku_id']) {
                $builder->where('sku_id', (int) $item['sku_id']);
            } else {
                $builder->where('sku_id IS NULL', null, false);
            }
            $builder->delete();
        }

        $this->writeStatusLog($orderId, '', $targetStatus, match (true) {
            $pgProvider === 'free'          => '무료 주문 자동 확정 (쿠폰·포인트로 전액 차감)',
            $pgProvider === 'bank_transfer' => '무통장입금 주문 접수',
            default                         => 'PG 결제 확인 (' . $pgProvider . ')',
        });

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            return 0;
        }

        $attemptModel->linkOrder($attemptId, $orderId);

        return $orderId;
    }

    /**
     * 전환 실패 보상 — 이미 일어난 청구를 추적 가능한 형태로 남긴다.
     *
     * 시도는 이미 converted 로 클레임된 상태라 markFailed() 로는 되돌릴 수 없다.
     * 여기서 직접 failed 로 확정하고 쿠폰·포인트를 되돌린 뒤, 취소 상태의 주문과
     * 결제행을 만들어 findRefundPending() 이 환불 대상으로 잡을 수 있게 한다.
     *
     * 실패한 트랜잭션이 롤백된 뒤 호출해야 하며, 자체 트랜잭션으로 처리한다.
     *
     * @param array<string, mixed>                                                                                    $attempt
     * @param array{pg_provider: string, pg_tid: string, method: string, amount: int, raw: array<string, mixed>}|null  $charge
     */
    private function compensateFailedConversion(array $attempt, string $note, ?array $charge = null): void
    {
        // 직전 롤백으로 트랜잭션 상태가 false 로 남아 있으면 이 트랜잭션도 롤백된다.
        $this->db->resetTransStatus();
        $this->db->transStart();

        $now = date('Y-m-d H:i:s');

        $orderId = (int) $this->insert([
            'user_id'                => (int) $attempt['user_id'],
            'order_number'           => $attempt['order_number'],
            'status'                 => 'cancelled',
            'total_product_price'    => (int) $attempt['total_product_price'],
            'shipping_fee'           => (int) $attempt['shipping_fee'],
            'total_amount'           => (int) $attempt['total_amount'],
            'coupon_id'              => $attempt['coupon_id'],
            'coupon_discount_amount' => (int) $attempt['coupon_discount_amount'],
            'point_used_amount'      => (int) $attempt['point_used_amount'],
            'point_earned_amount'    => (int) $attempt['point_earned_amount'],
            'payable_amount'         => (int) $attempt['payable_amount'],
            'receiver_name'          => $attempt['receiver_name'],
            'receiver_phone'         => $attempt['receiver_phone'],
            'zipcode'                => $attempt['zipcode'],
            'address1'               => $attempt['address1'],
            'address2'               => $attempt['address2'],
            'delivery_memo'          => $attempt['delivery_memo'],
            'cancelled_at'           => $now,
        ], true);

        $rows = [];
        foreach ($attempt['items'] as $item) {
            $rows[] = array_merge($item, ['order_id' => $orderId, 'created_at' => $now]);
        }
        if ($rows !== []) {
            $this->db->table('order_items')->insertBatch($rows);
        }

        // 주문은 cancelled 인데 결제가 paid 로 남은 조합이 곧 "환불 필요" 신호다.
        if ($charge !== null && $charge['pg_tid'] !== '') {
            $this->db->table('payments')->ignore(true)->insert([
                'order_id'     => $orderId,
                'pg_provider'  => $charge['pg_provider'],
                'pg_tid'       => $charge['pg_tid'],
                'method'       => $charge['method'],
                'amount'       => (int) $charge['amount'],
                'status'       => 'paid',
                'raw_response' => json_encode($charge['raw'], JSON_UNESCAPED_UNICODE),
                'paid_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // 선점은 order_attempt_id 에 걸려 있으므로 두 키를 함께 넘긴다.
        // PHP 의 + 연산자는 왼쪽 배열의 키를 우선하므로 id 는 새 주문 id 로 덮인다.
        $restoreTarget = ['id' => $orderId, 'order_attempt_id' => $attempt['id']] + $attempt;
        $this->restoreCoupon($restoreTarget);
        $this->restorePoints($restoreTarget, 'stock');

        $this->db->table('order_attempts')->where('id', $attempt['id'])->update([
            'status'      => 'failed',
            'failed_at'   => $now,
            'fail_reason' => $note,
            'order_id'    => $orderId,
            'updated_at'  => $now,
        ]);

        $this->writeStatusLog($orderId, '', 'cancelled', $note);

        $this->db->transComplete();
    }
```

- [ ] **Step 3b: restoreCoupon 이 attempt 선점분도 찾도록 수정**

기존 `restoreCoupon()`([app/Models/OrderModel.php:1371](../../../app/Models/OrderModel.php))은 `user_coupons.order_id` 로만 선점분을 찾는데, 전환 전 선점은 `order_attempt_id` 에 걸려 있다. 1382-1386행의 조회를 아래로 교체한다:

```php
        $builder = $this->db->table('user_coupons')
            ->where('coupon_id', $order['coupon_id'])
            ->where('status', 'used');

        // 전환 전 선점분은 주문이 아니라 시도를 가리킨다. (이슈 #214)
        // attempt 참조가 넘어온 경우에만 그 조건을 쓴다 — null 을 그대로 OR 로
        // 걸면 "order_attempt_id IS NULL" 이 되어 다른 주문의 쿠폰까지 잡는다.
        if (! empty($order['order_attempt_id'])) {
            $builder->where('order_attempt_id', (int) $order['order_attempt_id']);
        } else {
            $builder->where('order_id', $order['id']);
        }

        $uc = $builder->get()->getRowArray();
```

그리고 1395-1400행의 복원 UPDATE에 `order_attempt_id` 초기화를 추가한다:

```php
        $this->db->table('user_coupons')->where('id', $uc['id'])->update([
            'status'           => 'issued',
            'order_id'         => null,
            'order_attempt_id' => null,
            'used_at'          => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
```

- [ ] **Step 4: 테스트 실행해 통과 확인**

Run:
```bash
vendor/bin/phpunit tests/unit/OrderAttemptConversionTest.php
```
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: 전체 스위트 회귀 확인**

Run:
```bash
vendor/bin/phpunit
```
Expected: 기존 테스트가 모두 그대로 통과한다(이 태스크는 새 메서드만 추가했다).

- [ ] **Step 6: 커밋**

```bash
composer cs-fix
git add app/Models/OrderModel.php tests/unit/OrderAttemptConversionTest.php
git commit -m "✨ feat: 주문 시도를 주문으로 전환하는 convertAttempt 추가"
```

---

## Task 6: Front\OrderController::create() 를 attempt 기반으로 전환

**Files:**
- Modify: `app/Controllers/Front/OrderController.php:195-301`

**Interfaces:**
- Consumes: Task 2의 `createAttempt()`, Task 5의 `convertAttempt()`
- Produces: PG 결제 응답의 `pgParams`가 attempt 배열로 만들어진다

- [ ] **Step 1: OrderAttemptModel 주입**

`app/Controllers/Front/OrderController.php` 상단 `use` 에 추가하고, 생성자에 프로퍼티를 추가한다:

```php
use App\Models\OrderAttemptModel;
```

클래스 프로퍼티 선언부(`private readonly OrderModel $orderModel;` 근처)에 추가:

```php
    private readonly OrderAttemptModel $attemptModel;
```

생성자(`$this->orderModel = new OrderModel();` 아래)에 추가:

```php
        $this->attemptModel = new OrderAttemptModel();
```

- [ ] **Step 2: createPending 호출을 createAttempt 로 교체**

195-208행의 블록을 아래로 교체한다:

```php
        // 주문은 결제가 확정될 때만 orders 에 남긴다(이슈 #214). 여기서는 시도만 만든다.
        $attemptId = $this->attemptModel->createAttempt(
            $userId,
            $shippingData,
            $items,
            $couponId,
            $resolvedUserCouponId,
            $couponDiscountAmount,
            $pointUse,
            $pointEarned,
            $isFreeOrder ? 'free' : $pgProvider
        );

        if ($attemptId === 0) {
            return $this->response->setJSON(['success' => false, 'message' => '주문 생성에 실패했습니다. (포인트 또는 쿠폰 처리 오류)']);
        }
```

- [ ] **Step 3: 무료 주문 분기 교체**

219-242행(무료 주문 블록)을 아래로 교체한다:

```php
        // 무료 주문 — 결제창 없이 바로 확정한다(재고 차감·장바구니 비우기는 convertAttempt 안에서).
        if ($isFreeOrder) {
            $orderId = $this->orderModel->convertAttempt($attemptId, 'paid', 'free', null, 'free', ['reason' => 'payable_amount = 0']);

            if ($orderId === 0) {
                log_message('error', "무료 주문 확정 실패 (재고 부족): attempt_id={$attemptId}");

                return $this->response->setJSON([
                    'success' => false,
                    // 쿠폰·포인트는 convertAttempt() 안에서 복구됐으므로 바로 다시 쓸 수 있다.
                    'message' => '재고가 부족해 주문을 완료할 수 없습니다. 사용하신 쿠폰·포인트는 복구되었습니다.',
                ]);
            }

            $order = $this->orderModel->getWithItems($orderId, $userId);
            session()->remove(CartModel::CHECKOUT_SESSION_KEY);

            return $this->response->setJSON([
                'success'  => true,
                'orderId'  => $orderId,
                'pgParams' => [
                    'pg'          => 'free',
                    'redirectUrl' => '/order/complete/' . $order['order_number'],
                ],
            ]);
        }
```

- [ ] **Step 4: 무통장입금 분기 교체**

244-279행(무통장 블록)을 아래로 교체한다. `payments` INSERT는 `convertAttempt()`가 대신하므로 사라진다:

```php
        // 무통장입금 — 입금 계좌를 주문내역에서 확인해야 하므로 즉시 주문으로 전환한다.
        if ($pgProvider === 'bank_transfer') {
            $orderId = $this->orderModel->convertAttempt($attemptId, 'awaiting_payment', 'bank_transfer', null, '무통장입금', []);

            if ($orderId === 0) {
                return $this->response->setJSON(['success' => false, 'message' => '주문 생성에 실패했습니다.']);
            }

            $order = $this->orderModel->getWithItems($orderId, $userId);

            // 주문에 담긴 장바구니 행만 정확히 비운다(같은 상품의 다른 옵션·미선택 항목은 남긴다).
            $this->cartModel->removeByIds(
                $userId,
                array_map(static fn (array $item): int => (int) $item['id'], $items),
            );
            session()->remove(CartModel::CHECKOUT_SESSION_KEY);

            return $this->response->setJSON([
                'success'  => true,
                'orderId'  => $orderId,
                'pgParams' => [
                    'pg'          => 'bank_transfer',
                    'redirectUrl' => '/order/bank_transfer/' . $order['order_number'],
                ],
            ]);
        }
```

- [ ] **Step 5: PG 결제 분기 교체**

281-300행을 아래로 교체한다. PG 어댑터에는 attempt 배열을 넘긴다 — `id`·`order_number`·`payable_amount`·`receiver_name`·`items` 를 모두 갖고 있어 기존 어댑터가 그대로 동작한다:

```php
        // PG 결제 — 승인 콜백이 와야 orders 로 전환된다. 여기서는 시도 배열을 넘긴다.
        $attempt  = $this->attemptModel->withItems(
            $this->attemptModel->find($attemptId)
        );
        $pg       = PGFactory::make($pgProvider);
        $pgParams = $pg->buildPaymentParams($attempt);

        if ($pgProvider === 'kakaopay' && isset($pgParams['tid'])) {
            session()->set('kakaopay_tid', $pgParams['tid']);
            session()->set('kakaopay_order_number', $attempt['order_number']);
        }
        // 승인(confirm) 요청의 orderId 는 결제창에 넘긴 값과 완전히 같아야 한다.
        // 어댑터가 만든 값을 그대로 보관해 두 값이 어긋날 여지를 없앤다.
        // (키 설정 오류로 어댑터가 error 만 돌려준 경우엔 orderId 자체가 없다.)
        if ($pgProvider === 'toss' && isset($pgParams['orderId'])) {
            session()->set('toss_order_id', (string) $pgParams['orderId']);
        }

        return $this->response->setJSON([
            'success'  => true,
            'attemptId' => $attemptId,
            'pgParams' => $pgParams,
        ]);
```

- [ ] **Step 6: fail() 라우트에서 시도를 즉시 실패 처리**

334행 `fail()` 메서드를 아래로 교체한다. 결제창을 닫은 사용자가 쿠폰·포인트를 30분 기다리지 않고 바로 다시 쓸 수 있게 한다:

```php
    /** GET /order/fail/:orderNumber */
    public function fail(string $orderNumber): \CodeIgniter\HTTP\RedirectResponse|string
    {
        $userId = (int) session()->get('user_id');

        // 결제가 확정되지 않은 시도는 즉시 걷어내 쿠폰·포인트를 돌려준다(이슈 #214).
        // 이미 전환됐으면 markFailed() 가 false 를 돌려주므로 아무 일도 일어나지 않는다.
        $attempt = $this->attemptModel
            ->where('order_number', $orderNumber)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
        if ($attempt !== null) {
            $this->attemptModel->markFailed((int) $attempt['id'], '결제 실패 또는 결제창 이탈');
        }

        // 토스는 카카오페이·PAYCO·이니시스·네이버페이와 달리 별도 취소 URL이 없어,
        // 사용자가 결제창을 그냥 닫아도 진짜 승인 실패와 구분 없이 failUrl(이 라우트)로
        // 온다. code 가 취소성 코드면 다른 PG와 동일하게 주문서로 돌려보낸다 — 진짜
        // 승인 실패(카드 한도 초과 등)는 이 코드가 아니므로 그대로 실패 화면을 보여준다.
        $code = $this->request->getGet('code');
        if (in_array($code, ['PAY_PROCESS_CANCELED', 'PAY_PROCESS_ABORTED', 'USER_CANCEL'], true)) {
            return redirect()->to('/order');
        }

        $order   = $this->orderModel->where('order_number', $orderNumber)->where('user_id', $userId)->first();
        $message = session()->getFlashdata('pg_error') ?? '결제에 실패했습니다.';

        return $this->render('shop/order_fail', ['order' => $order, 'message' => $message]);
    }
```

- [ ] **Step 7: 프런트엔드 응답 키 확인**

Run:
```bash
grep -rn "orderId" app/Views/shop/order.php app/Views/themes/*/shop/order.php 2>/dev/null | head -20
```
결제 요청 JS가 응답의 `orderId`를 쓰고 있으면, PG 분기에서 `'attemptId' => $attemptId` 와 함께 `'orderId' => $attemptId` 도 넣어 하위 호환을 유지한다(무료·무통장 분기는 실제 order id를 그대로 돌려주므로 변경 없음). 쓰지 않으면 그대로 둔다.

- [ ] **Step 8: 정적 분석·전체 테스트**

Run:
```bash
composer cs-fix && composer analyse && vendor/bin/phpunit
```
Expected: PHPStan `[OK] No errors`, PHPUnit 전체 통과. `createPending()`은 아직 남아 있으므로 기존 테스트는 그대로 통과한다.

- [ ] **Step 9: 커밋**

```bash
git add app/Controllers/Front/OrderController.php
git commit -m "♻️ refactor: 주문 생성을 order_attempts 기반으로 전환"
```

---

## Task 7: PG 어댑터·결제 콜백을 attempt_id 로 전환

**Files:**
- Modify: `app/Libraries/PG/TossPaymentsAdapter.php:57`, `InicisAdapter.php:58`, `NicePayAdapter.php:44`, `KakaoPayAdapter.php:111`, `NaverPayAdapter.php:53`, `PaycoAdapter.php:39`
- Modify: `app/Controllers/Front/PaymentController.php:30-123`

**Interfaces:**
- Consumes: Task 4의 `findPendingForUser()`, Task 5의 `convertAttempt()`
- Produces: 콜백 URL이 `attempt_id=<attempt id>` 를 싣는다

- [ ] **Step 1: 6개 어댑터의 콜백 URL 파라미터 변경**

각 파일에서 `order_id=` 를 `attempt_id=` 로 바꾼다. 주석도 함께 고친다.

`app/Libraries/PG/TossPaymentsAdapter.php:54-57`:

```php
            // 콜백 URL 은 다른 PG 어댑터와 동일하게 어댑터가 만든다.
            // 콜백은 주문 시도를 PK 로 조회하므로 attempt_id 에는 반드시 id 를 넘긴다
            // (토스가 successUrl 에 덧붙이는 orderId 는 주문번호라 이름이 겹치지 않는다).
            'successUrl'  => base_url('payment/callback/toss?attempt_id=' . $order['id']),
```

`app/Libraries/PG/InicisAdapter.php:58`:

```php
            'returnUrl' => base_url('payment/callback/inicis?attempt_id=' . $order['id']),
```

`app/Libraries/PG/NicePayAdapter.php:44`:

```php
            'returnUrl' => base_url('payment/callback/nicepay?attempt_id=' . $order['id']),
```

`app/Libraries/PG/KakaoPayAdapter.php:111`:

```php
            'approval_url'     => $baseUrl . 'payment/callback/kakaopay?attempt_id=' . $order['id'],
```

`app/Libraries/PG/NaverPayAdapter.php:53`:

```php
            'returnUrl'   => base_url('payment/callback/naverpay?attempt_id=' . $order['id']),
```

`app/Libraries/PG/PaycoAdapter.php:39`:

```php
            'returnUrl'     => base_url('payment/callback/payco?attempt_id=' . $order['id']),
```

- [ ] **Step 2: 변경 누락 확인**

Run:
```bash
grep -rn "order_id=" app/Libraries/PG/
```
Expected: 출력 없음(6곳 모두 `attempt_id=` 로 바뀌었다).

- [ ] **Step 3: PaymentController 콜백 전환**

`app/Controllers/Front/PaymentController.php` 상단 `use` 에 `use App\Models\OrderAttemptModel;` 를 추가하고, 프로퍼티·생성자에 모델을 추가한다:

```php
    private readonly OrderModel        $orderModel;
    private readonly OrderAttemptModel $attemptModel;

    public function __construct()
    {
        $this->orderModel   = new OrderModel();
        $this->attemptModel = new OrderAttemptModel();
    }
```

`callback()` 메서드의 36-58행을 아래로 교체한다:

```php
        $attemptId = (int) ($this->request->getGet('attempt_id') ?: $this->request->getPost('attempt_id'));
        // 배포 시점에 결제창이 떠 있던 사용자의 콜백은 아직 order_id 를 싣고 온다.
        // TODO(#214): 다음 릴리스에서 이 레거시 분기를 제거한다.
        $legacyOrderId = (int) ($this->request->getGet('order_id') ?: $this->request->getPost('order_id'));
        $userId        = (int) session()->get('user_id');

        if ((! $attemptId && ! $legacyOrderId) || ! $userId) {
            // 이니시스·나이스페이는 이 returnUrl 을 자기 iframe 안에서 직접 로드한다.
            // SameSite=Lax 세션 쿠키는 그런 크로스사이트 iframe 서브요청엔 실리지
            // 않아 userId 가 비어 보일 수 있다 — 잘못된 접근으로 단정하기 전에
            // 최상위 창을 같은 URL로 이동시켜 재시도할 기회를 준다.
            if (! $userId && FrameBridge::isFramed($this->request)) {
                return $this->response->setBody(FrameBridge::render((string) current_url(true)));
            }

            return redirect()->to('/')->with('error', '잘못된 접근입니다.');
        }

        // 금액 검증·주문번호 표시에 쓸 스냅샷. 신규는 시도, 레거시는 주문에서 읽는다.
        $snapshot = $attemptId > 0
            ? $this->attemptModel->findPendingForUser($attemptId, $userId)
            : $this->orderModel->where('id', $legacyOrderId)->where('user_id', $userId)->where('status', 'pending')->first();

        if (! $snapshot) {
            return redirect()->to('/')->with('error', '유효하지 않은 주문입니다.');
        }
```

이어서 60-123행에서 `$order` 를 `$snapshot` 으로 바꾸고, 확정 호출과 실패 처리를 아래로 교체한다:

```php
        // 네이버페이는 성공·취소 모두 같은 returnUrl로 오고 resultCode 로만 구분한다
        // (카카오페이·PAYCO·이니시스처럼 별도 취소 URL이 없다). 취소(결제창을 그냥
        // 닫음)는 승인 실패가 아니므로 시도를 걷어내고 주문서로 돌려보낸다.
        if ($pgProvider === 'naverpay') {
            $resultCode = $this->request->getGet('resultCode') ?? $this->request->getPost('resultCode');
            if ($resultCode === 'Fail') {
                if ($attemptId > 0) {
                    $this->attemptModel->markFailed($attemptId, '네이버페이 결제 취소');
                }

                return redirect()->to('/order');
            }
        }

        // PG별 토큰 파라미터 이름이 다름
        $pgToken = $this->resolvePgToken($pgProvider);
        if ($pgToken === '' || $pgToken === '0') {
            session()->setFlashdata('pg_error', '결제 정보를 받지 못했습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        $pg     = PGFactory::make($pgProvider);
        $result = $pg->confirm($pgToken, (int) $snapshot['payable_amount']);

        if (! $result['success']) {
            session()->setFlashdata('pg_error', $result['message'] ?? '결제 확인에 실패했습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        // 금액 2차 검증 (어댑터 내부에서도 검증하지만 여기서 한 번 더)
        if ((int) $result['amount'] !== (int) $snapshot['payable_amount']) {
            log_message('critical', "결제 금액 불일치: attempt_id={$attemptId}, expected={$snapshot['payable_amount']}, got={$result['amount']}");
            session()->setFlashdata('pg_error', '결제 금액이 일치하지 않습니다.');

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        // 재고 차감 + 주문 생성 (트랜잭션)
        $confirmed = $attemptId > 0
            ? $this->orderModel->convertAttempt($attemptId, 'paid', $pgProvider, $result['tid'], $result['method'], $result['raw']) > 0
            : $this->orderModel->confirmPaid($legacyOrderId, $pgProvider, $result['tid'], $result['method'], $result['raw']);

        if (! $confirmed) {
            // 쿠폰·포인트는 확정 경로 안에서 이미 복구되고 주문도 취소됐다.
            // 다만 PG 결제는 이 시점에 이미 승인(청구)된 상태이고, 자동 취소는
            // 아직 구현돼 있지 않다 — 실제로 환불이 나가기 전까지 "자동 환불"이라고
            // 안내해선 안 된다.
            session()->setFlashdata(
                'pg_error',
                '재고 부족으로 주문이 취소되었습니다. 사용하신 쿠폰·포인트는 복구되었습니다. '
                . '결제하신 금액은 확인 후 환불해 드립니다. 고객센터로 문의해 주세요.'
            );
            // TODO(#113): PG 자동 취소 요청 ($pg->cancel($result['tid'], $result['amount'], '재고 부족'))
            //             구현 전까지는 아래 critical 로그가 수동 환불의 유일한 단서다.
            log_message(
                'critical',
                "결제 확정 실패 (재고 부족) — 수동 환불 필요: attempt_id={$attemptId}, "
                . "pg={$pgProvider}, tid={$result['tid']}, amount={$result['amount']}"
            );

            return redirect()->to('/order/fail/' . $snapshot['order_number']);
        }

        return redirect()->to('/order/complete/' . $snapshot['order_number']);
```

- [ ] **Step 4: 정적 분석·전체 테스트**

Run:
```bash
composer cs-fix && composer analyse && vendor/bin/phpunit
```
Expected: PHPStan `[OK] No errors`, PHPUnit 전체 통과.

- [ ] **Step 5: 커밋**

```bash
git add app/Libraries/PG/ app/Controllers/Front/PaymentController.php
git commit -m "♻️ refactor: PG 콜백을 attempt_id 기반으로 전환 (레거시 order_id 호환 유지)"
```

---

## Task 8: 만료 커맨드 — 시도 만료 + 레거시 주문 만료 병행

**Files:**
- Modify: `app/Commands/ExpireOrders.php`
- Create: `tests/unit/ExpireOrdersCommandTest.php`

**Interfaces:**
- Consumes: Task 4의 `expireStale()`, 기존 `OrderModel::expirePending()`
- Produces: 없음

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/unit/ExpireOrdersCommandTest.php` 신규 생성:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * orders:expire — 신규 시도와 레거시 주문을 모두 걷어가는지
 * 이슈 #214
 *
 * 모델 메서드를 직접 부르지 않고 커맨드를 실제로 구동한다. 커맨드가 둘 중
 * 하나만 호출하도록 퇴행하면 이 테스트가 잡아야 하기 때문이다.
 */
final class ExpireOrdersCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** @var array<int, int> */
    private array $attemptIds = [];
    /** @var array<int, int> */
    private array $orderIds = [];
    private int $userId = 0;

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->orderIds !== []) {
            $db->table('order_status_logs')->whereIn('order_id', $this->orderIds)->delete();
            $db->table('orders')->whereIn('id', $this->orderIds)->delete();
        }
        if ($this->attemptIds !== []) {
            $db->table('order_attempts')->whereIn('id', $this->attemptIds)->delete();
        }
        if ($this->userId > 0) {
            $db->table('users')->where('id', $this->userId)->delete();
        }

        $this->attemptIds = [];
        $this->orderIds   = [];
        $this->userId     = 0;
        parent::tearDown();
    }

    private function insertUser(): int
    {
        $db  = db_connect();
        $uid = uniqid();
        $db->table('users')->insert([
            'username'      => 'exptest_' . $uid,
            'email'         => 'exp-test-' . $uid . '@test.com',
            'password'      => password_hash('test', PASSWORD_DEFAULT),
            'nickname'      => 'ExpUser',
            'role'          => 'member',
            'grade'         => 'bronze',
            'is_active'     => 1,
            'point_balance' => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->userId = (int) $db->insertID();

        return $this->userId;
    }

    /** E-01: 신규 시도와 레거시 pending 주문이 모두 만료된다 */
    public function testExpireHandlesAttemptsAndLegacyOrders(): void
    {
        $db     = db_connect();
        $userId = $this->insertUser();
        $old    = date('Y-m-d H:i:s', strtotime('-40 minutes'));

        // 신규 경로 — 주문 시도
        $db->table('order_attempts')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-EXPA-' . random_int(10000, 99999),
            'status'              => 'pending',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '테스트',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트구',
            'items_snapshot'      => '[]',
            'created_at'          => $old,
            'updated_at'          => $old,
        ]);
        $attemptId          = (int) $db->insertID();
        $this->attemptIds[] = $attemptId;

        // 레거시 경로 — 배포 전에 만들어진 pending 주문
        $db->table('orders')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-EXPO-' . random_int(10000, 99999),
            'status'              => 'pending',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '테스트',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트구',
            'created_at'          => $old,
            'updated_at'          => $old,
        ]);
        $orderId          = (int) $db->insertID();
        $this->orderIds[] = $orderId;

        // 커맨드를 실제로 구동한다. 모델을 직접 부르면 커맨드의 퇴행을 못 잡는다.
        command('orders:expire 30');

        $this->assertSame(
            'expired',
            $db->table('order_attempts')->where('id', $attemptId)->get()->getRowArray()['status'],
            '커맨드가 order_attempts 를 만료시키지 않았다'
        );
        $this->assertSame(
            'expired',
            $db->table('orders')->where('id', $orderId)->get()->getRowArray()['status'],
            '커맨드가 레거시 orders.pending 을 만료시키지 않았다 — 배포 호환 경로가 빠졌다'
        );
    }
}
```

- [ ] **Step 2: 테스트 실행**

Run:
```bash
vendor/bin/phpunit tests/unit/ExpireOrdersCommandTest.php
```
Expected: FAIL — `커맨드가 order_attempts 를 만료시키지 않았다`. 현재 커맨드는 `expirePending()` 만 부르므로 시도는 `pending` 그대로다.

> `schedule_orders_expire_enabled` 설정이 `0` 이면 커맨드가 즉시 스킵해 두 단언이 모두 실패한다. 실패 메시지가 예상과 다르면 먼저 `SELECT value FROM settings WHERE key_name = 'schedule_orders_expire_enabled'` 로 확인하고, 테스트 `setUp()` 에서 해당 설정을 `1` 로 맞춘 뒤 `tearDown()` 에서 되돌린다.

- [ ] **Step 3: 커맨드 수정**

`app/Commands/ExpireOrders.php` 의 `run()` 메서드를 아래로 교체한다:

```php
    public function run(array $params): void
    {
        $settings = new SettingModel()->getAllAsMap();
        if (! (bool) ($settings['schedule_orders_expire_enabled'] ?? 1)) {
            CLI::write('[orders:expire] 비활성화됨 — 스킵', 'yellow');

            return;
        }

        $minutes = (int) ($params[0] ?? 30);

        $attempts = new OrderAttemptModel()->expireStale($minutes);

        // 배포 전에 만들어진 orders.pending 행은 쿠폰·포인트를 선점한 상태다.
        // 이 호출을 빼면 그 선점이 영구히 잠긴다. 레거시 pending 이 0건이 된 걸
        // 확인한 뒤 제거한다.
        // TODO(#214): 다음 릴리스에서 아래 레거시 만료 호출을 제거한다.
        $legacy = new OrderModel()->expirePending($minutes);

        CLI::write("[orders:expire] 시도 {$attempts}건 / 레거시 주문 {$legacy}건 만료 처리 ({$minutes}분 초과)", 'green');
        log_message('info', "[orders:expire] 시도 {$attempts}건 / 레거시 주문 {$legacy}건 만료 처리");
    }
```

파일 상단 `use` 에 추가:

```php
use App\Models\OrderAttemptModel;
```

- [ ] **Step 4: 커맨드 실제 구동 확인**

Run:
```bash
php spark orders:expire 30
```
Expected: `[orders:expire] 시도 0건 / 레거시 주문 0건 만료 처리 (30분 초과)` (건수는 DB 상태에 따라 다를 수 있다). 예외 없이 종료하면 성공.

- [ ] **Step 5: 커밋**

```bash
composer cs-fix
git add app/Commands/ExpireOrders.php tests/unit/ExpireOrdersCommandTest.php
git commit -m "✨ feat: orders:expire 가 주문 시도와 레거시 주문을 함께 만료 처리"
```

---

## Task 9: 목록에서 pending 제외 + 연쇄 정리

**Files:**
- Modify: `app/Models/OrderModel.php` (`getByUser()`, `adminGetAll()`)
- Modify: `app/Controllers/Admin/OrderController.php:19-35`
- Modify: `app/Controllers/Admin/UserController.php:294-299`
- Modify: `app/Libraries/OrderAnomalyService.php:31`
- Modify: `app/Views/shop/orders/list.php:97`
- Modify: `tests/unit/OrderLifecycleTest.php`

**Interfaces:**
- Consumes: 없음
- Produces: 없음

- [ ] **Step 1: 실패하는 테스트 추가**

`tests/unit/OrderLifecycleTest.php` 의 `testGetByUser_cancelTab_includesExpiredOrders()` 아래에 추가:

```php
    /** E-08: getByUser 기본 조회는 레거시 pending 주문도 제외한다 */
    public function testGetByUser_defaultStatus_excludesLegacyPendingOrders(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product);

        $result = $this->model->getByUser($userId, ['status' => '']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertNotContains($orderId, $ids);
    }

    /** E-09: adminGetAll 기본 조회도 pending·expired 를 제외한다 */
    public function testAdminGetAll_excludesPendingAndExpired(): void
    {
        $userId  = $this->insertUser();
        $product = $this->insertProduct();
        $orderId = $this->createPendingOrder($userId, $product);

        $result = $this->model->adminGetAll(['status' => '']);

        $ids = array_map('intval', array_column($result['items'], 'id'));
        $this->assertNotContains($orderId, $ids);
    }
```

- [ ] **Step 2: 테스트 실행해 실패 확인**

Run:
```bash
vendor/bin/phpunit --filter "testGetByUser_defaultStatus_excludesLegacyPending|testAdminGetAll_excludesPendingAndExpired" tests/unit/OrderLifecycleTest.php
```
Expected: FAIL — `Failed asserting that an array does not contain <id>`

- [ ] **Step 3: getByUser 수정**

`app/Models/OrderModel.php` 의 `getByUser()` 에서 Task 이전 커밋으로 들어간 `else` 블록을 아래로 교체한다:

```php
        } else {
            // "전체" 탭 기본 조회에서는 결제가 확정되지 않은 주문을 제외한다.
            // 신규 주문은 order_attempts 에 쌓이므로 여기 걸리는 건 레거시 행뿐이다.
            // 만료 주문은 "취소/환불" 탭(status=cancel)에서 계속 확인 가능하다. (이슈 #214)
            $builder->whereNotIn('status', ['pending', 'expired']);
        }
```

- [ ] **Step 4: adminGetAll 수정**

`app/Models/OrderModel.php:1114-1116` 을 아래로 교체한다. 이 빌더는 `orders o` 별칭을 쓰므로 컬럼명에 `o.` 를 붙여야 한다:

```php
        if ($status !== '') {
            $builder->where('o.status', $status);
        } else {
            // 결제 확정 전 주문은 관리자 주문 목록에도 노출하지 않는다.
            // 레거시 행은 /admin/order-attempts 로그 페이지에서 조회한다. (이슈 #214)
            $builder->whereNotIn('o.status', ['pending', 'expired']);
        }
```

- [ ] **Step 5: 테스트 실행해 통과 확인**

Run:
```bash
vendor/bin/phpunit tests/unit/OrderLifecycleTest.php
```
Expected: `OK (37 tests, ...)`

- [ ] **Step 6: 관리자 상태 필터에서 pending·expired 제거**

`app/Controllers/Admin/OrderController.php:19-35` 의 `STATUS_LABELS` 에서 `'pending'` 과 `'expired'` 항목을 삭제한다. 남은 항목의 순서·값은 그대로 둔다.

- [ ] **Step 7: 회원 상세 주문 탭 정리**

`app/Controllers/Admin/UserController.php:294-299` 의 주문 조회 빌더에 아래 한 줄을 추가한다:

```php
            // 결제 확정 전 주문은 회원 상세에도 노출하지 않는다. (이슈 #214)
            ->whereNotIn('status', ['pending', 'expired'])
```

- [ ] **Step 8: 이상주문 탐지 대상 정리**

`app/Libraries/OrderAnomalyService.php:31` 의 `ACTIVE_STATUSES` 에서 `'pending'` 을 제거한다. 결제 확정 전 주문은 더 이상 `orders` 에 생기지 않으므로 탐지 대상이 아니다.

- [ ] **Step 9: 사용자 화면 취소 버튼 정리**

`app/Views/shop/orders/list.php:97` 의 취소 가능 상태 배열에서 `'pending'` 을 제거한다. 결제 확정 전 주문은 목록에 나타나지 않으므로 이 분기가 켜질 일이 없다.

- [ ] **Step 10: 대시보드 조건은 그대로 두는지 확인**

Run:
```bash
grep -n "pending" app/Controllers/Admin/DashboardController.php app/Controllers/Admin/SalesController.php
```
Expected: `NOT IN ('pending','expired', …)` 조건들이 그대로 남아 있다. **레거시 행이 계속 존재하므로 이 조건은 죽은 코드가 아니다 — 삭제하지 않는다.**

- [ ] **Step 11: 전체 검증**

Run:
```bash
composer cs-fix && composer ci
```
Expected: CS·PHPStan·PHPUnit 모두 통과.

- [ ] **Step 12: 커밋**

```bash
git add app/Models/OrderModel.php app/Controllers/Admin/OrderController.php app/Controllers/Admin/UserController.php app/Libraries/OrderAnomalyService.php app/Views/shop/orders/list.php tests/unit/OrderLifecycleTest.php
git commit -m "✨ feat: 주문 목록에서 결제 확정 전 주문 제외"
```

---

## Task 10: createPending 제거 + 기존 테스트 이전

`createPending()`은 Task 6 이후 프로덕션 호출자가 없다. 레거시 호환이 필요한 건 `confirmPaid()`·`expirePending()`뿐이므로 생성 경로만 제거한다.

**Files:**
- Modify: `app/Models/OrderModel.php` (`createPending()` 삭제)
- Modify: `tests/unit/OrderFlowTest.php`, `OrderLifecycleTest.php`, `OrderConfirmCompensationTest.php`, `CouponDoubleSpendTest.php`, `FreeOrderTest.php`, `PgRefundPendingTest.php`, `SkuPriceDiffChargeTest.php`, `SupplierCostTest.php`

**Interfaces:**
- Consumes: Task 2의 `createAttempt()`, Task 5의 `convertAttempt()`
- Produces: 없음

- [ ] **Step 1: 호출 지점 파악**

Run:
```bash
grep -rn "createPending" app/ tests/
```
Expected: `app/Models/OrderModel.php` 의 정의부와 8개 테스트 파일의 호출부만 나온다. `app/Controllers/` 에 남아 있으면 Task 6이 덜 끝난 것이므로 먼저 마무리한다.

- [ ] **Step 2: 각 테스트 파일의 헬퍼를 attempt 경유로 교체**

각 파일의 `createPending()` 호출 헬퍼를 아래 형태로 바꾼다. 기존 테스트가 기대하는 반환값은 **주문 id** 이므로, 시도를 만든 뒤 즉시 전환해 주문 id를 돌려준다.

예시 — `tests/unit/OrderLifecycleTest.php` 의 `createPendingOrder()` 를 아래로 교체한다(`use App\Models\OrderAttemptModel;` 를 상단에 추가):

```php
    /**
     * 결제 확정된 주문을 만든다.
     *
     * 주문 생성은 order_attempts 를 거치도록 바뀌었다(이슈 #214).
     * 시도를 만든 뒤 즉시 전환해 기존 테스트가 기대하는 주문 id 를 돌려준다.
     */
    private function createPaidOrder(
        int $userId,
        array $product,
        int $qty = 1,
        ?int $couponId = null,
        ?int $userCouponId = null,
        int $couponDiscount = 0,
        int $pointUsed = 0,
        int $pointEarned = 0
    ): int {
        $attemptId = new OrderAttemptModel()->createAttempt(
            $userId,
            $this->shippingData(),
            [$this->makeCartItem($product, $qty)],
            $couponId,
            $userCouponId,
            $couponDiscount,
            $pointUsed,
            $pointEarned,
            'toss'
        );

        if ($attemptId === 0) {
            return 0;
        }

        return $this->trackOrder(
            $this->model->convertAttempt($attemptId, 'paid', 'toss', 'TID-' . uniqid(), 'card', [])
        );
    }
```

`pending` 상태의 **레거시** 주문이 필요한 테스트(만료·취소 경로)는 `orders` 에 직접 INSERT 한다:

```php
    /** 레거시 pending 주문을 직접 만든다(만료 경로 회귀 테스트용). */
    private function insertLegacyPendingOrder(int $userId): int
    {
        $db = db_connect();
        $db->table('orders')->insert([
            'user_id'             => $userId,
            'order_number'        => 'ORD-LEG-' . random_int(10000, 99999),
            'status'              => 'pending',
            'total_product_price' => 10000,
            'total_amount'        => 10000,
            'payable_amount'      => 10000,
            'receiver_name'       => '테스트',
            'receiver_phone'      => '010-0000-0000',
            'zipcode'             => '12345',
            'address1'            => '서울시 테스트구',
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        return $this->trackOrder((int) $db->insertID());
    }
```

각 파일에서 다음 원칙으로 치환한다:
- **결제 완료 주문이 필요한 테스트** → `createPaidOrder()`
- **레거시 pending 주문 자체를 검증하는 테스트**(`expirePending`, `cancelOrder`의 pending 분기) → `insertLegacyPendingOrder()`
- **쿠폰 이중사용 테스트**(`CouponDoubleSpendTest`) → Task 3에서 이미 attempt 기준으로 옮겼으므로, 이 파일의 기존 케이스는 `createAttempt()` 를 직접 호출하도록 바꾼다

- [ ] **Step 3: 테스트 실행해 그린 확인**

Run:
```bash
vendor/bin/phpunit
```
Expected: 전체 통과. **실패가 남아 있으면 `createPending()` 을 아직 지우지 말고 먼저 그린을 만든다.**

- [ ] **Step 4: createPending 삭제**

`app/Models/OrderModel.php` 에서 `createPending()` 메서드 전체(현재 87-261행의 docblock 포함)를 삭제한다. `runGuardedUpdate()` 가 다른 곳에서 쓰이지 않으면 함께 삭제한다:

Run:
```bash
grep -n "runGuardedUpdate" app/Models/OrderModel.php
```
정의부만 남으면 정의부도 삭제한다.

- [ ] **Step 5: 남은 참조 확인**

Run:
```bash
grep -rn "createPending" app/ tests/
```
Expected: 출력 없음.

- [ ] **Step 6: 전체 검증**

Run:
```bash
composer cs-fix && composer ci
```
Expected: CS·PHPStan·PHPUnit 모두 통과.

- [ ] **Step 7: 커밋**

```bash
git add app/Models/OrderModel.php tests/
git commit -m "♻️ refactor: createPending 제거 및 기존 테스트를 주문 시도 기반으로 이전"
```

---

## Task 11: 통합 확인 및 PR 생성

**Files:** 없음(검증·PR만)

- [ ] **Step 1: 전체 품질 게이트**

Run:
```bash
composer cs-fix && composer ci
```
Expected: CS·PHPStan·PHPUnit 모두 통과. **하나라도 실패하면 PR을 만들지 않는다.**

- [ ] **Step 2: 개발 서버로 실제 흐름 확인**

Run:
```bash
php spark serve --port 8303
```

브라우저에서 다음을 확인한다:
1. 상품을 담고 주문서에서 **카드 결제**를 고른 뒤 결제창을 그냥 닫는다 → `/mypage/orders` 에 아무 주문도 생기지 않는다.
2. 같은 쿠폰·포인트로 **즉시** 다시 주문할 수 있다(30분 대기 없음).
3. **무통장입금**으로 주문한다 → 주문내역에 "입금대기"로 보이고 입금 계좌가 표시된다.
4. `/admin/orders` 에 결제 확정 전 주문이 보이지 않는다.

Run(DB 직접 확인):
```bash
mysql -h127.0.0.1 -ushop -p"$MYSQL_PWD" -e "SELECT id, status, order_number, fail_reason FROM order_attempts ORDER BY id DESC LIMIT 5;" ci4-shop
```
Expected: 1번에서 만든 시도가 `failed` 상태로 남아 있다.

- [ ] **Step 3: 푸시**

```bash
git push -u origin feature/order-history-paid-only
```

- [ ] **Step 4: PR 생성**

```bash
gh pr create --base dev --head feature/order-history-paid-only \
  --title "✨ feat: 주문내역에 결제 확정 주문만 남기기 (order_attempts 도입)" \
  --body "$(cat <<'EOF'
이슈 #214 의 PR1. 관리자 로그 페이지(`/admin/order-attempts`)는 PR2 로 이어집니다.

## 배경
카드 결제를 고르고 결제창을 닫으면 `orders` 에 `pending` 주문이 남아, 주문내역에 "취소와 재주문밖에 할 수 없는" 항목이 쌓였습니다.

## 변경 사항
- `order_attempts` 테이블 신설. 주문서 제출은 여기에 기록되고, 결제가 확정될 때만 `orders` 로 전환됩니다.
- 결제 멱등성 가드를 `orders.status='pending'` 에서 `order_attempts` 조건부 UPDATE 로 이전 — 보호 수준은 동일합니다.
- 쿠폰 잠금(이슈 #123 방어)은 그대로 유지하고 소유자 키만 `order_attempt_id` 로 옮겼습니다.
- 결제 실패·이탈 시 쿠폰·포인트를 **즉시** 복구합니다(기존 30분 대기 해소). 상태 전이를 먼저 확정하고 복구하므로 이중 환급이 구조적으로 불가능합니다.
- 사용자·관리자 주문 목록에서 레거시 `pending`/`expired` 를 제외했습니다.

## 배포 호환
- PG 콜백이 `attempt_id` 와 레거시 `order_id` 를 모두 받습니다.
- `orders:expire` 가 신규 시도와 레거시 `orders.pending` 을 모두 걷어갑니다. 둘 다 다음 릴리스에서 제거 예정이며 `TODO(#214)` 로 표시했습니다.

## 검증
- `composer ci` (CS Fixer + PHPStan + PHPUnit) 통과
- 결제창 이탈 → 주문내역 미생성, 쿠폰 즉시 재사용 가능 수동 확인
- 무통장입금 주문이 "입금대기"로 정상 노출되는지 수동 확인

설계 문서: `docs/superpowers/specs/2026-08-11-order-attempts-design.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 5: 이슈는 아직 닫지 않는다**

이슈 #214 는 PR2(관리자 로그 페이지)까지 머지된 뒤 `end-issue` 스킬로 닫는다.

---

## 자체 검토 결과

**스펙 커버리지**

| 스펙 항목 | 담당 태스크 |
|---|---|
| `order_attempts` 테이블 | Task 1 |
| `user_coupons`·`point_logs` 참조 컬럼 | Task 1 |
| `items_snapshot` JSON | Task 2 |
| 쿠폰·포인트 선점 이전 | Task 2, Task 3 |
| 원자적 클레임(멱등성) | Task 4, Task 5 (C-02) |
| 즉시 복구·이중 환급 방지 | Task 4 (A-10) |
| 무료·무통장 즉시 전환 | Task 5 (C-03), Task 6 |
| PG 콜백 전환 + 레거시 호환 | Task 7 |
| 레거시 pending 만료 병행 | Task 8 |
| 목록에서 제외 + 연쇄 정리 | Task 9 |
| 대시보드 조건 보존 | Task 9 (Step 10) |
| 전환 실패 보상 | Task 5 (C-04) |

PR2 범위(관리자 로그 페이지)는 이 계획에 포함하지 않는다 — 별도 계획으로 작성한다.

**스펙에 없던 결정 1건**

전환 실패 시 "관리자 알림"을 어떻게 구현할지 스펙이 열어두었다. 이 계획은 **취소 상태의 주문 + `paid` 결제행**을 남겨 기존 `findRefundPending()` 이 환불 대상으로 잡게 한다. 별도 알림 채널을 새로 만드는 것보다 관리자가 이미 쓰는 화면에 올리는 편이 낫고, `payments.order_id` 가 NOT NULL 이라 주문 없이는 청구 기록 자체를 남길 수 없다. 스펙의 위험 섹션에 이 결정을 반영해야 한다.
