# AICopia — 프로젝트 분석 문서

> 최초 작성: 2026-06-11 / 최종 현행화: 2026-06-30
> 분석 대상: 저장소 루트 전체

---

## 1. 프로젝트 개요

**AICopia**는 CodeIgniter 4 기반의 1인 웹에이전시용 보일러플레이트로, 쇼핑몰 기능이 통합된 풀스택 CMS입니다.
"코어는 재사용하고 껍데기만 교체"하는 방식으로 단순 홈페이지는 3~5일, 중형 쇼핑몰은 1~2주 납품을 목표로 설계됐습니다.

저장소 루트 자체가 하나의 CI4 프로젝트입니다 (`app/`, `public/`이 루트 바로 아래).
별도 하위 프로젝트 폴더(`shop/` 등)는 존재하지 않습니다.

---

## 2. 기술 스택

| 영역 | 기술 |
|------|------|
| 백엔드 프레임워크 | CodeIgniter 4 (PHP 8.1+) |
| 프론트엔드 | Bootstrap 5 + Bootstrap Icons |
| 에디터 | TinyMCE 6 (자체 호스팅, npm — API 키 불필요) |
| 데이터베이스 | MySQL 8.0 / MariaDB 10.6+ |
| 인증 | CI4 Session 기반 (role: admin / member) |
| 캐시 | CI4 File Cache (설정, 메뉴, 배너, 팝업) |
| 스케줄러 | CI4 Scheduler — `Config/Tasks.php` (`* * * * * php spark tasks:run`) |
| 테마 시스템 | 폴더 기반 멀티테마 (`app/Views/themes/{테마명}/`) |
| 엑셀 | PhpSpreadsheet (`phpoffice/phpspreadsheet`) |
| 정적 분석 | PHPStan 레벨 6 + CI4 확장 (`codeigniter/phpstan-codeigniter`) |
| 코드 스타일 | PHP-CS-Fixer (PSR-12, strict_types 강제) |
| 현대화 | Rector (PHP 8.1 셋) |

---

## 3. 디렉토리 구조

