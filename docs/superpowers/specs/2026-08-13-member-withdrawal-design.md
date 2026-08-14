# 회원탈퇴 — 탈퇴회원 보관 테이블 + 개인정보 기간 경과 파기

## 배경

이 쇼핑몰에는 **회원탈퇴 기능이 아예 없다.** `Front\AuthController`([app/Controllers/Front/AuthController.php](../../../app/Controllers/Front/AuthController.php))에는 로그인·가입·프로필 수정만 있고, 회원이 스스로 계정을 정리할 경로가 없다.

관리자 쪽에는 삭제가 있지만 데이터를 망가뜨린다. `Admin\UserController::delete()`([app/Controllers/Admin/UserController.php:139](../../../app/Controllers/Admin/UserController.php))는 `users` 행을 그대로 hard delete 한다. 이 DB에는 **외래키 제약이 하나도 정의돼 있지 않아**(마이그레이션 전체에 `addForeignKey` 0건) 삭제가 에러 없이 통과하고, 대신 `orders`·`order_items`·`product_reviews`·`product_qnas`·`posts`·`post_comments`·`point_logs`·`user_coupons`의 `user_id`가 조용히 고아가 된다. 관리자 주문 상세에서 주문자를 조회할 수 없게 되고, 전자상거래법이 요구하는 5년치 거래기록의 주체 식별이 끊긴다.

동시에 개인정보보호법은 반대 방향을 요구한다 — 보유 목적이 끝난 개인정보는 파기해야 한다. 즉 **거래기록의 참조 무결성은 지키면서 개인정보만 골라 지우는** 구조가 필요하다.

## 목표

1. 회원이 마이페이지에서 스스로 탈퇴할 수 있다.
2. 탈퇴 시 개인정보 원본은 **`withdrawn_users` 테이블로 스냅샷 보관**하고, `users` 행은 삭제하지 않고 마스킹해 남긴다(tombstone).
3. 보관한 개인정보는 **설정된 기간이 지나면 배치가 자동 파기**한다. 통계용 메타(탈퇴일·사유·등급)는 남는다.
4. 탈퇴 계정으로는 일반 로그인·소셜 로그인 모두 불가능하다.
5. 진행 중인 거래가 있는 회원은 탈퇴할 수 없다.

## 범위 밖

- **주문·리뷰·게시글의 `user_id` 재작성.** tombstone이 참조를 지탱하므로 손댈 이유가 없다. 이 테이블들을 건드리는 순간 작업 범위가 8개 테이블로 번지고, 주문 상세의 주문자 조회가 오히려 깨진다.
- **부정 재가입 차단(N일 내 동일 이메일 재가입 금지).** 요구되지 않았다. 필요해지면 `withdrawn_users`에 이메일 해시 컬럼을 추가해 뒤에 붙일 수 있다.
- **탈퇴 철회·계정 복구 기능.** 파기 전이면 데이터가 남아 있어 이론상 가능하지만, 요구되지 않았고 복구 시 마스킹된 이메일 충돌 처리라는 별도 문제가 생긴다.
- **탈퇴 사유 통계 화면.** `withdrawn_users.reason_code`에 데이터는 쌓이지만 집계 화면은 만들지 않는다.
- **관리자 탈퇴회원 엑셀 내보내기.** 목록 조회만 제공한다.
- **`orders` ENUM·기존 마이그레이션 수정.** 신규 테이블과 컬럼 추가만 한다.

## 결정 사항

