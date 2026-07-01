# CLAUDE.md

이 파일은 이 저장소에서 작업할 때 Claude Code(claude.ai/code)에 제공되는 가이드입니다.

## 저장소 개요

**AICopia**는 단일 CodeIgniter 4 기반 쇼핑몰 솔루션입니다. 저장소 루트가 곧 하나의 CI4 프로젝트이며(`app/`, `public/`가 루트 바로 아래), 기업형 사이트(페이지·게시판·문의)와 풀 쇼핑몰(상품·장바구니·주문·PG 결제)을 AI 운영 보조 레이어(AI 카테고리 추천, 리뷰 요약, 문의 자동분류/Triage, 시맨틱 검색, 재입고 제안 등) 위에 함께 제공합니다.

모든 `php spark` / `composer` / `git` 명령은 **저장소 루트에서** 실행합니다. `default/`, `shop/` 같은 하위 프로젝트는 없습니다.

## 언어 규칙

- 모든 응답·설명은 한국어로 작성합니다.
- 코드 주석도 한국어로 작성합니다.
- 커밋 메시지는 한국어 + 변경 내용에 맞는 이모지 접두사(아래 Git 워크플로우 참고).

## 명령어

```bash
php spark serve              # 개발 서버 실행 (http://localhost:8080)
php spark migrate            # 대기 중인 마이그레이션 전체 실행 (테이블 생성 + 시드)
php spark migrate:rollback   # 마지막 마이그레이션 배치 롤백
php spark db:seed <Seeder>   # 시더 실행 (예: ProductSeeder, PostSeeder)

composer test                # PHPUnit
composer analyse             # PHPStan (레벨 5)
composer cs                  # 코드 스타일 검사 (PHP-CS-Fixer, dry-run)
composer cs-fix              # 코드 스타일 자동 수정
composer rector              # 리팩토링 미리보기 (dry-run)
composer rector-fix          # 리팩토링 적용
composer check               # cs + analyse + test 일괄
```

### 스케줄 / 배치 명령 (`app/Commands/`)

| 명령 | 클래스 | 용도 |
|------|--------|------|
| `php spark orders:expire [분]` | `ExpireOrders` | N분(기본 30분) 초과한 `pending` 주문 만료 처리 |
| `php spark grades:upgrade` | `UpgradeGrades` | 회원 등급 재계산 |
| `php spark coupons:birthday` | `IssueBirthdayCoupons` | 생일 쿠폰 발급 |
| `php spark stats:purge-logs` | `PurgeAccessLogs` | 오래된 접속 로그 정리 |
| `php spark ai:work` | `WorkAiJobs` | 대기 중인 AI 작업 처리 (`ai_jobs` 테이블) |

**크론 (운영 환경 — 단 1줄 등록):**
```
* * * * * cd /path/to/AICopia && php spark tasks:run >> /dev/null 2>&1
```
`Config/Tasks.php`가 `settings` 테이블에서 활성화된 잡을 읽어 스케줄러에 등록합니다. 잡→명령 매핑(`schedule_orders_expire`, `schedule_grades_upgrade`, `schedule_coupons_birthday`, `schedule_stats_purge_logs`, `schedule_ai_work`)은 **`/admin/schedule`**에서 관리합니다(활성화·주기 설정).

## 초기 설정

```bash
composer install
# 표준 CI4 스켈레톤은 gitignore 되어 있어 vendor에서 복원한다(커스텀 Config는 보존).
cp -rn vendor/codeigniter4/framework/app/Config/. app/Config/
[ -e system ] || ln -s vendor/codeigniter4/framework/system system
cp vendor/codeigniter4/framework/env .env   # 이후 편집: DB, CI_ENVIRONMENT, AI 키, PG 키, OAuth 키, SMTP
# app/Config/App.php: $appTimezone = 'Asia/Seoul' 로 설정
php spark migrate              # 테이블 생성 + 기본 데이터 시드
```

기본 관리자: `admin@example.com` / `admin1234!` (`2024-01-01-000002_SeedBoardData`에서 시드).

리눅스 업로드 권한: `chmod -R 755 public/uploads writable`

## Git 워크플로우

**브랜치 모델: `feature/* → dev → main`.**

