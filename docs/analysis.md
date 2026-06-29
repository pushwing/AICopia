# AICreo Shop — 프로젝트 분석 문서

> 작성일: 2026-06-11  
> 분석 대상: `/shop` 폴더 전체

---

## 1. 프로젝트 개요

**AICreo**는 CodeIgniter 4 기반의 1인 웹에이전시용 보일러플레이트로, 쇼핑몰 기능이 통합된 풀스택 CMS입니다.
"코어는 재사용하고 껍데기만 교체"하는 방식으로 단순 홈페이지는 3~5일, 중형 쇼핑몰은 1~2주 납품을 목표로 설계됐습니다.

---

## 2. 기술 스택

| 영역 | 기술 |
|------|------|
| 백엔드 프레임워크 | CodeIgniter 4 (PHP 8.x) |
| 프론트엔드 | Bootstrap 5 + Bootstrap Icons |
| 에디터 | TinyMCE 6 (CDN, `.env` API 키 관리) |
| 데이터베이스 | MySQL / MariaDB |
| 인증 | CI4 Session 기반 (role: admin / member) |
| 캐시 | CI4 File Cache (설정, 메뉴, 배너, 팝업) |
| 스케줄러 | CI4 Scheduler (crontab `* * * * *`) |
| 테마 시스템 | 폴더 기반 멀티테마 (`app/Views/themes/{테마명}/`) |

---

## 3. 디렉토리 구조

```
shop/
├── app/
│   ├── Commands/
│   │   └── ExpireOrders.php          # php spark orders:expire (미결제 자동 만료)
│   ├── Config/
│   │   ├── Routes.php                # 전체 라우팅
│   │   ├── Filters.php               # auth:member / auth:admin
│   │   ├── Services.php              # ThemeView 기본 렌더러 등록
│   │   ├── OAuth.php                 # 소셜 로그인 설정
│   │   ├── Editor.php                # TinyMCE API 키
│   │   ├── PG.php                    # PG사 설정
│   │   ├── Scheduler.php             # 스케줄러 (5분 주기 expire)
│   │   └── Security.php
│   ├── Controllers/
│   │   ├── BaseController.php        # 설정·메뉴·배너·팝업 전역 주입
│   │   ├── Front/                    # 프론트 컨트롤러 (11개)
│   │   └── Admin/                   # 관리자 컨트롤러 (16개)
│   ├── Filters/
│   │   └── AuthFilter.php            # 세션 기반 인증/권한 필터
│   ├── Libraries/
│   │   ├── CouponService.php         # 쿠폰 검증·할인 계산
│   │   ├── FileUploader.php          # 게시판 첨부 (확장자 화이트리스트)
│   │   ├── ImageUploader.php         # 배너·팝업 이미지 업로드
│   │   ├── MediaUploader.php         # 미디어 라이브러리 업로드
│   │   ├── SeoHelper.php             # OG태그 + GA 자동 생성
│   │   ├── ThemeView.php             # 테마 경로 우선 해석 렌더러
│   │   ├── OAuth/                    # 소셜 로그인 Provider (Google / Kakao / Naver)
│   │   └── PG/                      # PG 어댑터 (7종)
│   ├── Models/                       # 22개 도메인 모델
│   ├── Traits/
│   │   └── HasSlug.php
│   ├── Database/Migrations/          # 마이그레이션 22개
│   └── Views/
│       ├── admin/                    # 관리자 뷰
│       ├── auth/                     # 인증 뷰
│       ├── board/                    # 게시판 뷰
│       ├── layouts/admin.php         # 관리자 레이아웃
│       ├── pages/                    # 동적 페이지 뷰
│       ├── shop/                     # 쇼핑 뷰 (장바구니·주문·마이페이지)
│       └── themes/                   # 멀티테마
│           └── default/              # 기본 테마
├── public/
│   └── themes/default/              # 테마 CSS / JS
├── docs/
│   ├── env.example                  # 환경변수 예시
│   └── roadmap.md                   # 개발 로드맵
├── dark.zip                         # 다크 테마 샘플
├── spring.zip                       # 스프링 테마 샘플
└── violet.zip                       # 바이올렛 테마 샘플
```

---

## 4. DB 스키마

### 4-1. 기본 CMS 테이블

| 테이블 | 설명 |
|--------|------|
| `users` | 회원 (role: admin/member, point_balance) |
| `settings` | 사이트 전역 설정 (key-value) |
| `pages` | 동적 페이지 (슬러그 기반) |
| `menus` | 네비게이션 메뉴 (2단계 드롭다운) |
| `media` | 미디어 라이브러리 |
| `inquiries` | 문의 수신함 |
| `boards` | 게시판 설정 |
| `posts` | 게시글 (소프트 삭제) |
| `post_files` | 첨부파일 |
| `post_comments` | 댓글 (소프트 삭제) |
| `banners` | 배너 (위치·기간·우선순위) |
| `popups` | 팝업 (위치·좌표·기간) |
| `popup_pages` | 팝업-메뉴 연결 중간 테이블 |

### 4-2. 쇼핑 테이블