```
AICopia/
├── app/
│   ├── Commands/
│   │   ├── ExpireOrders.php          # php spark orders:expire
│   │   ├── IssueBirthdayCoupons.php  # php spark coupons:birthday
│   │   ├── PurgeAccessLogs.php       # php spark stats:purge-logs
│   │   ├── UpgradeGrades.php         # php spark grades:upgrade
│   │   └── WorkAiJobs.php            # php spark ai:work
│   ├── Config/
│   │   ├── Routes.php
│   │   ├── Filters.php               # auth:member / auth:admin / StatsFilter
│   │   ├── Services.php              # ThemeView 기본 렌더러 등록
│   │   ├── Tasks.php                 # 스케줄러 잡 등록 (settings 테이블 참조)
│   │   ├── OAuth.php
│   │   ├── PG.php
│   │   └── Security.php
│   ├── Controllers/
│   │   ├── BaseController.php        # 설정·메뉴·배너·팝업·카테고리 전역 주입
│   │   ├── Front/                    # 프론트 컨트롤러 (13개)
│   │   │   ├── AuthController.php
│   │   │   ├── BoardController.php
│   │   │   ├── CartController.php
│   │   │   ├── CouponController.php
│   │   │   ├── HomeController.php
│   │   │   ├── MyPageController.php
│   │   │   ├── OrderController.php
│   │   │   ├── PageController.php
│   │   │   ├── PaymentController.php
│   │   │   ├── PromotionController.php
│   │   │   ├── ShopController.php
│   │   │   └── SocialAuthController.php
│   │   └── Admin/                    # 관리자 컨트롤러 (26개)
│   │       ├── BannerController.php
│   │       ├── BoardManagerController.php
│   │       ├── CouponController.php
│   │       ├── DashboardController.php
│   │       ├── GradeController.php
│   │       ├── InquiryController.php
│   │       ├── InventoryController.php
│   │       ├── MediaController.php
│   │       ├── MenuController.php
│   │       ├── NotificationController.php
│   │       ├── OrderController.php
│   │       ├── PageManagerController.php
│   │       ├── PointController.php
│   │       ├── PopupController.php
│   │       ├── PostController.php
│   │       ├── ProductController.php
│   │       ├── PromotionController.php
│   │       ├── QnaController.php
│   │       ├── ReviewController.php
│   │       ├── SalesController.php
│   │       ├── ScheduleController.php
│   │       ├── SettingController.php
│   │       ├── StatsController.php
│   │       ├── SupplierController.php
│   │       ├── UserController.php
│   │       └── WelcomeController.php
│   ├── Filters/
│   │   ├── AuthFilter.php            # 세션 기반 인증/권한 필터
│   │   └── StatsFilter.php           # 접속 로그 기록 필터
│   ├── Libraries/
│   │   ├── AiProvider/               # AI 운영 레이어
│   │   │   ├── AiProviderInterface.php
│   │   │   ├── GroqProvider.php
│   │   │   ├── ClaudeProvider.php
│   │   │   ├── OpenRouterProvider.php
│   │   │   ├── AiCache.php
│   │   │   ├── AiJobRunner.php
│   │   │   ├── AiPrompts.php
│   │   │   ├── InquiryClassifyHandler.php
│   │   │   ├── InquiryParsing.php
│   │   │   ├── InquiryTaxonomy.php
│   │   │   ├── ReviewSummaryHandler.php
│   │   │   ├── ReviewSummaryParsing.php
│   │   │   └── SearchExpandParsing.php
│   │   ├── AiCategoryAdvisor.php
│   │   ├── CouponService.php
│   │   ├── FileUploader.php
│   │   ├── GradeService.php
│   │   ├── ImageUploader.php
│   │   ├── Mailer.php
│   │   ├── MediaUploader.php
│   │   ├── NaverShoppingProvider.php
│   │   ├── OAuth/                    # Google / Kakao / Naver
│   │   │   ├── AbstractOAuthProvider.php
│   │   │   ├── GoogleProvider.php
│   │   │   ├── KakaoProvider.php
│   │   │   ├── NaverProvider.php
│   │   │   └── OAuthFactory.php
│   │   ├── OrderAnomalyService.php
│   │   ├── PG/                       # PG 어댑터 7종
│   │   │   ├── PGInterface.php
│   │   │   ├── PGFactory.php
│   │   │   ├── BankTransferAdapter.php
│   │   │   ├── TossPaymentsAdapter.php
│   │   │   ├── InicisAdapter.php
│   │   │   ├── NicePayAdapter.php
│   │   │   ├── KakaoPayAdapter.php
│   │   │   ├── NaverPayAdapter.php
│   │   │   └── PaycoAdapter.php
│   │   ├── RecommendationService.php
│   │   ├── RestockSuggestionService.php
│   │   ├── SemanticSearchService.php
│   │   ├── SeoHelper.php
│   │   └── ThemeView.php
│   ├── Models/                       # 31개 도메인 모델
│   ├── Database/Migrations/          # 마이그레이션 58개
│   └── Views/
│       ├── admin/
│       ├── auth/
│       ├── board/
│       ├── layouts/admin.php
│       ├── pages/
│       ├── shop/
│       └── themes/
│           └── default/
│               ├── components/       # navbar, footer, banner_slot, popups 등
│               └── layouts/main.php
├── public/
│   ├── themes/default/
│   ├── uploads/
│   └── tinymce/                      # TinyMCE 자체 호스팅 (gitignore, npm으로 설치)
├── docs/
│   ├── analysis.md                   # 이 문서
│   ├── roadmap.md
│   ├── env.example
│   └── create_dev_account.sql
├── themes/                           # 설치용 테마 ZIP (dark, spring, violet)
├── SETUP.md
├── package.json                      # TinyMCE npm 의존성
├── phpstan.neon                      # PHPStan 레벨 6
├── phpstan-baseline.neon             # baseline 4건 (외부 라이브러리 한정)
├── .php-cs-fixer.dist.php
└── rector.php
```

---

## 4. DB 스키마

### 4-1. 기본 CMS 테이블