- **`main`** — 운영/릴리스 브랜치. `dev`에서 올라온 PR로만 갱신.
- **`dev`** — 통합 브랜치. **`dev`는 절대 삭제 금지.** 모든 기능 작업은 먼저 여기로 머지.
- **`feature/xxx`** — 짧게 쓰는 작업 브랜치. 항상 **`dev`에서 분기**.

모든 변경의 표준 흐름:
```bash
git checkout dev && git pull origin dev
git checkout -b feature/<짧은-이름>      # dev에서 분기
# ...작업 후 커밋...
git push -u origin feature/<짧은-이름>
gh pr create --base dev --head feature/<짧은-이름>   # dev로 PR
# 리뷰/머지 후 feature 브랜치만 삭제 (dev 아님)
```
릴리스: 별도로 `dev → main` PR을 올립니다 (`gh pr create --base main --head dev`).

**규칙**
- `main`·`dev`에 직접 커밋 금지. 항상 `feature/*` 브랜치 + PR을 거칠 것.
- `dev` 브랜치는 절대 삭제하지 말 것.
- `feature/*` 브랜치는 PR 머지 후에만 삭제.
- 커밋 메시지: 한국어 + 변경 내용에 맞는 이모지 접두사(프로젝트 규칙). 논리적 작업 1개 = 커밋 1개.

## 아키텍처

### 테마 시스템

`ThemeView`(`app/Libraries/ThemeView.php`)가 CI4 기본 렌더러를 대체합니다(`Config/Services.php`에서 공유 렌더러로 연결). 뷰 탐색 순서:

1. `app/Views/themes/{active_theme}/{view}.php`
2. `app/Views/themes/default/{view}.php`
3. `app/Views/{view}.php` (관리자·콘텐츠 뷰 — 테마화 대상 아님)

활성 테마는 `settings.active_theme`에 저장(캐시)됩니다. 테마 추가는 `app/Views/themes/{name}/`와 `public/themes/{name}/`에 파일을 두고 `default`와 다른 부분만 오버라이드하면 됩니다. (설치용 테마 압축본 `dark.zip`, `spring.zip`, `violet.zip`은 `themes/` 아래에 있습니다.)

### BaseController — 전역 데이터 주입

모든 컨트롤러는 `BaseController`를 상속합니다. `initController()`가 매 요청마다 실행되어 `$this->viewData`에 다음을 주입합니다:

- `$settings` — 사이트 전역 키-값 설정 (캐시)
- `$menus` — 내비게이션 트리 (캐시)
- `$authUser` — 세션 기반 사용자 정보 (id, nickname, role, loggedIn)
- `$subLeftBanners` — 활성 사이드바 배너 (캐시, 관리자 라우트에서는 생략)
- `$activePopups` — 현재 URI에 해당하는 활성 팝업 (캐시)
- `$cartCount` — 장바구니 항목 수
- `$unreadInquiries` — 미확인 문의 수 (관리자 role 한정)

컨트롤러에서는 `$this->render('view/path', $extraData)`를 사용하세요. `$viewData`가 자동 병합됩니다.

### 컨트롤러

- `Controllers/Front/` — `Home`, `Shop`, `Cart`, `Order`, `Payment`, `MyPage`, `Coupon`, `Promotion`, `Board`, `Page`, `Auth`, `SocialAuth`.
- `Controllers/Admin/` — `Dashboard`, `Product`, `Inventory`, `Order`, `Sales`, `Stats`, `Coupon`, `Point`, `Grade`, `Promotion`, `Supplier`, `Review`, `Qna`, `Inquiry`, `Notification`, `User`, `Banner`, `Popup`, `Menu`, `PageManager/PostManager`, `BoardManager`, `Media`, `Schedule`, `Setting`, `Welcome`.

### 인증 & 라우팅

- 인증 필터 별칭: `auth` → `App\Filters\AuthFilter`; 사용 예 `['filter' => 'auth:member']` / `['filter' => 'auth:admin']`.
- `StatsFilter`가 접속 로그를 기록합니다.
- 모든 `/admin/*` 라우트는 `auth:admin` 필요.
- 장바구니 조회/수정/삭제는 `auth:member` 필요. `cart/add`(POST)는 비회원도 가능(세션 장바구니).
- 동적 페이지 catch-all 라우트 `(:segment)` → `Front\PageController::show`는 `Routes.php`에서 **반드시 맨 마지막**에 위치해야 함.