| 테이블 | 설명 |
|--------|------|
| `categories` | 상품 카테고리 (parent_id 계층 구조) |
| `products` | 상품 (status: on_sale/sold_out/hidden, 배송 타입) |
| `product_images` | 상품 이미지 (media 연동, is_primary) |
| `cart_items` | 장바구니 (user_id 기반) |
| `shipping_addresses` | 회원 배송지 주소록 (is_default) |
| `orders` | 주문 (배송지 스냅샷, 쿠폰·포인트 적용 정보) |
| `order_items` | 주문 상품 (상품명·단가 스냅샷) |
| `payments` | 결제 기록 (pg_tid UNIQUE, pg_provider) |
| `order_status_logs` | 주문 상태 변경 이력 |
| `stock_logs` | 재고 변경 이력 (type: adjust/order/cancel/in/out) |
| `coupons` | 쿠폰 (type: fixed/percent, 수량·기간·최소금액 조건) |
| `user_coupons` | 쿠폰 발급·사용 이력 |
| `point_logs` | 포인트 적립·차감·환급 이력 |

---

## 5. 주요 모듈 분석

### 5-1. 인증 시스템

- CI4 세션 기반 로그인/회원가입
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
pending → paid → preparing → shipped → delivered
       ↘ awaiting_payment → paid (무통장 입금 확인)
