# 코딩 표준 & 스타일

> 보안 관련 규칙(입력 검증·XSS·CSRF·시크릿·업로드)은 [security.md](security.md) 참조.

## 코딩 표준 (프로젝트 전역)

- PHP 8.5+ (타입 프로퍼티, match, 화살표 함수); PSR-12. (`composer.json`에서 `php >=8.5` 요구, CI·PHPStan 기준도 8.5)
- 모델은 `CodeIgniter\Model`을 상속하고 `$allowedFields`를 명시.
- 뷰는 네이티브 대체 문법(`<?php if (): ?> … <?php endif; ?>`) 사용, 모든 출력에 `esc()`.
- 입력은 `$this->request->getPost()`로 받고, 처리 전 `$this->validate()`로 검증.
- 모든 POST/PUT/DELETE 폼에 `<?= csrf_field() ?>` 포함(CSRF 예외 라우트 제외).
- DB 접근은 Query Builder만 사용 — 문자열 연결 raw SQL 금지.
- 하드코딩 시크릿 금지 — `env()` / Config 클래스 사용. `.env`는 절대 스테이징하지 말 것.

## 네이밍 규칙

### PHP

| 대상 | 규칙 | 예시 |
|------|------|------|
| 클래스 | PascalCase | `OrderController`, `CouponService` |
| 인터페이스 | PascalCase + `Interface` | `PGInterface`, `AiProviderInterface` |
| 추상 클래스 | `Base`/`Abstract` 접두어 | `BaseController`, `AbstractOAuthProvider` |
| 메서드 | camelCase | `confirmPaid()`, `buildPaymentParams()` |
| 변수·프로퍼티 | camelCase | `$cartCount`, `$authUser` |
| 상수 | UPPER_SNAKE_CASE | `MAX_RETRY`, `DEFAULT_TTL` |
| 배열 키 | snake_case | `$data['discount_price']`, `$payload['user_id']` |
| 파일명 | 클래스명과 동일 | `OrderController.php` |

### DB

| 대상 | 규칙 | 예시 |
|------|------|------|
| 테이블 | snake_case · 복수형 | `products`, `order_items`, `stock_logs` |
| 컬럼 | snake_case | `created_at`, `discount_price` |
| PK | `id` | `id` |
| FK | `{단수테이블명}_id` | `product_id`, `user_id` |
| 불리언 | `is_` 접두어 | `is_featured`, `is_hidden` |
| 타임스탬프 | CI4 표준 | `created_at`, `updated_at`, `deleted_at` |
| 일반 인덱스 | `idx_{테이블}_{컬럼}` | `idx_orders_status` |
| 유니크 인덱스 | `uniq_{테이블}_{컬럼}` | `uniq_payments_pg_tid` |
| Pivot 테이블 | 두 테이블 알파벳순 · 단수 | `product_categories` |

## 레이어 책임 (Controller · Service)

- **Controller는 얇게(thin)**: 유효성 검사 → Service 호출 → 렌더/응답만 수행.
- 비즈니스 로직이 Controller에 생기면 즉시 Service로 추출. 도메인 서비스는 `app/Libraries/`에 둠(`CouponService`, `GradeService`, `RecommendationService` 등).
- **하나의 Service 메서드 = 하나의 유스케이스.**
- **DB 트랜잭션은 Service/Model 레이어**에서 관리(`$db->transStart()` / `transComplete()`). 재고 차감 같은 동시성 로직은 `OrderModel`처럼 모델 메서드에 캡슐화.
- 복잡한 쿼리는 Model 메서드로 캡슐화한다(별도 Repository 레이어는 두지 않음).

## PHP / CI4 안티패턴 (금지)

> 보안 안티패턴은 [security.md](security.md) 참조.

### 코드 품질

| 금지 | 이유 |
|------|------|
| `@` 에러 억제 연산자 | 에러를 숨겨 디버깅 불가 |
| `extract()` / `global` | 변수 추적·테스트 불가 |
| 비즈니스 로직 안의 `die()`/`exit()` | 응답 흐름 단절 |
| `var_dump()`/`print_r()`/`dd()` 커밋 | 디버그 코드 노출 |
| 주석으로 비활성화한 죽은 코드 방치 | 가독성 저하 |
| 의미 없는 변수명(`$a`, `$tmp`, `$data2`) | 가독성 저하 |

### PHP 특성 함정

| 금지 | 이유 | 대신 |
|------|------|------|
| `==` 느슨한 비교 | `0 == "a"` → true | `===` 사용 |
| 타입 선언 없는 함수 파라미터/반환 | PHPStan 통과 불가 | `string $id`, `: int` 등 명시 |
| `null`·`false` 반환 혼용 | 호출부 처리 혼란 | 반환 타입 통일 |
| `catch` 후 예외 무시 | 버그가 조용히 삼켜짐 | 최소한 로깅 |

### CI4 한정

| 금지 | 이유 |
|------|------|
| Controller에 비즈니스 로직 작성 | Model/Service로 위임 |
| `$db->query("... WHERE id = $id")` | SQL Injection |
| `$allowedFields` 없는 Model | 의도치 않은 mass assignment |
| CSRF 예외 라우트 무분별 추가 | 보호 구멍 |
| 뷰에서 Model을 직접 호출해 조회 | MVC 책임 분리 위반 |

뷰는 컨트롤러가 전달한 데이터만 렌더링합니다.

```php
// ❌ 금지 — 뷰에서 직접 조회
$products = new \App\Models\ProductModel();
foreach ($products->findAll() as $item) { ... }

// ✅ 올바른 방식 — 컨트롤러에서 전달
// Controller
return $this->render('admin/products/index', [
    'products' => (new ProductModel())->findAll(),
]);
// View
foreach ($products as $item) { ... }
```

## 권장 PHP 스타일 (8.5+)

- 메서드·프로퍼티에 타입 선언(반환 타입 포함)을 적용 — PHPStan 레벨 5 전제.
- 분기는 `match` 표현식 우선(`switch` 지양).
- 상태·타입 값은 매직 넘버/문자열 대신 **Backed Enum** 사용 권장.

```php
enum OrderStatus: string
{
    case Pending   = 'pending';
    case Paid      = 'paid';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => '결제대기',
            self::Paid      => '결제완료',
            self::Delivered => '배송완료',
        };
    }
}
```

## 도메인 예외 처리

- 도메인 예외는 `app/Exceptions/`에 커스텀 클래스로 정의(예: `AiKeyMissingException`).
- 예외는 의미 있는 메시지를 포함하고, 컨트롤러에서 적절한 HTTP 상태/리다이렉트로 변환.
- `catch` 후 무시 금지 — 최소한 `log_message()`로 기록.

## 성능 · 쿼리 원칙

DB·렌더링 부하를 기본적으로 고려합니다.

- `SELECT *` 지양 — 필요한 컬럼만 명시.
- **N+1 쿼리 금지** — 관계 데이터는 JOIN 또는 일괄 조회로.
- 목록은 반드시 페이징 적용(`paginate()` 또는 `limit`/`offset`).
- 자주 `WHERE`로 거는 컬럼은 마이그레이션에 인덱스 정의(주문 상태, FK 등 — `AddPerformanceIndexes`/`AddOrderCompositeIndexes` 참고).
- 변경 빈도 낮은 조회(설정·메뉴·배너·팝업)는 파일 캐시 활용 — 쓰기 시 모델 콜백으로 무효화.
- 무거운 작업(대량 집계, 메일 발송, AI 추론 등)은 요청 사이클에서 처리하지 말고 배치 커맨드/`ai_jobs` 큐로 위임.