### CSRF 예외 (`Config/Filters.php`)

PG 서버 등 외부에서 CSRF 토큰 없이 POST가 들어오는 라우트는 제외됩니다:
- `api/*`
- `payment/callback/*` (PG 서버 콜백)
- `board/image-upload`
- `admin/media/upload`

### PG 결제 레이어

`PGInterface`는 `buildPaymentParams()`, `confirm()`, `cancel()`를 정의합니다. `PGFactory::create(string $provider)`가 어댑터를 해석합니다. 키는 `Config/PG.php`에 있고 모두 `.env`에서 읽습니다.

| Provider 키 | 어댑터 | PG |
|-------------|--------|----|
| `bank_transfer` | `BankTransferAdapter` | 무통장입금 |
| `toss` | `TossPaymentsAdapter` | 토스페이먼츠 |
| `inicis` | `InicisAdapter` | KG이니시스 |
| `nicepay` | `NicePayAdapter` | 나이스페이 |
| `kakaopay` | `KakaoPayAdapter` | 카카오페이 |
| `naverpay` | `NaverPayAdapter` | 네이버페이 |
| `payco` | `PaycoAdapter` | PAYCO |

### 재고 관리

**원칙: 재고는 PG 성공 콜백(또는 관리자 무통장입금 확인) 시점에만 차감합니다. 장바구니 담기 시점에는 절대 차감하지 않습니다.**

`OrderModel::confirmPaid()` / `confirmBankTransfer()`는 트랜잭션 안에서 2단계 동시성 가드를 사용합니다:
1. `SELECT stock ... FOR UPDATE` — 행 단위 잠금
2. `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?` — 조건부 업데이트. 영향 행 0이면 롤백

`payments.pg_tid`에 UNIQUE 제약이 있어 중복 PG 콜백은 조용히 거부됩니다. 조정 내역은 `stock_logs`에 감사 기록됩니다.

주문 상태 흐름(단방향, `OrderModel::updateStatus()`에서 강제):
```
pending → [PG 결제] → paid → preparing → shipped → delivered
pending → [무통장입금] → awaiting_payment → [관리자 확인] → paid
pending (30분 내 미확인) → expired                          (재고 미점유)
paid/preparing → cancelled                                  (재고 복원)
refund_requested → refunded

delivered → [회원, 7일 이내] → return_requested
    → [관리자 승인] → return_approved → [환불 확정] → refunded
    → [관리자 거부] → delivered

delivered → exchange_requested
    → [관리자 승인] → exchange_approved → exchange_completed
```
`delivered_at`은 `delivered` 전환 시 설정됩니다. 반품/교환 기간은 `delivered_at` 기준 7일(null은 레거시 주문으로 항상 허용).

### AI 운영 레이어

AI 기능은 `AiProviderInterface`(`app/Libraries/AiProvider/`)를 통해 동작하며 다음을 제공합니다:
`suggestCategories`, `generateDescription`, `generateQnaAnswer`, `summarizeReviews`, `classifyInquiry`, `generateInquiryReply`, `generateSalesReport`, `generateRestockMessage`, `expandSearchQuery`.

- **Provider 선택**: `settings['ai_provider']` (없으면 `env('AI_PROVIDER', 'groq')`). 지원: `groq`(`GroqProvider`), `claude`(`ClaudeProvider`), `openrouter`(`OpenRouterProvider extends GroqProvider`). API 키는 설정값 우선, 그다음 env(`GROQ_API_KEY`, `OPENROUTER_API_KEY` 등).
- **비동기 잡**: 오래 걸리는 AI 작업은 `ai_jobs` 테이블에 큐잉되고 `php spark ai:work`(→ `AiJobRunner`)가 처리합니다. 등록 핸들러: `review_summary`(`ReviewSummaryHandler`), `inquiry_classify`(`InquiryClassifyHandler`). `AiCache`가 결과를 메모이즈하고, `AiPrompts`가 프롬프트 템플릿을 보관합니다.
- **상위 서비스**(`app/Libraries/`): `AiCategoryAdvisor`, `RecommendationService`, `SemanticSearchService`, `RestockSuggestionService`, `OrderAnomalyService`, `NaverShoppingProvider`, `SeoHelper`.

### 회원 등급 / 쿠폰 / 포인트 시스템