| 테이블 | 설명 |
|--------|------|
| `users` | 회원 (role: admin/member, point_balance, 소셜 로그인 필드, 등급 fk) |
| `settings` | 사이트 전역 설정 key-value (type: text/password/select/carriers) |
| `pages` | 동적 페이지 (슬러그 기반) |
| `menus` | 네비게이션 메뉴 (2단계 드롭다운) |
| `media` | 미디어 라이브러리 |
| `inquiries` | 문의 수신함 (AI triage 컬럼 포함) |
| `boards` | 게시판 설정 |
| `posts` | 게시글 (소프트 삭제) |
| `post_files` | 첨부파일 |
| `post_comments` | 댓글 (소프트 삭제) |
| `banners` | 배너 (위치·기간·우선순위) |
| `popups` | 팝업 (위치·좌표·기간) |
| `popup_pages` | 팝업-메뉴 연결 피벗 |
| `access_logs` | 방문자 접속 로그 |
| `access_log_summaries` | 방문자 일별 집계 |
| `ai_jobs` | 비동기 AI 작업 큐 |

### 4-2. 쇼핑 테이블

| 테이블 | 설명 |
|--------|------|
| `categories` | 상품 카테고리 (parent_id 계층 구조) |
| `product_categories` | 상품-카테고리 피벗 테이블 |
| `products` | 상품 (status: on_sale/sold_out/hidden, is_featured, 배송 타입, supplier_fk) |
| `product_images` | 상품 이미지 (media 연동, is_primary) |
| `product_options` | 상품 옵션 그룹 |
| `product_skus` | SKU (옵션 조합별 재고·가격) |
| `product_reviews` | 리뷰 (is_hidden, is_negative, AI 요약) |
| `product_qnas` | 상품 Q&A |
| `cart_items` | 장바구니 (user_id 또는 session_id, 비회원 지원) |
| `shipping_addresses` | 회원 배송지 주소록 (is_default) |
| `wishlists` | 회원별 찜 상품 |
| `orders` | 주문 헤더 (배송지 스냅샷, 쿠폰·포인트 적용, delivered_at, 반품/교환 필드) |
| `order_items` | 주문 상품 스냅샷 |
| `order_memos` | 관리자 내부 메모 |
| `exchange_items` | 교환 라인 아이템 |
| `payments` | 결제 (pg_tid UNIQUE, PG 원응답 JSON) |
| `order_status_logs` | 상태 변경 감사 (admin/member/system) |
| `stock_logs` | 재고 조정 감사 (adjust/order/cancel/in/out) |
| `restock_alerts` | 재입고 알림 요청 |
| `coupons` | 쿠폰 (type: fixed/percent, 수량·기간·최소금액 조건) |
| `user_coupons` | 쿠폰 발급·사용 이력 |
| `point_logs` | 포인트 (earn/use/refund/cancel/admin) |
| `grades` | 회원 등급 정책 (티어별 조건, 쿠폰 발급 설정) |
| `promotions` | 프로모션 캠페인 |
| `suppliers` | 공급사/사업자 정보 |

---

## 5. 주요 모듈 분석

### 5-1. 인증 시스템

- CI4 세션 기반 로그인/회원가입
- 이메일 인증 (토큰 기반, 재발송 지원)
- 비밀번호: `password_hash()` / `password_verify()`
- 소셜 로그인: Google, Kakao, Naver OAuth2 (`AbstractOAuthProvider` 상속 구조)
- `AuthFilter`가 `auth:member` / `auth:admin` 필터로 라우트 보호

### 5-2. 결제(PG) 시스템

**PGInterface** 인터페이스를 구현하는 어댑터 패턴:

| PG | 어댑터 | 설정 환경변수 |
|----|--------|---------------|
| 토스페이먼츠 | `TossPaymentsAdapter` | `TOSS_CLIENT_KEY`, `TOSS_SECRET_KEY` |
| KG이니시스 | `InicisAdapter` | `INICIS_MERCHANT_ID`, `INICIS_SIGN_KEY` |
| 나이스페이 | `NicePayAdapter` | `NICEPAY_CLIENT_ID`, `NICEPAY_SECRET_KEY` |
| 카카오페이 | `KakaoPayAdapter` | `KAKAOPAY_SECRET_KEY`, `KAKAOPAY_CID` |
| 네이버페이 | `NaverPayAdapter` | `NAVERPAY_CLIENT_ID`, `NAVERPAY_CLIENT_SECRET` |
| PAYCO | `PaycoAdapter` | `PAYCO_SELLER_KEY`, `PAYCO_SECRET_KEY` |
| 무통장입금 | `BankTransferAdapter` | DB 설정 (bank_name/account/holder) |

