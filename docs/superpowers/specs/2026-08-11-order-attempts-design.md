# 주문 시도(order_attempts) 분리 — 주문내역에 결제 완료건만 남기기

> 이슈 [#214](https://github.com/pushwing/AICopia/issues/214)

## 배경

`Front\OrderController::create()`([app/Controllers/Front/OrderController.php:195](../../../app/Controllers/Front/OrderController.php))는 주문서를 제출하는 즉시 `OrderModel::createPending()`([app/Models/OrderModel.php:94](../../../app/Models/OrderModel.php))으로 `orders` 행을 `status='pending'`으로 만든다. 카드 결제를 고르고 PG 결제창을 그냥 닫으면 이 행이 그대로 남아, 사용자 주문내역에 "취소와 재주문밖에 할 수 없는" 주문이 쌓인다. 관리자 주문 목록도 같다.

`pending` 행은 단순한 미완성 주문이 아니라 결제 안전장치 3개를 동시에 떠받치고 있다는 점이 이 작업의 핵심 제약이다.

1. **결제 멱등성** — `confirmPaid()`의 `WHERE id=? AND status='pending'`([OrderModel.php:348](../../../app/Models/OrderModel.php))이 PG 콜백 재전송·새로고침 시 중복 확정을 막는 주 방어선이다.
2. **쿠폰 이중사용 방지** — `createPending()` 트랜잭션 안의 조건부 UPDATE가 `coupons` 행 잠금을 잡아 동시 요청을 직렬화한다([OrderModel.php:182](../../../app/Models/OrderModel.php)). 이슈 #123의 재발 방지 장치이고 전용 테스트(`tests/unit/CouponDoubleSpendTest.php`)가 있다.
3. **쿠폰·포인트 소유권 키** — `user_coupons.order_id`, `point_logs.order_id`가 `orders.id`를 가리킨다.

따라서 "결제 완료 후에만 `orders`에 INSERT"로 단순 전환하면 이 세 가지가 한꺼번에 무너진다. 이 설계는 세 장치를 **없애지 않고 `order_attempts`로 그대로 이전**한다.

## 목표

**결제 의사가 확정된 주문만 `orders`에 남긴다.** 결제 완료 전 단계는 `order_attempts`에만 기록되고, 사용자·관리자 주문내역 어디에도 노출되지 않으며, 관리자 전용 로그 페이지에서만 조회된다.

부수 목표로, 결제창을 닫은 사용자가 30분을 기다리지 않고 같은 쿠폰·포인트로 즉시 재주문할 수 있게 한다.

## 범위 밖

- `orders.status` ENUM에서 `pending`·`expired` 값 제거. 운영 DB의 레거시 행이 그 값을 쓰고 있어 제거하면 데이터가 깨진다. 신규 생산만 중단하고 값 자체는 남긴다.
- 레거시 `pending`/`expired` 주문 행의 이관·삭제. `point_logs.order_id`·`user_coupons.order_id`가 참조 중이라 삭제 시 고아 참조가 생긴다. 그대로 두고 목록에서 숨기며, 로그 페이지에서 함께 조회한다.
- 이탈 상품 집계·분석 기능. 상품 라인을 JSON으로 보관하므로 SQL 집계가 불편하지만, 이슈가 요구하지 않는다.

## 결정 사항

| 결정 | 선택 | 근거 |
|---|---|---|
| 주문 시도 보관 | `order_attempts` 테이블 신설 | `orders`에 결제 완료건만 남기려면 물리적 분리가 필요 |
| 상품 라인 보관 | `items_snapshot JSON` 컬럼 1개 | `order_items`는 컬럼 12개로 계속 확장돼 왔다. 미러 테이블은 영구히 동기화 부담. `payments.raw_response`가 JSON을 쓰는 선례가 있다 |
| 무통장입금 | 주문서 제출 즉시 `orders`로 전환 | 사용자가 입금 계좌·금액·기한을 주문내역에서 확인해야 한다 |
| 레거시 행 | 보존 + 목록에서 숨김 | 삭제 시 `point_logs`·`user_coupons` 참조가 깨진다 |
| 쿠폰·포인트 복구 | 실패·이탈 즉시 | 30분간 자기 쿠폰을 못 쓰는 문제 해소. 30분 만료는 콜백 미수신 대비 안전망으로 유지 |
| PR 분할 | 2단계 | 결제 핵심 변경을 먼저 안정화한 뒤 화면을 올린다 |

## 데이터 모델

### 신규 `order_attempts`

주문서 제출 시점의 스냅샷. `orders`의 금액·배송지 컬럼을 그대로 갖는다.

```
id                     INT unsigned PK auto_increment
user_id                INT unsigned
order_number           VARCHAR(30)      UNIQUE
status                 ENUM('pending','converted','failed','expired') default 'pending'

total_product_price    INT unsigned default 0
shipping_fee           INT unsigned default 0
total_amount           INT unsigned default 0
coupon_id              INT unsigned null
coupon_discount_amount INT unsigned default 0
point_used_amount      INT unsigned default 0
point_earned_amount    INT unsigned default 0
payable_amount         INT unsigned default 0

receiver_name          VARCHAR(100)
receiver_phone         VARCHAR(20)
zipcode                VARCHAR(10)
address1               VARCHAR(200)
address2               VARCHAR(100) null
delivery_memo          VARCHAR(200) null

items_snapshot         JSON             -- order_items 로 그대로 전환 가능한 라인 배열
pg_provider            VARCHAR(20) null
order_id               INT unsigned null -- 전환 성공 시 연결
fail_reason            VARCHAR(200) null

converted_at, failed_at, expired_at, created_at, updated_at   DATETIME null

KEY idx_order_attempts_status_created (status, created_at)
KEY idx_order_attempts_user_id (user_id)
KEY idx_order_attempts_order_id (order_id)
```

`items_snapshot`의 각 원소는 `createPending()`이 현재 `order_items`에 넣는 것과 동일한 키를 갖는다(`order_id` 제외): `product_id`, `sku_id`, `parent_product_id`, `sku_option_label`, `product_name`, `product_price`, `cost_price`, `qty`, `subtotal`. 전환 시 `order_id`만 채워 `insertBatch`한다.

`idx_order_attempts_status_created`는 만료 스윕(`status='pending' AND created_at < cutoff`)과 로그 페이지 정렬을 함께 커버한다.

`pg_provider`는 `payments.pg_provider`와 달리 ENUM이 아니라 VARCHAR로 둔다. PG를 추가할 때마다 두 테이블의 ENUM을 같이 늘리는 결합을 만들지 않기 위해서다. attempt는 로그 성격이라 값 제약이 느슨해도 무방하다.

### 기존 테이블 변경

`user_coupons`, `point_logs` 각각에 `order_attempt_id INT unsigned null` + 인덱스를 추가한다. 선점 시 attempt를 가리키고, 전환 시 `order_id`를 채운다(`order_attempt_id`는 추적용으로 유지). nullable이라 기존 행에 영향이 없다.

`orders`는 스키마 변경이 없다.

## 상태 흐름

```
주문서 제출 → order_attempts(pending)
  ├─ 무료 주문     → 즉시 전환 → orders(paid)
  ├─ 무통장 선택   → 즉시 전환 → orders(awaiting_payment)
  └─ PG 결제 요청  → 결제창
        ├─ 승인 콜백   → 전환 → orders(paid)
        ├─ 실패/이탈   → attempts(failed) + 쿠폰·포인트 즉시 복구
        └─ 무응답 30분 → attempts(expired) + 복구   [안전망]
```

attempt는 **PG 결제창이 떠 있는 수 분 동안만** 존재한다. 무료·무통장은 attempt를 거치지만 즉시 전환되므로 사용자에게는 종전과 동일하게 보인다.

## 멱등성

전환 진입점에서 단일 조건부 UPDATE로 원자적 클레임을 잡는다.

```sql
UPDATE order_attempts SET status='converted', converted_at=?
 WHERE id=? AND status='pending'
```

`affectedRows = 0`이면 다른 요청이 이미 전환한 것이므로 중단한다. 이 UPDATE가 행 잠금을 잡아 동시 콜백을 직렬화하므로, 현재의 `WHERE orders.status='pending'`([OrderModel.php:348](../../../app/Models/OrderModel.php))과 동등한 보호다. `payments.pg_tid` UNIQUE는 2차 방어선으로 유지한다.

## 쿠폰·포인트

선점 시점은 바꾸지 않는다. attempt 생성 트랜잭션 안에서 `coupons` 행 잠금을 그대로 잡아 이슈 #123 방어를 유지하고, 소유자 키만 `order_id` → `order_attempt_id`로 옮긴다.

복구는 attempt를 원자적으로 잠글 수 있을 때만 실행한다.

```sql
UPDATE order_attempts SET status='failed', failed_at=?, fail_reason=?
 WHERE id=? AND status='pending'
```

`affectedRows = 1`일 때만 restore를 돌리므로 이중 환급이 구조적으로 불가능해진다. 현재 `restorePoints()`에 중복 방어가 없어 "포인트가 두 배로 환급된다"고 주석에 남아 있던 문제([OrderModel.php:1215](../../../app/Models/OrderModel.php))도 함께 해소된다. 만료(`expireAttempts()`)도 같은 조건부 UPDATE 패턴을 쓴다.

## 구성 요소

| 구성 요소 | 책임 | 의존 |
|---|---|---|
| `App\Models\OrderAttemptModel` (신규) | attempt 생성·원자적 클레임·실패/만료 전환, 쿠폰·포인트 선점과 복구 | `coupons`, `user_coupons`, `users`, `point_logs` |
| `App\Models\OrderModel` (변경) | attempt 스냅샷을 받아 `orders`+`order_items` 생성, 재고 차감, `payments` 기록 | `OrderAttemptModel`이 넘긴 배열 |
| `Front\OrderController` (변경) | 주문서 검증 → attempt 생성 → 3갈래 분기 | 위 두 모델 |
| `Front\PaymentController` (변경) | PG 콜백 수신 → attempt 클레임 → 전환 | 위 두 모델 |
| `Admin\OrderAttemptController` (신규, PR2) | 주문시도 로그 목록·상세 | `OrderAttemptModel` |

`OrderAttemptModel`은 "결제 전 단계"를, `OrderModel`은 "결제 확정 이후"를 담당한다. 전환 지점이 두 모델의 유일한 접점이며, 그 인터페이스는 attempt 배열 하나다.

## 화면·목록 변경

**사용자 주문내역** — `pending`이 신규 생성되지 않으므로 근본 해결된다. 레거시 행 때문에 `getByUser()`의 기본 제외를 `expired` → `pending`·`expired` 둘 다로 넓힌다([OrderModel.php:1069](../../../app/Models/OrderModel.php)). `app/Views/shop/orders/list.php:97`의 취소 가능 상태 목록에서 `pending`을 뺀다.

**관리자 주문 목록** — `adminGetAll()`([OrderModel.php:1092](../../../app/Models/OrderModel.php))에 동일한 제외를 넣고, 상태 필터 드롭다운([Admin/OrderController.php:19](../../../app/Controllers/Admin/OrderController.php))에서 `pending`·`expired` 항목을 제거한다. 회원 상세의 주문 탭([Admin/UserController.php:294](../../../app/Controllers/Admin/UserController.php))도 같다.

**연쇄 정리** — `OrderAnomalyService`의 `ACTIVE_STATUSES`에서 `pending`을 뺀다([OrderAnomalyService.php:31](../../../app/Libraries/OrderAnomalyService.php)).

대시보드·매출 집계의 `NOT IN ('pending','expired', …)` 조건([Admin/DashboardController.php:26](../../../app/Controllers/Admin/DashboardController.php))은 **레거시 행이 계속 존재하므로 그대로 둔다** — 죽은 조건이 아니다.

**만료 커맨드** — `orders:expire`는 `order_attempts`를 걷어가도록 바꾸되, **기존 `OrderModel::expirePending()` 호출도 당분간 함께 유지한다**(아래 배포 호환 참조). 커맨드 이름·스케줄 설정 키(`schedule_orders_expire_enabled`)는 그대로 둬서 `/admin/schedule` 설정이 깨지지 않게 한다.

## 배포 호환

배포 순간 결제창이 떠 있던 사용자가 두 곳에서 깨진다. 둘 다 한시적 호환 경로로 처리하고, 다음 릴리스에서 제거한다. 제거 대상임을 코드 주석에 남긴다.

**콜백 파라미터** — PG 콜백은 현재 `order_id`로 주문을 찾는다([PaymentController.php:36](../../../app/Controllers/Front/PaymentController.php)). 콜백이 두 파라미터를 모두 받아들이도록 한다: `attempt_id`가 있으면 신규 경로, 없고 `order_id`만 있으면 기존 `orders` 기반 확정 경로를 탄다.

**레거시 pending 만료** — 배포 직전에 만들어진 `orders.status='pending'` 행은 쿠폰·포인트를 선점한 상태다. `orders:expire`가 `order_attempts`만 보게 바꾸면 이 행들을 아무도 걷어가지 않아 **선점된 쿠폰·포인트가 영구히 잠긴다.** 커맨드가 `expireAttempts()`와 기존 `expirePending()`을 **둘 다** 호출하게 두고, 레거시 `pending`이 0건이 된 것을 확인한 뒤 다음 릴리스에서 `expirePending()` 호출을 제거한다. (`expired`로 바뀐 레거시 행은 그대로 보존되며 로그 페이지에서 조회된다.)

## 테스트

TDD로 진행하되 **기존 안전장치 테스트를 먼저 attempt 기준으로 이전**하는 것을 최우선으로 둔다.

- `CouponDoubleSpendTest` — attempt 기준으로 이전. **이 테스트가 통과하기 전에는 다음 단계로 가지 않는다.**
- 멱등성(신규) — 동일 attempt를 2회 전환 시도 → `orders` 1건만 생성.
- 이중 환급 방지(신규) — `markFailed()` 2회 호출 → 포인트 1회만 환급.
- 만료(신규) — `expireAttempts()` 후 `orders`에 아무것도 생기지 않고, 쿠폰·포인트가 1회만 복구됨.
- 무료 주문·무통장 주문이 즉시 `orders`로 전환되는지.
- `getByUser()`·`adminGetAll()`에서 레거시 `pending`/`expired`가 제외되는지.
- `orders:expire`가 `order_attempts`와 레거시 `orders.pending`을 **둘 다** 걷어가는지(배포 호환 경로).
- `items_snapshot` → `order_items` 전환 시 금액 정합성(`SUM(subtotal) === total_product_price`)이 유지되는지. 현재 `createPending()`이 하는 검증([OrderModel.php:170](../../../app/Models/OrderModel.php))을 attempt 생성 시점으로 옮긴다.

`createPending()`을 직접 호출하는 기존 8개 파일(`OrderFlowTest`, `OrderLifecycleTest`, `OrderConfirmCompensationTest`, `CouponDoubleSpendTest`, `FreeOrderTest`, `PgRefundPendingTest`, `SkuPriceDiffChargeTest`, `SupplierCostTest`)은 호출부를 치환한다.

## PR 분할

| | 범위 |
|---|---|
| **PR1** | 마이그레이션(테이블 1개 신설 + 컬럼 2개 추가) · `OrderAttemptModel` 신설 · `OrderModel` 전환 로직 · `Front` 컨트롤러 2개 · 목록 필터 · `orders:expire` 전환 · 테스트 |
| **PR2** | 관리자 주문시도 로그 페이지 `/admin/order-attempts`. `Admin\PointController`([app/Controllers/Admin/PointController.php:19](../../../app/Controllers/Admin/PointController.php))와 `app/Views/admin/points/` 패턴을 복제. 신규 attempt와 레거시 `orders`(`pending`/`expired`)를 함께 조회하고, 상태·기간·키워드(주문번호/회원) 필터와 `items_snapshot` 렌더링을 제공 |

이슈 214는 PR2까지 머지된 뒤 닫는다.

## 위험

**결제는 승인됐는데 주문이 생성되지 않는 상태**가 이 작업의 최대 위험이다. 전환 트랜잭션이 재고 부족 등으로 롤백되면 attempt는 `converted`로 잠긴 채 주문이 없는 상태가 된다. 현재도 같은 위험이 있어 `compensateFailedConfirm()`([OrderModel.php:1223](../../../app/Models/OrderModel.php))이 보상 경로를 맡고 있으므로, 이 경로를 attempt 기준으로 이전하고 전환 실패 시 attempt를 `pending`으로 되돌리는 대신 **`failed` + 사유 기록 + 관리자 알림**으로 처리한다. 되돌리면 같은 결제로 재전환이 시도될 수 있다.