- `GradeService` — 등급 티어 및 승급(`grades:upgrade`가 재계산; `AddGradeSystem` 마이그레이션).
- `CouponService` — 쿠폰 발급/사용(`coupons`, `user_coupons`); 생일 쿠폰은 `coupons:birthday`.
- 포인트 — `point_logs`(earn/use/refund/cancel/admin), `users.point_balance`.

### 소셜 로그인 (OAuth)

`AbstractOAuthProvider` 베이스에 `GoogleProvider`, `NaverProvider`, `KakaoProvider`. `OAuthFactory::create(string $provider)`가 provider를 해석합니다. 키는 `Config/OAuth.php`(및 `Config/Naver.php`)에 있고 `.env`에서 읽습니다.

### 파일 업로드

| 클래스 | 용도 |
|--------|------|
| `FileUploader` | 게시판 첨부 — 확장자 화이트리스트, 최대 10MB, 랜덤 hex 파일명 |
| `ImageUploader` | 배너/팝업/상품 이미지 — 이미지 전용, 용량 제한 |
| `MediaUploader` | 관리자 미디어 라이브러리 — 드래그앤드롭, 경로를 `media` 테이블에 저장 |

### 캐싱 전략

CI4 파일 캐시를 다음에 사용합니다:
- `site_settings` — 전체 설정 키-값 맵 (`SettingModel`)
- `nav_menus` — 메뉴 트리 (`MenuModel`)
- `active_banners_{position}` — 포지션별 배너 (`BannerModel`)
- `active_popups` — 활성 팝업 + 페이지 URL 매핑 (`PopupModel`)

모델 콜백(`afterInsert/Update/Delete`)이 관리자 쓰기 시 해당 캐시 키를 무효화합니다. 배너/팝업 만료는 캐시된 데이터에 대해 PHP에서 확인하므로 시간 기반 캐시 무효화가 필요 없습니다.

### DB 스키마 요약

```
users                — 회원/관리자 role, 소셜 로그인 필드, 등급, point_balance
settings             — 키-값 사이트 설정 (active_theme, ai_provider, smtp, schedule_* 등)
menus                — 2단계 내비게이션 트리
pages                — slug 기반 동적 페이지
boards / posts / post_files / post_comments   — 게시판 시스템
inquiries            — 문의 폼 (+ AI triage 컬럼)
banners / popups / popup_pages                — 마케팅 오버레이
media                — 미디어 라이브러리
categories           — 상품 카테고리 (parent_id 계층)
products             — price, discount_price, stock, status, shipping_*, supplier_fk, is_featured
product_images       — 상품별 다중 이미지, is_primary 플래그
product_options / product_skus                — 옵션 조합 & SKU
product_reviews      — 리뷰 (is_hidden, is_negative); AI 요약
product_qnas         — 상품 Q&A
cart_items           — user_id 또는 session_id (비회원 장바구니)
wishlists            — 회원별 찜 상품
orders               — 헤더, status, 배송 스냅샷, delivered_at, 반품/교환 필드
order_items          — 주문 시점 상품 스냅샷
order_status_logs    — 상태 변경 감사 (admin/member/system)
order_memos          — 관리자 내부 메모
exchange_items       — 교환 라인 아이템
shipping_addresses   — 회원별 저장 주소
payments             — pg_tid UNIQUE, PG 원응답 JSON 저장
stock_logs           — 재고 조정 감사
restock_alerts       — 재입고 알림 요청
coupons / user_coupons                         — 쿠폰 시스템
point_logs           — 포인트 적립/사용/환불/취소
promotions           — 프로모션 캠페인
suppliers            — 공급사/사업자 정보
access_logs / access_log_summaries             — 방문자 분석
ai_jobs              — 비동기 AI 잡 큐
```

## 코딩 표준 (프로젝트 전역)

- PHP 8.5+ (타입 프로퍼티, match, 화살표 함수); PSR-12. (`composer.json`에서 `php >=8.5` 요구, CI·PHPStan 기준도 8.5)
- 모델은 `CodeIgniter\Model`을 상속하고 `$allowedFields`를 명시.
- 뷰는 네이티브 대체 문법(`<?php if (): ?> … <?php endif; ?>`) 사용, 모든 출력에 `esc()`.
- 입력은 `$this->request->getPost()`로 받고, 처리 전 `$this->validate()`로 검증.
- 모든 POST/PUT/DELETE 폼에 `<?= csrf_field() ?>` 포함(위 CSRF 예외 라우트 제외).
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

