# CLAUDE.md

이 파일은 이 저장소에서 작업할 때 Claude Code(claude.ai/code)에 제공되는 가이드입니다.

## 저장소 개요

**AICopia**는 단일 CodeIgniter 4 기반 쇼핑몰 솔루션입니다. 저장소 루트가 곧 하나의 CI4 프로젝트이며(`app/`, `public/`가 루트 바로 아래), 기업형 사이트(페이지·게시판·문의)와 풀 쇼핑몰(상품·장바구니·주문·PG 결제)을 AI 운영 보조 레이어(AI 카테고리 추천, 리뷰 요약, 문의 자동분류/Triage, 시맨틱 검색, 재입고 제안 등) 위에 함께 제공합니다.

모든 `php spark` / `composer` / `git` 명령은 **저장소 루트에서** 실행합니다. `default/`, `shop/` 같은 하위 프로젝트는 없습니다.

## 명령어

```bash
php spark serve              # 개발 서버 실행 (http://localhost:8080)
php spark migrate            # 대기 중인 마이그레이션 전체 실행 (테이블 생성 + 시드)
php spark migrate:rollback   # 마지막 마이그레이션 배치 롤백
php spark db:seed <Seeder>   # 시더 실행 (예: ProductSeeder, PostSeeder)
./vendor/bin/phpunit         # 테스트 실행
vendor/bin/phpstan analyse   # 정적 분석 (설정: phpstan.neon)
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
cp env .env                    # 이후 편집: DB, CI_ENVIRONMENT, AI 키, PG 키, OAuth 키, SMTP
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

- PHP 8.1+ (타입 프로퍼티, match, 화살표 함수); PSR-12.
- 모델은 `CodeIgniter\Model`을 상속하고 `$allowedFields`를 명시.
- 뷰는 네이티브 대체 문법(`<?php if (): ?> … <?php endif; ?>`) 사용, 모든 출력에 `esc()`.
- 입력은 `$this->request->getPost()`로 받고, 처리 전 `$this->validate()`로 검증.
- 모든 POST/PUT/DELETE 폼에 `<?= csrf_field() ?>` 포함(위 CSRF 예외 라우트 제외).
- DB 접근은 Query Builder만 사용 — 문자열 연결 raw SQL 금지.
- 하드코딩 시크릿 금지 — `env()` / Config 클래스 사용. `.env`는 절대 스테이징하지 말 것.