| 결정 | 선택 | 근거 |
|---|---|---|
| `users` 행 처리 | **삭제하지 않고 마스킹**(tombstone) | 외래키가 없어 삭제해도 DB 에러는 없지만 8개 테이블의 `user_id`가 고아가 된다. 마스킹은 참조를 지탱하면서 개인정보를 제거한다 |
| 개인정보 원본 보관처 | `withdrawn_users` 신규 테이블 | 파기 대상을 한 테이블에 모아야 배치가 단순해진다. `users`에 남기면 파기 시 tombstone까지 건드려야 한다 |
| 보관 기간 | `settings.withdrawal_retention_days`(기본 30) | 법적 요구·마케팅 정책 변경 시 배포 없이 대응. 기존 `schedule_*` 설정 패턴과 동일 |
| 탈퇴 판별 | `users.withdrawn_at IS NOT NULL` | `is_active=0`은 이미 **이메일 미인증** 계정이 쓰고 있어 재사용하면 두 상태가 섞인다 |
| 마스킹 이메일 | `withdrawn_{id}@deleted.local` | `users.email`의 UNIQUE 제약을 만족하면서 원래 이메일을 해방해 재가입이 가능해진다 |
| 재로그인 차단 | 마스킹으로 자동 성립 | `findByEmail()`이 `is_active=1`만 조회하고, 소셜은 `social_provider`+`social_id`로 조회하는데 둘 다 NULL이 된다. 로그인 코드 수정 불필요 |
| 관리자 삭제 | 강제 탈퇴로 **교체** | 현행 hard delete는 참조를 깨뜨린다. 같은 서비스 경로를 쓰면 관리자 삭제도 안전해진다 |
| 포인트 소멸 기록 | `point_logs.type='admin'` + note | ENUM에 `withdraw` 값을 추가하려면 `ALTER TABLE`이 필요한데, 기존 `admin` 타입으로 충분히 표현된다 |
| 진행 중 거래 | 탈퇴 **차단** | 배송 중인데 계정이 마스킹되면 CS 대응이 불가능하고, 환불금 수령 주체가 사라진다 |

## 데이터 모델

### 신규 `withdrawn_users`

```
id              INT unsigned PK auto_increment
user_id         INT unsigned                      -- 원 회원 ID (마스킹된 users 행과 연결)

-- 파기 대상 개인정보 (보관기간 경과 시 NULL)
username        VARCHAR(50)  null
email           VARCHAR(150) null
nickname        VARCHAR(50)  null
phone           VARCHAR(20)  null
gender          VARCHAR(10)  null
birthday        DATE         null
avatar          VARCHAR(255) null
social_provider VARCHAR(20)  null
social_id       VARCHAR(100) null

-- 통계용 메타 (파기하지 않음)
grade           VARCHAR(20)  null
point_balance   INT          default 0            -- 탈퇴 시점 잔여 포인트 (소멸분)
coupon_count    INT          default 0            -- 탈퇴 시점 미사용 쿠폰 수 (소멸분)
order_count     INT          default 0            -- 누적 주문 건수
joined_at       TIMESTAMP    null                 -- 원 users.created_at

-- 탈퇴 정보 (파기하지 않음)
reason_code     ENUM('unused','price','service','privacy','rejoin','admin','etc') default 'etc'
reason_text     VARCHAR(500) null                 -- 자유 입력. 파기 대상 (개인정보 포함 가능)
withdrawn_by    ENUM('member','admin') default 'member'
withdrawn_at    TIMESTAMP    null
purged_at       TIMESTAMP    null                 -- 파기 완료 시각 (NULL이면 미파기)

created_at      TIMESTAMP    null
updated_at      TIMESTAMP    null

KEY idx_withdrawn_users_user_id      (user_id)
KEY idx_withdrawn_users_withdrawn_at (withdrawn_at)
KEY idx_withdrawn_users_purged_at    (purged_at)
```

시각 컬럼은 전부 `TIMESTAMP`다(프로젝트 타임존 규약 — `DatabaseTimezoneTest::testNoDatetimeColumnsRemainInSchema()`가 `DATETIME`을 잡는다). `birthday`만 순수 날짜라 `DATE`를 쓴다.

`reason_text`는 자유 입력이라 개인정보가 섞일 수 있으므로 **파기 대상에 포함**한다. `reason_code`만 통계용으로 남는다.

### `users` 컬럼 추가

```
withdrawn_at    TIMESTAMP null    -- NULL이 아니면 탈퇴 회원
KEY idx_users_withdrawn_at (withdrawn_at)
```

### 탈퇴 시 `users` 마스킹 규칙