### 보안

| 금지 | 이유 | 대신 |
|------|------|------|
| `$_GET`·`$_POST` 직접 사용 | 필터링 없는 원시 입력 | `$this->request->getPost()` / `getGet()` |
| SQL 문자열 직접 조합 | SQL Injection | Query Builder / 바인딩 |
| 뷰에서 `echo $변수` | XSS | `echo esc($변수)` (HTML은 `esc($v, 'html')`) |
| `md5()`/`sha1()`로 비밀번호 저장 | 취약한 해시 | `password_hash()` |
| 시크릿·API키 하드코딩 | 노출 위험 | `.env` + `env('KEY')` |
| CSRF 토큰 없이 POST 처리 | CSRF 공격 | `csrf_field()` (예외 라우트는 `Config/Filters.php`에 한정) |
| `$_FILES` 직접 처리 | 악성 파일 업로드 | `FileUploader`/`ImageUploader` 경유(확장자·MIME 검증) |

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
- 변경 빈도 낮은 조회(설정·메뉴·배너·팝업)는 파일 캐시 활용(위 캐싱 전략) — 쓰기 시 모델 콜백으로 무효화.
- 무거운 작업(대량 집계, 메일 발송, AI 추론 등)은 요청 사이클에서 처리하지 말고 배치 커맨드/`ai_jobs` 큐로 위임.

## 엑셀 (PhpSpreadsheet)

엑셀 내보내기·읽기는 의존성으로 포함된 **PhpSpreadsheet**(`phpoffice/phpspreadsheet`)를 사용합니다.

```php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 내보내기
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray($rows, null, 'A1');

$response = service('response');
$response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
$response->setHeader('Content-Disposition', 'attachment; filename="export.xlsx"');
ob_start();
(new Xlsx($spreadsheet))->save('php://output');
return $response->setBody(ob_get_clean());

// 읽기
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
$rows = $spreadsheet->getActiveSheet()->toArray();
```

- 대용량(1만 행 이상)은 청크 단위 처리(`ChunkReadFilter`) 적용.
- 업로드 파일은 `public/` 외부(`writable/uploads/`)에 저장 후 처리하고, 완료 즉시 임시 파일 삭제.

## 개발 품질 게이트 (필수)

커밋·PR 전에 아래를 통과시킵니다. 한 번에 `composer check`(= cs + analyse + test).

### 1. 코드 스타일 — PHP-CS-Fixer

```bash
composer cs       # 검사 (변경 없음)
composer cs-fix   # 자동 수정
```

- 설정: `.php-cs-fixer.dist.php` — **PSR-12** 기반 + `declare(strict_types=1)` 강제 + import 정렬·short array 등.
- 대상: `app/`, `tests/`(Views 제외). CI에서 `composer cs`가 실패하면 머지 불가.
- **모든 PHP 파일 첫 줄에 `declare(strict_types=1);`** — cs-fixer가 자동 삽입/검사.

### 2. 정적 분석 — PHPStan

```bash
composer analyse
```

- 레벨 **5**(`phpstan.neon`), CI4 확장(`codeigniter/phpstan-codeigniter`) 사용. 대상은 `app/` 코드 디렉토리(Views 제외).
- 기존 억제는 `phpstan-baseline.neon`. 새 코드는 `@phpstan-ignore`로 덮지 말고 원인 수정.
- 새 클래스·메서드에는 제네릭 타입(`array<string, mixed>` 등) 명시.
- 점진적 상향 목표: 5 → 6 → 8 (baseline으로 단계적 적용).

### 3. 자동 현대화 — Rector

```bash
composer rector       # 미리보기(dry-run)
composer rector-fix   # 적용
```

- 설정: `rector.php` — PHP 8.5 셋 + CODE_QUALITY / DEAD_CODE / TYPE_DECLARATION / EARLY_RETURN.
- 대량 변경이 나올 수 있으므로 **반드시 PR 단위로 검토**하고 cs-fix·analyse·test로 재검증.

### 4. 테스트 — PHPUnit

```bash
composer test
```