pending/awaiting_payment/paid → cancelled
paid/preparing → cancelled (관리자 강제)
delivered → refund_requested → refunded
pending → expired (스케줄러, 30분 초과)
```

### 5-4. 쿠폰 시스템

`CouponService`가 검증·할인 계산을 담당:
- **코드 직접 입력** vs **발급된 user_coupon_id** 두 경로 지원
- 검증 조건: 활성 여부 / 유효 기간 / 수량 소진 / 최소 주문 금액 / 1인당 사용 제한
- 할인 타입: `fixed`(정액) / `percent`(정률, max_discount_amount 상한 적용)
- 주문 취소·만료 시 쿠폰 자동 복구 (source='code'면 user_coupon 레코드 삭제, 'issued'면 상태 복원)

### 5-5. 포인트 시스템

- 주문 생성 시 `FOR UPDATE` 잠금 후 차감 (잔액 부족 시 트랜잭션 롤백)
- 포인트 적립은 `delivered` 전환 시 확정 (`point_earned_amount`)
- 취소·만료·환불 시 사용 포인트 환급, 이미 적립된 포인트 회수

### 5-6. 재고 관리

- 결제 확정(paid) 시점에 `SELECT FOR UPDATE` 후 차감
- 재고 0이면 자동 `sold_out` 전환
- 취소 시 재고 복구 + `sold_out → on_sale` 자동 전환
- `stock_logs` 테이블에 모든 변경 이력 기록

### 5-7. 테마 시스템

`ThemeView` 렌더러가 파일 해석 순서를 제어:
```
활성 테마 폴더 → default 테마 폴더 → 원본 경로
```
- ZIP 업로드로 테마 설치 (Zip-slip 방지, 확장자 화이트리스트)
- 콘텐츠 뷰(`board/`, `auth/`, `admin/`)는 테마와 완전 분리
- 샘플 테마: `default`, `dark`, `violet`, `spring`

### 5-8. 성능 최적화

- 배너·팝업·사이트 설정·메뉴: CI4 File Cache (1시간)
- 캐시 무효화: 모델 `afterInsert/Update/Delete` 콜백으로 즉시 반영
- DB 인덱스: `posts(board_id, is_notice, id)`, `posts(deleted_at)`, `banners(position, is_active)`, `popups(is_active, show_scope)`, `inquiries(is_read)`

---

## 6. 라우팅 구조

### 프론트 라우트

| 경로 | 컨트롤러 | 설명 |
|------|----------|------|
| `GET /` | `Front\ShopController::index` | 홈 |
| `GET|POST /auth/*` | `Front\AuthController` | 로그인·회원가입·프로필 |
| `GET /auth/social/{provider}` | `Front\SocialAuthController` | OAuth2 소셜 로그인 |
| `GET|POST /board/{slug}/*` | `Front\BoardController` | 게시판 CRUD·댓글 |
| `GET /shop` | `Front\ShopController::index` | 상품 목록 |
| `GET /shop/{slug}` | `Front\ShopController::detail` | 상품 상세 |
| `POST /cart/add` | `Front\CartController::add` | 장바구니 추가 (비회원 허용) |
| `GET|POST /cart/*` | `Front\CartController` | 장바구니 관리 (로그인 필요) |
| `GET|POST /order/*` | `Front\OrderController` | 주문서·결제 (로그인 필요) |
| `GET|POST /payment/callback/{pg}` | `Front\PaymentController` | PG 콜백 |
| `GET|POST /mypage/*` | `Front\MyPageController` | 주문이력·배송지·쿠폰·포인트 |
| `GET /{slug}` | `Front\PageController::show` | 동적 페이지 (최하위) |

### 관리자 라우트 (`/admin`, auth:admin 필터)

| 메뉴 | 경로 | 컨트롤러 |
|------|------|----------|
| 대시보드 | `/admin/dashboard` | `Admin\DashboardController` |
| 상품 관리 | `/admin/products/*` | `Admin\ProductController` |
| 카테고리 | `/admin/products/categories/*` | `Admin\ProductController` |
| 재고 관리 | `/admin/inventory/*` | `Admin\InventoryController` |
| 주문 관리 | `/admin/orders/*` | `Admin\OrderController` |
| 매출 관리 | `/admin/sales` | `Admin\SalesController` |
| 쿠폰 관리 | `/admin/coupons/*` | `Admin\CouponController` |
| 포인트 관리 | `/admin/points/*` | `Admin\PointController` |
| 배너 관리 | `/admin/banners/*` | `Admin\BannerController` |
| 팝업 관리 | `/admin/popups/*` | `Admin\PopupController` |
| 게시판 관리 | `/admin/boards/*` | `Admin\BoardManagerController` |
| 페이지 관리 | `/admin/pages/*` | `Admin\PageManagerController` |
| 회원 관리 | `/admin/users/*` | `Admin\UserController` |
| 메뉴 관리 | `/admin/menus/*` | `Admin\MenuController` |
| 미디어 | `/admin/media/*` | `Admin\MediaController` |
| 사이트 설정 | `/admin/settings/*` | `Admin\SettingController` |
| 문의 수신함 | `/admin/inquiries/*` | `Admin\InquiryController` |

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
| 파일 업로드 | 확장자 화이트리스트 (php, exe 등 실행파일 차단), 저장명 랜덤 변환 |
| 관리자 라우트 | `auth:admin` 필터 전체 보호 |
| 비밀번호 | `password_hash()` / `password_verify()` |
| CSRF | CI4 기본 CSRF 필터 (PG 콜백 엔드포인트는 예외) |
| 이중 결제 | `payments.pg_tid` UNIQUE 제약 |
| 재고 경쟁 | `SELECT FOR UPDATE` 비관적 잠금 |
| Zip-slip | 테마 ZIP 업로드 시 `..` / `/` 시작 경로 차단 |

---

## 9. 스케줄러

```bash
# crontab (매 분 실행)
* * * * * cd /path/to/shop && php spark schedule:run >> /dev/null 2>&1
```

- `App\Config\Scheduler`: `orders:expire` 커맨드를 **5분 간격** 등록
- `App\Commands\ExpireOrders`: 30분 초과 `pending` 주문을 `expired` 처리 + 쿠폰·포인트 복구

---

## 10. 개발 로드맵 현황

| # | 기능 | 상태 |
|---|------|------|
| 1 | 배너 관리 | ✅ 완료 |
| 2 | 팝업 관리 | ✅ 완료 |
| 3 | 성능 최적화 (캐싱 + DB 인덱스) | ✅ 완료 |
| 4-1 | 상품 목록·상세 | ✅ 완료 |
| 4-2 | 장바구니 | ✅ 완료 |
| 4-3 | 주문·결제 (PG 6종) | ✅ 완료 |
| 4-4 | 무통장입금 | ✅ 완료 |
| 4-5 | 재고 관리 (관리자) | ✅ 완료 |
| 4-6 | 마이페이지 (주문 이력) | ✅ 완료 |
| 4-7 | 주문 관리 (관리자) | ✅ 완료 |
| 4-8 | 매출 관리 (관리자) | ✅ 완료 |
| 4-9 | 쿠폰·포인트 시스템 | ✅ 완료 |
| 4-10 | 배송지 주소록 | ✅ 완료 |
| 5 | 라이센스 관리 | 📋 예정 |

---

## 11. 알려진 이슈 및 TODO

| 항목 | 내용 |
|------|------|
| PG 재고 부족 자동 취소 | `confirmPaid()` 실패 시 PG 자동 취소 API 호출 미구현 |
| PG 서버 웹훅 | 현재 브라우저 리다이렉트 콜백만 지원, 서버-투-서버 웹훅 별도 구현 필요 |
| 게시판 검색 | `LIKE '%키워드%'` 방식 — 대용량 시 FULLTEXT 인덱스 전환 검토 필요 |
| 매출 집계 | 환불 포함 gross 매출 기준 — 순매출(net) 지표 추가 필요 |
| 라이센스 관리 | 미구현 (5번 로드맵) |

---

## 12. 설치 및 실행

```bash
# 1. CI4 프로젝트 생성 후 파일 복사
composer create-project codeigniter4/appstarter my-project
cd my-project && cp -r /path/to/shop/app /path/to/shop/public .

# 2. 환경 설정
cp env .env
# .env에서 DB, TinyMCE API 키, PG 키 설정

# 3. 마이그레이션 실행
php spark migrate

# 4. 업로드 폴더 권한
chmod -R 755 public/uploads writable

# 5. 개발 서버
php spark serve

# 6. Cron 등록 (운영 서버)
crontab -e
# * * * * * cd /path/to/shop && php spark schedule:run >> /dev/null 2>&1
```

**기본 관리자 계정:**
- 이메일: `admin@example.com`
- 비밀번호: `admin1234!`