| 컬럼 | 값 | 이유 |
|---|---|---|
| `email` | `withdrawn_{id}@deleted.local` | UNIQUE 충족 + 원래 이메일 해방 |
| `username` | `withdrawn_{id}` | 식별 불가 |
| `nickname` | `탈퇴회원` | 리뷰·게시글 작성자 표시용 |
| `password` | `password_hash(random_bytes(32) hex)` | 로그인 불가. 빈 문자열이 아닌 유효 해시라야 `password_verify()`가 안전하게 실패한다 |
| `phone`·`gender`·`birthday`·`avatar` | NULL | 개인정보 제거 |
| `social_provider`·`social_id`·`social_token` | NULL | 소셜 재로그인 차단. `unique_social(social_provider, social_id)` 복합 UNIQUE는 NULL 중복을 허용하므로 충돌하지 않는다 |
| `email_verify_token`·`email_verify_token_at` | NULL | `uq_email_verify_token` UNIQUE 충돌 방지 |
| `point_balance` | 0 | 포인트 소멸 |
| `is_active` | 0 | 로그인 조회에서 제외 |
| `withdrawn_at` | `date('Y-m-d H:i:s')` | 탈퇴 표식 |

`role`·`grade`·`created_at`은 그대로 둔다 — 개인정보가 아니고 관리자 통계에서 쓴다.

## 컴포넌트

### `App\Libraries\WithdrawalService`

탈퇴 유스케이스 전부를 담는다. 컨트롤러는 검증·위임·응답만 한다.

```php
final class WithdrawalService
{
    /** @return array{allowed: bool, reasons: list<string>} */
    public function canWithdraw(array $user): array;

    /** @throws WithdrawalBlockedException */
    public function withdraw(int $userId, string $reasonCode, ?string $reasonText, string $by = 'member'): void;

    public function purgeExpired(int $retentionDays): int;

    /** @return array{point: int, coupon: int} 탈퇴 시 소멸되는 자산 (화면 경고용) */
    public function forfeitSummary(int $userId): array;
}
```

**`canWithdraw()` — 차단 조건 3종**

| 조건 | 판정 | 메시지 |
|---|---|---|
| 관리자 계정 | `role === 'admin'` | 관리자 계정은 탈퇴할 수 없습니다. |
| 진행 중 주문 | `orders.status IN ('pending','awaiting_payment','paid','preparing','shipped')` | 진행 중인 주문이 N건 있습니다. 배송 완료 후 탈퇴해 주세요. |
| 반품/교환/환불 진행 중 | `orders.status IN ('refund_requested','return_requested','return_approved','exchange_requested','exchange_approved')` | 처리 중인 반품·교환·환불이 N건 있습니다. |

두 주문 조건은 `orders` 한 번 조회로 상태별 집계해 판정한다(N+1 방지).

**`withdraw()` — 트랜잭션 1개**

```
$db->transStart()
  1. users 행 재조회 + canWithdraw() 재검사        ← TOCTOU 방지. 실패 시 예외로 롤백
  2. withdrawn_users INSERT (개인정보 스냅샷 + 메타 집계)
  3. users UPDATE (위 마스킹 규칙)
  4. 부수 데이터 정리
       cart_items          DELETE where user_id
       wishlists           DELETE where user_id
       shipping_addresses  DELETE where user_id
       restock_alerts      DELETE where user_id
       user_coupons        UPDATE status='expired' where user_id AND status='issued'
                           (행 삭제 금지 — uniq(user_id, coupon_id)가 재발급 이력을 지탱한다)
       point_logs          INSERT (type='admin', amount=-잔액, note='회원탈퇴로 인한 포인트 소멸')
$db->transComplete()
```

세션 파기는 서비스가 아니라 컨트롤러가 한다 — 서비스는 관리자 강제 탈퇴에서도 쓰이므로 호출자의 세션을 건드리면 안 된다.

**`purgeExpired()`**

`withdrawn_at < now - N일 AND purged_at IS NULL`인 행의 개인정보 컬럼(`username`·`email`·`nickname`·`phone`·`gender`·`birthday`·`avatar`·`social_provider`·`social_id`·`reason_text`)을 NULL로 UPDATE하고 `purged_at = now()`를 찍는다. 행 자체는 지우지 않는다 — 탈퇴 통계가 남아야 한다. 처리 건수를 반환한다.

### `App\Models\WithdrawnUserModel`