- 단위 테스트는 `tests/unit/`(`CIUnitTestCase` + `DatabaseTestTrait`, `$DBGroup = 'tests'`, `$migrate = false`).
- 테스트는 **미리 마이그레이션된 `tests` DB 그룹**을 가정한다(스스로 마이그레이션하지 않음).
- 마이그레이션은 **MySQL 전용 DDL**(`ALTER TABLE ... ADD UNIQUE KEY` 등)을 사용하므로 SQLite로는 실행 불가 — 테스트 DB도 **MySQL**이어야 한다.
- 마이그레이션이 prefix 없는 raw DDL(`ALTER TABLE users ...`)을 쓰므로 **DBPrefix는 빈 값**이어야 한다(프레임워크 기본 tests 그룹의 `db_`를 쓰면 안 됨).
- 로컬 테스트 준비 예시(default·tests 그룹을 같은 MySQL DB로):

```bash
# .env (default·tests 그룹 모두 동일 MySQL, prefix 없음)
#   database.default.DBDriver = MySQLi
#   database.default.database = aicopia_test
#   database.default.DBPrefix =
#   database.tests.DBDriver   = MySQLi
#   database.tests.database   = aicopia_test
#   database.tests.DBPrefix   =
php spark migrate --all      # default 그룹에 테이블 생성
composer test                # tests 그룹이 같은 DB를 읽음
```

- 운영 DB는 절대 사용 금지. CI는 `.github/workflows/ci.yml`의 `test` 잡이 동일 흐름을 MySQL 서비스로 자동 수행.
- 새 기능(특히 Service/Model 로직)은 테스트를 함께 작성.

## CI / CD

- **GitHub Actions**(`.github/workflows/ci.yml`)가 `dev`·`main` 대상 PR·push마다 실행:
  - `static` 잡 — `composer cs` + `composer analyse` + `composer audit`(의존성 취약점).
  - `test` 잡 — MySQL 서비스 프로비저닝 → `tests` DB 마이그레이션 → `composer test`.
- 이 저장소는 표준 CI4 스켈레톤(`app/Config/App.php`·`Constants.php`·`system/`·`public/index.php`·`spark`)을 **gitignore** 하고 커스텀 Config만 추적한다. CI는 `vendor/codeigniter4/framework`에서 누락 스켈레톤을 복원(`cp -rn` + `system` 심링크)한 뒤 검사를 돌린다. 로컬도 동일 방식으로 복원 가능.
- 권장: GitHub 브랜치 보호 규칙으로 `main`·`dev` 직접 push 차단 + CI 통과를 머지 조건으로 설정.

### 배포 (CD) — `.github/workflows/deploy.yml`

- 트리거: `main`에 머지(push)되면 배포 잡이 큐에 들어가고, **`production` 환경의 Required reviewers 승인 후** SSH로 운영 서버에 배포(머지 후 수동 승인). `workflow_dispatch`로 수동 재배포도 가능.
- 서버 배포 순서: `git reset --hard origin/main` → `composer install --no-dev` → `php spark cache:clear`.
  - `.env`와 gitignore된 스켈레톤은 untracked라 `git reset --hard`에도 보존된다.
- **DB 마이그레이션은 수동**: 일반 배포에서는 실행하지 않는다. 마이그레이션이 필요하면 Actions 탭에서 `workflow_dispatch`로 **`run_migration = true`**를 선택해 배포를 실행하면 그때만 `php spark migrate --all`이 돈다. (서버에 직접 SSH로 `php spark migrate`를 돌려도 됨.)
- **필수 GitHub Secrets**: `SSH_HOST`, `SSH_USER`, `SSH_KEY`(개인키), `DEPLOY_PATH`(서버의 git 클론 경로). 선택: `SSH_PORT`(기본 22), Variable `PRODUCTION_URL`.
- **사전 준비(1회)**: ① 운영 서버에 이 저장소를 git clone하고 `.env`·스켈레톤·`writable/` 권한을 세팅, ② GitHub Settings → Environments에서 `production` 환경 생성 후 **Required reviewers** 지정(승인 게이트), ③ 배포용 SSH 키를 서버 `authorized_keys`에 등록하고 개인키를 `SSH_KEY` Secret에 저장.
- 마이그레이션은 되돌리기 어렵다 — `run_migration` 실행 전 `deploy.yml`의 mysqldump 백업 단계(주석) 활성화를 권장.
