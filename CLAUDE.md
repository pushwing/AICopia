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
php spark serve --port 8303  # 개발 서버 실행 (http://localhost:8303)
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
# 표준 CI4 스켈레톤은 gitignore 되어 있어 vendor에서 복원한다(없는 항목만 복사 → 커스텀 Config 보존).
for src in vendor/codeigniter4/framework/app/Config/*; do
  dest="app/Config/$(basename "$src")"; [ -e "$dest" ] || cp -r "$src" "$dest"
done
[ -e system ] || ln -s vendor/codeigniter4/framework/system system
cp vendor/codeigniter4/framework/env .env   # 이후 편집: DB, CI_ENVIRONMENT, AI 키, PG 키, OAuth 키, SMTP
# app/Config/App.php: $appTimezone = 'Asia/Seoul' 로 설정 (아래 "타임존 규약" 참고)
php spark migrate              # 테이블 생성 + 기본 데이터 시드
```

기본 관리자: `admin@example.com` (`2024-01-01-000002_SeedBoardData`에서 시드). 비밀번호는 설치마다 랜덤 생성되어 `php spark migrate` 콘솔 출력에 1회만 표시되며, 최초 로그인 시 변경이 강제된다(`must_change_password` + `ForcePasswordChangeFilter`).

리눅스 업로드 권한: `chmod -R 755 public/uploads writable`

## Git 워크플로우

브랜치 모델·표준 흐름·규칙은 규칙 문서로 분리했습니다 → @.claude/rules/git-workflow.md

## 아키텍처

### 타임존 규약 (저장 UTC / 표시 KST)

**서버 자체 시계와 DB 저장값은 UTC, CI4 앱과 화면은 `Asia/Seoul`(KST, UTC+9).**

| 계층 | 타임존 | 비고 |
|------|--------|------|
| 서버 OS 시계(시스템 시각·크론·DB 서버) | **UTC** | 운영 서버는 UTC로 돌아간다 |
| CI4 앱 (`app/Config/App.php` `$appTimezone`) | **Asia/Seoul** | `$appTimezone = 'Asia/Seoul'` — PHP `date()`·`Time::now()`가 KST를 반환 |
| DB에 저장되는 모든 시각 | **UTC** | `created_at`·`updated_at`·`expires_at`·`delivered_at`·`starts_at` 등 전부 |
| 웹 화면 출력·사용자 입력 해석 | **Asia/Seoul** | 쿠폰 만료일, 주문 일시, 프로모션 기간, 통계 날짜 등 사용자가 보는 모든 시각 |

**규칙**

- **DB에 쓸 때는 UTC로 변환해서 쓴다.** `$appTimezone`이 `Asia/Seoul`이라 `date('Y-m-d H:i:s')`·`Time::now()`는 **KST**를 반환한다 — 그대로 저장하면 UTC 규약이 깨진다.

  ```php
  // ✅ 저장 — UTC로 변환해서 넣는다
  $nowUtc = \CodeIgniter\I18n\Time::now('UTC')->format('Y-m-d H:i:s');

  // ❌ 금지 — KST 값이 UTC 컬럼에 들어간다 (9시간 어긋남)
  $now = date('Y-m-d H:i:s');
  ```

- **사용자에게 보여줄 때는 UTC 저장값을 KST로 변환한다.** DB 값을 그대로 `date()`·`strtotime()`으로 찍으면 UTC가 KST인 척 노출되어 9시간 어긋난다.

  ```php
  // ✅ 표시 — UTC 저장값을 KST로 변환 (앱 타임존이 Asia/Seoul이므로 setTimezone 생략 가능)
  echo esc((new \CodeIgniter\I18n\Time($uc['expires_at'], 'UTC'))
      ->setTimezone('Asia/Seoul')->format('Y년 n월 j일'));

  // ❌ 금지 — UTC 값을 그대로 출력
  echo date('Y년 n월 j일', strtotime($uc['expires_at']));
  ```

- **관리자 폼 등 사용자 입력(`datetime-local`)은 KST 입력으로 간주**해 저장 전에 UTC로 변환하고, 폼에 다시 채울 때는 UTC → KST로 되돌린다.
- **날짜 경계가 있는 집계(일별 매출·통계·"오늘")는 KST 기준 하루**로 잡는다. UTC 기준으로 자르면 한국 시각 09:00을 경계로 하루가 갈린다 — KST 날짜 범위를 UTC 구간으로 환산해 `WHERE`에 넣는다.
- **비교·계산은 UTC끼리** 한다(만료 판정 `expires_at < now()` 등). KST로 변환한 값과 UTC 저장값을 섞어 비교하지 말 것. MySQL `NOW()`는 DB 서버(UTC)를 따르므로 UTC 컬럼과 비교해도 안전하지만, PHP `date()`는 KST라 그대로 쓰면 안 된다.

> ⚠️ 현재 코드 상당수(뷰의 `date()`/`strtotime()`, `CouponService`, `StatsFilter`, 시더·마이그레이션의 `date('Y-m-d H:i:s')`)는 앱 타임존(KST) 그대로 저장·출력해 변환 계층이 없다 — 즉 **기존 데이터에는 KST와 UTC가 섞여 있을 수 있다.** 시각을 다루는 코드를 새로 쓰거나 손댈 때 위 규칙에 맞게 정리한다.

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

일부 관리자 컨트롤러는 기본 CRUD 외에 다음 고급 기능을 포함한다:
- `Product` — 상품 복제·일괄 작업·엑셀 임포트/내보내기, 카테고리 관리(계층), 미분류 상품 정리, 이미지 배경 제거, 네이버 상품 검색/임포트, AI 보조(설명 생성·이미지 정보 추출·카테고리 추천).
- `Order` — 무통장입금 확인, 반품/교환 승인·거부, 주문 메모, **배송추적 일괄 업로드**(`tracking_upload`), **AI 이상주문 탐지**(`anomalies`, `OrderAnomalyService`).
- `Inventory` — **AI 재입고 제안**(`suggestions`). `Sales` — AI 매출 분석 리포트. `Setting` — 탭 그룹: general/mail/theme/oauth/**api**.

> 화면·URL·작업 단위 사용 흐름은 [docs/manual.md](docs/manual.md)(고객+관리자 통합 매뉴얼) 참조.

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

## 코딩 표준 & 보안

세부 지침은 규칙 문서로 분리했습니다:

- **코딩 표준·네이밍·레이어 책임·안티패턴·권장 스타일·도메인 예외·성능/쿼리** → @.claude/rules/code-style.md
- **보안(입력 검증·XSS·CSRF·시크릿·업로드)** → @.claude/rules/security.md

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

커밋·PR 전 통과 기준(cs · analyse · rector · test)과 테스트 DB 설정은 규칙 문서로 분리했습니다 → @.claude/rules/testing.md

## CI / CD

**검증 게이트 정책: 검증은 로컬에서 끝낸다.** `feature/* → dev` PR에는 CI를 걸지 않고(로컬 pre-push 훅이 실질 게이트), CI는 `dev → main` 배포 PR에서만 돈다. 세부 근거·pre-push 훅 동작·self-hosted 러너 등록 방법은 → @.claude/rules/testing.md (0·5번 섹션)

- **GitHub Actions**(`.github/workflows/ci.yml`)는 **`main` 대상 PR에서만** 실행(`on.pull_request.branches: [main]`) — `dev`로 가는 PR·어떤 브랜치로의 push에도 CI가 돌지 않는다:
  - `static` 잡 — `composer cs` + `composer analyse` + `composer audit`(의존성 취약점).
  - `test` 잡 — `docker run`으로 MySQL 컨테이너 기동(호스트 포트 13306) → `tests` DB 마이그레이션 → `composer test:parallel`.
- **self-hosted 러너**(`runs-on: [self-hosted, macOS, ARM64]`)에서 세 잡 모두 돈다 — GitHub 호스팅 러너(`ubuntu-latest`)가 아니라 이 저장소를 로컬 개발하는 Mac을 러너로 등록해 사용(계정 결제 문제로 호스팅 러너가 막혔던 게 계기). PHP/Composer는 러너 머신에 이미 설치된 로컬 개발 환경을 그대로 쓴다.
- 이 저장소는 표준 CI4 스켈레톤(`app/Config/App.php`·`Constants.php`·`system/`·`public/index.php`·`spark`)을 **gitignore** 하고 커스텀 Config만 추적한다. CI는 `vendor/codeigniter4/framework`에서 누락 스켈레톤을 복원(없는 항목만 복사 + `system` 심링크)한 뒤 검사를 돌린다. 로컬도 동일 방식으로 복원 가능.
- 권장: GitHub 브랜치 보호 규칙으로 `main`·`dev` 직접 push 차단 + `main` 대상 PR은 CI 통과를 머지 조건으로 설정(`dev` 대상 PR은 CI가 없으므로 코드 리뷰 승인만 조건으로).

### 배포 (CD) — `.github/workflows/deploy.yml`

- 트리거: `main`에 머지(push)되면 즉시 SSH로 운영 서버에 배포된다. `workflow_dispatch`로 수동 재배포도 가능.
  - `production` 환경(Environments)은 만들어져 있지만 **Required reviewers 승인 게이트는 설정돼 있지 않다**(2026-07-30 확인, `protection_rules: []`) — `dev → main` PR을 머지하는 순간 사람의 추가 승인 없이 바로 배포되므로, 머지 자체를 신중히 판단할 것. 승인 게이트가 필요해지면 GitHub Settings → Environments → `production` → Required reviewers에서 지정한다.
- 서버 배포 순서: `git reset --hard origin/main` → `composer install --no-dev` → **스켈레톤 복원(없는 항목만 복사)** → `php spark cache:clear`.
  - `.env`와 gitignore된 스켈레톤은 untracked라 `git reset --hard`에도 보존된다.
  - gitignore된 표준 CI4 스켈레톤(`app/Config` 표준 파일·`system`·`spark`·`public/index.php`)은 `composer install` 직후 vendor 프레임워크에서 복원한다(없는 항목만 복사 + `[ -e ]` 가드라 커스텀 Config·`.env`는 덮어쓰지 않음). 신규 서버·프레임워크 업데이트로 Config가 추가돼도 자동으로 채워진다.
- **DB 마이그레이션은 수동**: 일반 배포에서는 실행하지 않는다. 마이그레이션이 필요하면 Actions 탭에서 `workflow_dispatch`로 **`run_migration = true`**를 선택해 배포를 실행하면 그때만 `php spark migrate --all`이 돈다. (서버에 직접 SSH로 `php spark migrate`를 돌려도 됨.)
- **필수 GitHub Secrets**: `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`(개인키), `DEPLOY_PATH`(서버의 git 클론 경로). 선택: `SSH_PORT`(기본 22), Variable `PRODUCTION_URL`.
- **사전 준비(1회)**: ① 운영 서버에 이 저장소를 git clone하고 `.env`·스켈레톤·`writable/` 권한을 세팅, ② GitHub Settings → Environments에서 `production` 환경 생성(현재 승인 게이트 없이 사용 중 — 필요 시 Required reviewers 추가), ③ 배포용 SSH 키를 서버 `authorized_keys`에 등록하고 개인키를 `SSH_PRIVATE_KEY` Secret에 저장.
- 마이그레이션은 되돌리기 어렵다 — `run_migration` 실행 전 `deploy.yml`의 mysqldump 백업 단계(주석) 활성화를 권장.