**결제 흐름:**
```
장바구니
  → POST /order/create  (status: pending 생성, 쿠폰·포인트 확정)
  → PG 결제창 (프론트)
  → GET|POST /payment/callback/{pg}
  → 금액 검증 → SELECT FOR UPDATE → stock 차감 → paid 전환
  → GET /order/complete/{orderNumber}
```

**이중 결제 방지:** `payments.pg_tid` UNIQUE 제약으로 콜백 중복 차단

### 5-3. 주문 상태 머신

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

- `delivered_at`은 `delivered` 전환 시 설정
- 반품/교환 기간은 `delivered_at` 기준 7일 (null은 레거시 주문으로 항상 허용)
- 상태 전환은 `OrderModel::updateStatus()`에서 단방향 강제

### 5-4. 쿠폰 시스템

`CouponService`가 검증·할인 계산을 담당:
- **코드 직접 입력** vs **발급된 user_coupon_id** 두 경로 지원
- 검증 조건: 활성 여부 / 유효 기간 / 수량 소진 / 최소 주문 금액 / 1인당 사용 제한
- 할인 타입: `fixed`(정액) / `percent`(정률, max_discount_amount 상한 적용)
- 주문 취소·만료 시 쿠폰 자동 복구
- 생일 쿠폰 자동 발급: `coupons:birthday` 커맨드

### 5-5. 포인트 시스템

- 주문 생성 시 `FOR UPDATE` 잠금 후 차감 (잔액 부족 시 트랜잭션 롤백)
- 포인트 적립은 `delivered` 전환 시 확정 (`point_earned_amount`)
- 취소·만료·환불 시 사용 포인트 환급, 이미 적립된 포인트 회수
- `point_logs` 타입: earn / use / refund / cancel / admin

### 5-6. 회원 등급 시스템

`GradeService`가 등급 티어·승급 관리:
- `grades:upgrade` 커맨드로 전체 회원 등급 재계산
- 등급 승급 시 쿠폰 자동 발급 (설정 기반)
- 등급 정책은 `grades` 테이블 + `settings` 테이블의 `grade_*` 키로 관리

### 5-7. 재고 관리

- 결제 확정(paid) 시점에 `SELECT FOR UPDATE` 후 차감
- 재고 0이면 자동 `sold_out` 전환
- 취소 시 재고 복구 + `sold_out → on_sale` 자동 전환
- `stock_logs` 테이블에 모든 변경 이력 기록
- 재입고 알림 요청: `restock_alerts` — 재입고 시 회원 이메일 발송

### 5-8. AI 운영 레이어

`AiProviderInterface`(`app/Libraries/AiProvider/`)를 통해 동작:

| 메서드 | 기능 |
|--------|------|
| `suggestCategories` | AI 카테고리 추천 |
| `generateDescription` | 상품 설명 자동 생성 |
| `generateQnaAnswer` | Q&A 자동 답변 |
| `summarizeReviews` | 리뷰 요약 |
| `classifyInquiry` | 문의 자동 분류 (triage) |
| `generateInquiryReply` | 문의 답변 자동 생성 |
| `generateSalesReport` | 매출 리포트 생성 |
| `generateRestockMessage` | 재입고 알림 메시지 생성 |
| `expandSearchQuery` | 시맨틱 검색 쿼리 확장 |

**Provider 선택:** `settings['ai_provider']` (없으면 `env('AI_PROVIDER', 'groq')`)

| Provider 키 | 클래스 | 비고 |
|-------------|--------|------|
| `groq` | `GroqProvider` | 기본값 |
| `claude` | `ClaudeProvider` | Anthropic API |
| `openrouter` | `OpenRouterProvider` | GroqProvider 상속, 모델 선택 가능 |

**비동기 잡:** `ai_jobs` 테이블에 큐잉 → `php spark ai:work`(`AiJobRunner`)가 처리
- 등록 핸들러: `review_summary`(`ReviewSummaryHandler`), `inquiry_classify`(`InquiryClassifyHandler`)
- `AiCache`가 결과 메모이즈, `AiPrompts`가 프롬프트 템플릿 관리