`$allowedFields`에 위 컬럼 전부 명시. 메서드:
- `snapshot(array $user, array $meta, string $reasonCode, ?string $reasonText, string $by): int`
- `paginateList(?string $keyword, int $perPage): array` — 관리자 목록(검색: 이메일·닉네임, 파기된 행은 `user_id`로만 검색)
- `purgeOlderThan(int $days): int`

### `App\Exceptions\WithdrawalBlockedException`

`app/Exceptions/`의 기존 도메인 예외(`AiKeyMissingException`) 패턴을 따른다. 차단 사유 목록을 담고, 컨트롤러가 리다이렉트 + 에러 메시지로 변환한다.

## 화면

### 회원 — `/auth/profile?tab=withdraw`

기존 `app/Views/auth/profile.php`의 탭 구조(현재 `info`/`password`)에 세 번째 탭을 추가한다. 뷰는 170줄이라 탭 하나 추가로 감당 가능하다.

- **차단된 경우**: 사유 목록을 안내 박스로 표시하고 폼은 렌더링하지 않는다.
- **가능한 경우**:
  - 소멸 항목 경고 — "보유 포인트 N점, 미사용 쿠폰 N장이 소멸되며 복구할 수 없습니다."
  - 유지 항목 안내 — "주문 내역은 전자상거래법에 따라 5년간 보관됩니다."
  - 탈퇴 사유 선택(`reason_code` ENUM 라디오) + 상세 사유 textarea(선택)
  - **본인 확인** — 일반 계정은 비밀번호 입력, 소셜 계정은 `탈퇴합니다` 문구 입력(비밀번호가 랜덤 생성돼 있어 검증 불가)
  - `<?= csrf_field() ?>` 필수

`AuthController::withdrawProcess()` — 검증 → `WithdrawalService::withdraw()` → `session()->destroy()` → 홈으로 완료 메시지.

라우트(기존 `auth/profile` 라우트 아래):
```php
$routes->post('auth/withdraw', 'Front\AuthController::withdrawProcess', ['filter' => 'auth:member']);
```

탭 자체는 기존 `GET auth/profile`이 `?tab=withdraw`로 처리하므로 새 GET 라우트가 필요 없다. `AuthController::profile()`이 `activeTab==='withdraw'`일 때 `canWithdraw()`·`forfeitSummary()` 결과를 뷰에 넘긴다.

### 관리자 — `/admin/users/withdrawn`

`Admin\UserController::withdrawn()` + `app/Views/admin/users/withdrawn.php`.

목록 컬럼: 원 회원ID · 이메일(파기 시 `—`) · 닉네임 · 등급 · 누적주문 · 소멸포인트 · 탈퇴사유 · 탈퇴경로(회원/관리자) · 탈퇴일 · 파기여부. 검색 + 페이징.

라우트는 `users/(:num)/edit`보다 **먼저** 등록해야 한다 — `withdrawn`이 `(:num)`에 걸리지는 않지만, 기존 `users/json`·`users/export`가 `users` 위에 있는 순서 관례를 따른다.

```php
$routes->get('users/withdrawn', 'Admin\UserController::withdrawn');
```

### 관리자 삭제 교체

`Admin\UserController::delete()`의 `$this->userModel->delete($id)`를 `WithdrawalService::withdraw($id, 'admin', $note, 'admin')`로 교체한다. 본인 계정 차단 로직은 유지하고, 성공 메시지를 "회원이 탈퇴 처리되었습니다."로 바꾼다. 관리자 강제 탈퇴는 진행 중 주문 차단을 **우회하지 않는다** — 배송 중 계정을 관리자가 지우는 것도 같은 사고이므로 차단 사유를 그대로 보여준다.

## 배치 파기

`App\Commands\PurgeWithdrawnUsers` — `PurgeAccessLogs`([app/Commands/PurgeAccessLogs.php](../../../app/Commands/PurgeAccessLogs.php)) 패턴 그대로.

```php
protected $name = 'users:purge-withdrawn';
// settings['schedule_users_purge_withdrawn_enabled'] 확인 후 스킵
// 기간: $params[0] ?? settings['withdrawal_retention_days'] ?? 30
// WithdrawalService::purgeExpired($days) 호출, 건수 CLI 출력 + log_message('info', ...)
```