**상위 서비스(`app/Libraries/`):**
- `AiCategoryAdvisor` — AI 카테고리 추천
- `RecommendationService` — 추천 상품
- `SemanticSearchService` — 시맨틱 검색
- `RestockSuggestionService` — 재입고 제안
- `OrderAnomalyService` — 주문 이상 감지
- `NaverShoppingProvider` — 네이버 쇼핑 API

**이미지 AI:**
- Clipdrop Remove Background API: 상품 이미지 배경 자동 제거
- 엔드포인트: `POST /admin/products/image/{id}/remove-bg`
- API 키: `settings['clipdrop_api_key']` (관리자 설정에서 등록)

### 5-9. 테마 시스템

`ThemeView` 렌더러가 파일 해석 순서를 제어:
```
활성 테마 폴더 → default 테마 폴더 → 원본 경로
```
- ZIP 업로드로 테마 설치 (Zip-slip 방지: `..` / `/` 시작 경로 차단, 확장자 화이트리스트)
- 콘텐츠 뷰(`board/`, `auth/`, `admin/`)는 테마와 완전 분리
- 샘플 테마: `default` / `dark` / `violet` / `spring` (ZIP 형태로 `themes/` 에 제공)

### 5-10. 성능 최적화

- 배너·팝업·사이트 설정·메뉴: CI4 File Cache
- 캐시 무효화: 모델 `afterInsert/Update/Delete` 콜백으로 즉시 반영
- DB 인덱스:
  - `posts(board_id, is_notice, id)`, `posts(deleted_at)`
  - `banners(position, is_active)`, `popups(is_active, show_scope)`
  - `inquiries(is_read)`
  - `orders(status)`, `orders(user_id, status)`, `orders(created_at)`
  - `stock_logs(product_id, created_at)`, `access_logs(created_at)`

### 5-11. 스케줄러

`Config/Tasks.php`가 `settings` 테이블에서 활성화된 잡을 읽어 스케줄러에 등록.
잡 활성화·주기 설정은 `/admin/schedule`에서 관리.

| 설정 키 | 커맨드 | 기본 주기 |
|---------|--------|----------|
| `schedule_orders_expire` | `orders:expire` | 5분 |
| `schedule_grades_upgrade` | `grades:upgrade` | 1일 |
| `schedule_coupons_birthday` | `coupons:birthday` | 1일 |
| `schedule_stats_purge_logs` | `stats:purge-logs` | 1주 |
| `schedule_ai_work` | `ai:work` | 5분 |

---

## 6. 라우팅 구조

### 프론트 라우트

| 경로 | 컨트롤러 | 설명 |
|------|----------|------|
| `GET /` | `Front\ShopController::home` | 홈 |
| `GET /welcome` | `Front\ShopController::welcome` | 웰컴 페이지 |
| `GET|POST /auth/*` | `Front\AuthController` | 로그인·회원가입·이메일인증·프로필 |
| `GET /auth/social/{provider}` | `Front\SocialAuthController` | OAuth2 소셜 로그인 |
| `GET|POST /board/{slug}/*` | `Front\BoardController` | 게시판 CRUD·댓글·파일다운로드 |
| `POST /inquiry/submit` | `Front\PageController` | 문의 폼 제출 |
| `GET /shop` | `Front\ShopController::index` | 상품 목록 |
| `GET /shop/{slug}` | `Front\ShopController::detail` | 상품 상세 |
| `POST /cart/add` | `Front\CartController::add` | 장바구니 추가 (비회원 허용) |
| `GET|POST /cart/*` | `Front\CartController` | 장바구니 관리 (로그인 필요) |
| `GET|POST /order/*` | `Front\OrderController` | 주문서·결제·취소·반품·교환 |
| `GET|POST /payment/callback/{pg}` | `Front\PaymentController` | PG 콜백 |
| `GET|POST /mypage/*` | `Front\MyPageController` | 주문이력·배송지·쿠폰·포인트 |
| `GET|POST /coupon/*` | `Front\CouponController` | 쿠폰 사용 |
| `GET /promotion/{slug}` | `Front\PromotionController` | 프로모션 페이지 |
| `GET /{slug}` | `Front\PageController::show` | 동적 페이지 (최하위) |

### 관리자 라우트 (`/admin`, auth:admin 필터)

| 메뉴 | 경로 | 컨트롤러 |
|------|------|----------|
| 대시보드 | `/admin/dashboard` | `Admin\DashboardController` |
| 상품 관리 | `/admin/products/*` | `Admin\ProductController` |
| 재고 관리 | `/admin/inventory/*` | `Admin\InventoryController` |
| 주문 관리 | `/admin/orders/*` | `Admin\OrderController` |
| 매출 관리 | `/admin/sales` | `Admin\SalesController` |
| 통계 | `/admin/stats/*` | `Admin\StatsController` |
| 쿠폰 관리 | `/admin/coupons/*` | `Admin\CouponController` |
| 포인트 관리 | `/admin/points/*` | `Admin\PointController` |
| 등급 관리 | `/admin/grades/*` | `Admin\GradeController` |
| 프로모션 | `/admin/promotions/*` | `Admin\PromotionController` |
| 공급사 관리 | `/admin/suppliers/*` | `Admin\SupplierController` |
| 리뷰 관리 | `/admin/reviews/*` | `Admin\ReviewController` |
| Q&A 관리 | `/admin/qnas/*` | `Admin\QnaController` |
| 문의 수신함 | `/admin/inquiries/*` | `Admin\InquiryController` |
| 알림 | `/admin/notifications/counts` | `Admin\NotificationController` |
| 배너 관리 | `/admin/banners/*` | `Admin\BannerController` |
| 팝업 관리 | `/admin/popups/*` | `Admin\PopupController` |
| 게시판 관리 | `/admin/boards/*` | `Admin\BoardManagerController` |
| 게시글 관리 | `/admin/posts/*` | `Admin\PostController` |
| 페이지 관리 | `/admin/pages/*` | `Admin\PageManagerController` |
| 회원 관리 | `/admin/users/*` | `Admin\UserController` |
| 메뉴 관리 | `/admin/menus/*` | `Admin\MenuController` |
| 미디어 | `/admin/media/*` | `Admin\MediaController` |
| 스케줄 관리 | `/admin/schedule/*` | `Admin\ScheduleController` |
| 사이트 설정 | `/admin/settings/*` | `Admin\SettingController` |
| 웰컴 설정 | `/admin/welcome` | `Admin\WelcomeController` |

---

## 7. 컨트롤러별 주요 로직

### `OrderModel` 핵심 메서드

| 메서드 | 역할 |
|--------|------|
| `createPending()` | 주문 생성 + 쿠폰 확정 + 포인트 차감 (트랜잭션) |
| `confirmPaid()` | PG 콜백 후 재고 차감 + paid 전환 + 장바구니 삭제 |
| `confirmBankTransfer()` | 무통장 입금 확인 후 재고 차감 + paid 전환 |
| `cancelOrder()` | 회원 취소 + 재고·쿠폰·포인트 복구 |
| `adminCancel()` | 관리자 강제 취소 (preparing 까지 가능) |
| `expirePending()` | 미결제 30분 초과 주문 만료 처리 |
| `updateStatus()` | 단방향 상태 전환 + delivered 시 포인트 적립 |
| `markRefunded()` | 환불 완료 + 쿠폰 복구 + 포인트 환급·적립취소 |
| `calculateShippingFee()` | 조건부 무료 / 고정 배송비 계산 |

---

## 8. 보안 사항