`Config/Tasks.php`의 잡 매핑에 추가:
```php
'schedule_users_purge_withdrawn' => 'users:purge-withdrawn',
```

시더 마이그레이션으로 `settings` 3행 등록:

| group | key | value | type | label |
|---|---|---|---|---|
| `member` | `withdrawal_retention_days` | `30` | text | 탈퇴회원 개인정보 보관일수 |
| `schedule` | `schedule_users_purge_withdrawn_enabled` | `1` | boolean | 탈퇴회원 개인정보 파기 |
| `schedule` | `schedule_users_purge_withdrawn_cron` | `0 4 * * *` | text | 탈퇴회원 개인정보 파기 — 크론 주기 |

키·label 형식은 `2026-06-17-000043_SeedScheduleCronSettings`를 그대로 따른다(`0 3 * * *`인 등급 승급과 겹치지 않게 04시로 잡는다).

## 에러 처리

| 상황 | 처리 |
|---|---|
| 차단 조건 위반(폼 제출 시점) | `WithdrawalBlockedException` → 프로필 탭으로 리다이렉트 + 사유 표시 |
| 비밀번호 불일치 | validation 에러로 폼 재표시(`withInput()`) |
| 소셜 계정 확인 문구 불일치 | 동일 |
| 트랜잭션 실패 | 롤백 + `log_message('error', ...)` + "탈퇴 처리 중 오류가 발생했습니다" |
| 이미 탈퇴한 회원 재요청 | `withdrawn_at IS NOT NULL`이면 조용히 홈으로(멱등) |
| 배치 중 개별 행 실패 | 해당 행 스킵 + 에러 로그, 나머지 계속 처리 |

## 테스트

`tests/unit/WithdrawalServiceTest.php` (기존 유닛 테스트 관례: `CIUnitTestCase` + `DatabaseTestTrait`, `$DBGroup='tests'`, `$migrate=false`)

| 케이스 | 검증 |
|---|---|
| 관리자 계정 차단 | `canWithdraw()`가 `allowed=false` |
| 진행 중 주문 차단 | `paid`·`shipped` 주문 있는 회원 차단 |
| 반품 진행 중 차단 | `return_requested` 주문 있는 회원 차단 |
| 배송완료만 있으면 통과 | `delivered` 주문만 있는 회원은 `allowed=true` |
| 스냅샷 정확성 | `withdrawn_users` 행의 이메일·전화·등급이 원본과 일치 |
| 마스킹 결과 | `users` 행의 email이 `withdrawn_{id}@deleted.local`, phone/social_* NULL, `withdrawn_at` 설정 |
| 재로그인 불가 | `UserModel::findByEmail(원래이메일)`이 null |
| 소셜 재로그인 불가 | `social_provider`+`social_id` 조회가 null |
| 부수 데이터 정리 | `cart_items`·`wishlists`·`shipping_addresses` 0건 |
| 포인트 소멸 | `users.point_balance=0`, `point_logs`에 음수 행 1건 |
| 주문 참조 유지 | 탈퇴 후에도 `orders.user_id`로 tombstone 조인 성공 |
| 파기 배치 | 기간 경과 행만 개인정보 NULL·`purged_at` 설정, `reason_code`·`withdrawn_at`은 유지 |
| 파기 멱등성 | 두 번 돌려도 `purged_at`이 바뀌지 않고 0건 반환 |
| 재가입 | 탈퇴 후 동일 이메일로 신규 가입 성공 |

## 구현 순서

1. 마이그레이션 — `CreateWithdrawnUsers` + `AddWithdrawnAtToUsers` + `SeedWithdrawalSettings`
2. `WithdrawnUserModel` + `WithdrawalBlockedException`
3. `WithdrawalService` + 테스트 (TDD)
4. 회원 탈퇴 화면 (`profile.php` 탭 + `AuthController::withdrawProcess`) + 라우트
5. 배치 커맨드 + `Config/Tasks.php` 매핑
6. 관리자 탈퇴회원 목록 + `UserController::delete` 교체
7. `composer check` 전체 통과 → PR(`dev` 대상)