| 항목 | 구현 |
|------|------|
| 파일 업로드 | 확장자 화이트리스트 (php, exe 등 차단), 저장명 랜덤 변환 |
| 관리자 라우트 | `auth:admin` 필터 전체 보호 |
| 비밀번호 | `password_hash()` / `password_verify()` |
| CSRF | CI4 기본 CSRF 필터 (PG 콜백·api/*·미디어 업로드·게시판 이미지 업로드는 예외) |
| 이중 결제 | `payments.pg_tid` UNIQUE 제약 |
| 재고 경쟁 | `SELECT FOR UPDATE` 비관적 잠금 |
| Zip-slip | 테마 ZIP 업로드 시 `..` / `/` 시작 경로 차단 |
| SQL Injection | Query Builder + 바인딩만 사용 (raw SQL 금지) |
| XSS | 뷰 출력 전부 `esc()` 처리 |
| 접속 로그 | `StatsFilter`가 모든 요청에서 `access_logs` 기록 |

---

## 9. 개발 품질 게이트

커밋·PR 전 `composer check` (cs + analyse + test) 통과 필수.

| 도구 | 명령 | 기준 |
|------|------|------|
| PHP-CS-Fixer | `composer cs` / `composer cs-fix` | PSR-12 + strict_types 강제 |
| PHPStan | `composer analyse` | 레벨 6, baseline 4건 (외부 라이브러리) |
| Rector | `composer rector` / `composer rector-fix` | PHP 8.1 셋 |
| PHPUnit | `composer test` | MySQL 전용, DBPrefix 없음 |

CI: GitHub Actions (`.github/workflows/ci.yml`) — `dev`·`main` PR/push마다 `static` + `test` 잡 자동 실행

---

## 10. 개발 로드맵 현황

| # | 기능 | 상태 |
|---|------|------|
| 1 | 배너 관리 | ✅ 완료 |
| 2 | 팝업 관리 | ✅ 완료 |
| 3 | 성능 최적화 (캐싱 + DB 인덱스) | ✅ 완료 |
| 4-1 | 상품 목록·상세 (옵션·SKU·리뷰·Q&A·찜) | ✅ 완료 |
| 4-2 | 장바구니 (비회원 세션 장바구니 포함) | ✅ 완료 |
| 4-3 | 주문·결제 (PG 7종) | ✅ 완료 |
| 4-4 | 무통장입금 | ✅ 완료 |
| 4-5 | 재고 관리 (관리자, 재입고 알림) | ✅ 완료 |
| 4-6 | 마이페이지 (주문 이력·취소·반품·교환) | ✅ 완료 |
| 4-7 | 주문 관리 (관리자) | ✅ 완료 |
| 4-8 | 매출 관리 (관리자) | ✅ 완료 |
| 4-9 | 쿠폰·포인트·등급 시스템 | ✅ 완료 |
| 4-10 | 배송지 주소록 | ✅ 완료 |
| 4-11 | AI 운영 레이어 (카테고리·리뷰·문의·검색) | ✅ 완료 |
| 4-12 | 프로모션·공급사·통계·스케줄 관리 | ✅ 완료 |
| 4-13 | 이미지 AI (Clipdrop 배경 제거) | ✅ 완료 |
| 5 | 라이센스 관리 | 📋 예정 |

---

## 11. 알려진 이슈 및 TODO

| 항목 | 내용 |
|------|------|
| PG 재고 부족 자동 취소 | `confirmPaid()` 재고 차감 실패 시 PG 승인 취소 API 호출 미구현 — 결제는 완료됐으나 주문이 롤백되는 케이스 |
| PG 서버 웹훅 | 현재 브라우저 리다이렉트 콜백만 지원, 서버-투-서버 웹훅 별도 구현 필요 |
| 게시판 검색 | `LIKE '%키워드%'` 방식 — 대용량 시 FULLTEXT 인덱스 전환 검토 필요 |
| 매출 집계 | 환불 포함 gross 매출 기준 — 순매출(net) 지표 추가 필요 |
| 라이센스 관리 | 미구현 (5번 로드맵) — `LicenseService` + `LicenseFilter` 계획됨 |

---

## 12. 설치 및 실행

`SETUP.md` 참조. 핵심 순서:

```bash
composer install
# CI4 부트스트랩 파일 복원 (spark, public/index.php, Config 누락 파일)
# .env 설정 (DB, AI 키, PG 키, OAuth 키, SMTP)
php spark migrate       # 테이블 생성 + 기본 시드 (58개 마이그레이션)
npm install && cp -r node_modules/tinymce public/tinymce
php spark serve         # http://localhost:8080
```

**기본 관리자 계정:**
- 이메일: `admin@example.com`
- 비밀번호: `admin1234!`

**크론 등록 (운영 서버):**
```
* * * * * cd /path/to/AICopia && php spark tasks:run >> /dev/null 2>&1
```
