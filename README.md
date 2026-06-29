# AICreo Shop — CI4 쇼핑몰 보일러플레이트

> CodeIgniter 4 기반, 1인 웹에이전시를 위한 빠른 납품용 쇼핑몰 템플릿

---

## 개요

`default/` 템플릿(기업 홈페이지)에 쇼핑몰 기능을 추가한 독립 CI4 프로젝트입니다.  
상품·카트·주문·결제·포인트·쿠폰·회원 등급 등 중형 쇼핑몰에 필요한 기능을 포함하며,  
**코어는 재사용하고 테마(껍데기)만 교체**해 빠른 납품을 목표로 설계되어 있습니다.

---

## 주요 기능

### 쇼핑 프론트
| 기능 | 설명 |
|------|------|
| 홈 / Welcome 페이지 | 기본 홈 또는 `/welcome` 선택 가능 — Hero·카테고리·기획전·신상품·할인 섹션 |
| 상품 목록 | 카테고리 필터, 가격 범위·할인 필터, 키워드 검색, 정렬 (인기·신상·가격순) |
| 상품 상세 | 다중 이미지, 옵션(SKU), 재고 확인, 상품 문의(QnA), 리뷰, 찜하기 |
| 빠른 장바구니 | 상품 목록에서 바로 담기 (모달 팝업, 옵션 있는 상품도 지원) |
| 장바구니 | 게스트(세션) · 회원 공용, 수량 변경, 삭제, 쿠폰 적용, 포인트 사용 |
| 주문 | 배송지 선택·입력 (카카오 주소 API), 배송 요청사항, 쿠폰·포인트 최종 적용 |
| 결제 | 5개 PG사 + 무통장입금 지원 |
| 기획전 | 기획전 목록·상세, 기간·할인 설정, 어드민 관리 |
| 재입고 알림 | 품절 상품 재입고 시 이메일 알림 신청 |

### 마이페이지
| 기능 | 설명 |
|------|------|
| 주문 내역 | 주문 목록·상세, 배송 추적, 구매 확정 |
| 취소 / 반품 / 교환 | 회원 직접 신청 → 관리자 승인/거부 처리 |
| 간편 재주문 | 과거 주문 상품을 한 번에 장바구니에 추가 |
| 배송지 관리 | 복수 배송지 저장·수정·삭제, 기본 배송지 설정 |
| 쿠폰 / 포인트 | 보유 쿠폰 목록, 포인트 적립·사용 내역 |
| 찜 목록 | 찜한 상품 목록, 장바구니 바로 담기 |

### 회원 인증
| 기능 | 설명 |
|------|------|
| 이메일 가입 | 가입 → 이메일 인증 24시간 이내 활성화, 재발송 1분 쿨다운 |
| 소셜 로그인 | Google · Naver · Kakao OAuth2 |
| 회원 정보 | 휴대폰번호(필수) · 성별 · 생일 · 주소 (카카오 주소 API) |
| 회원 등급 | 구매 실적 기반 자동 등급 산정, 등급별 포인트 적립률 차등 |
| 인증 대기 화면 | `auth/verify-pending` — 인증 메일 발송 안내 + 재발송 버튼 |

### 게시판 / 커뮤니티
| 기능 | 설명 |
|------|------|
| 다중 게시판 | 슬러그별 독립 게시판, 권한 (비회원·회원·관리자) 설정 |
| 위지윅 에디터 | TinyMCE 6 기반 글쓰기·수정, 이미지 붙여넣기·업로드 |
| 파일 첨부 | 이미지 + 일반 파일 복수 첨부, 확장자 화이트리스트, 최대 10MB |
| 비회원 게시 | 이름 + 비밀번호 입력으로 작성·수정·삭제 |
| 댓글 | 회원·비회원 댓글, 소프트 삭제 |
| 공지글 / 비밀글 | 상단 고정 공지(최대 5개), 작성자·관리자만 열람 가능 |
| 문의폼 | 이름·이메일·연락처·내용 → DB 저장 + 이메일 자동 발송 |

### 관리자 패널 (`/admin`)
| 메뉴 | 기능 |
|------|------|
| 대시보드 | 매출·주문·회원·상품 통계 위젯, 미읽음 문의 알림 |
| 상품 관리 | AG Grid 목록, CRUD, 다중 이미지, 옵션(SKU), 상품 복사, 일괄 편집, 기획전 상품 지정 |
| 카테고리 | 계층형 카테고리 (parent_id) |
| 재고 관리 | 재고 부족 필터, 인라인 재고 수정, 재입고 알림 발송 |
| 주문 관리 | AG Grid 목록, 상태 변경, 상태 일괄 변경, 내부 메모, 반품/교환 승인·거부 |
| 배송 관리 | 배송업체 설정, 개별 송장 입력, CSV 일괄 업로드 |
| 엑셀 다운로드 | 주문 엑셀(XLSX), 송장 입력용 CSV 템플릿 다운로드 |
| 무통장 확인 | 입금 확인 → `awaiting_payment → paid` 전환 |
| 반품 / 교환 | 승인·거부, 교환 대체품 지정, 환불 확인 처리 |
| 쿠폰 관리 | AG Grid 목록, 쿠폰 CRUD, 회원별 수동 발급 |
| 포인트 관리 | 회원별 포인트 수동 조정, 내역 조회 |
| 회원 등급 | 등급 기준·적립률·이름 설정 |
| 회원 관리 | AG Grid 목록, 닉네임·이메일·역할·활성 상태 수정, 이메일 인증 수동 처리 |
| 매입처 관리 | 공급업체(Supplier) CRUD, 상품 매입단가·영업이익 계산 |
| 기획전 관리 | 기획전 CRUD, 상품 검색·연결 |
| Welcome 설정 | 섹션별 ON/OFF, 제목, 표시 수량, 기획전 상품 토글 |
| 통계 | 일별·월별 접속자·매출 차트, 인기 상품, 유입 경로 |
| 리뷰 관리 | AG Grid 목록, 리뷰 삭제 |
| 상품 문의 | QnA 목록, 답변 입력, 삭제 |
| 게시판 관리 | 게시판 생성·수정, 권한·첨부 허용 설정 |
| 전체 게시물 | AG Grid 목록, 게시판 필터·키워드 검색, 강제 삭제 |
| 배너 / 팝업 | 배너·팝업 CRUD, 노출 기간·위치 설정 |
| 페이지 관리 | TinyMCE 에디터 기반 동적 페이지 CRUD, SEO 메타 설정 |
| 메뉴 관리 | GNB 메뉴 추가·수정·삭제, 2단계 드롭다운 |
| 미디어 라이브러리 | 드래그 업로드, 이미지 경로 복사 |
| 문의 수신함 | 문의 목록·상세, 이메일 바로 답장 |
| 사이트 설정 | 기본·연락처·SNS·SEO·푸터·배송비·결제·SMTP 설정 |
| 테마 관리 | ZIP 업로드 설치, 설치된 테마 목록, 클릭 한 번으로 전환 |

---

## AI · 자동화 기능

> **제공자**: Groq · Claude · OpenRouter 중 선택 (`/admin/settings/api`). API 키 미설정 시 각 기능은 자동 비활성화되거나 일반 동작으로 폴백합니다.
> **프롬프트**: 모든 AI 프롬프트는 설정 화면에서 코드 수정 없이 편집 가능.
> 추천·발주·이상탐지·검색 등 일부 기능은 LLM 없이 동작하는 **휴리스틱** 방식이라 비용·지연이 없습니다.

### 기반 구조

| 구성 | 설명 |
|------|------|
| 비동기 작업 큐 | `ai_jobs` 테이블 + `ai:work` 워커(크론) — 무거운 AI 호출을 백그라운드 처리해 요청을 막지 않음 |
| 결과 캐시 | `AiCache` — 동일 입력 반복 호출의 API 비용·지연 제거, 데이터 변경 시 무효화 |
| 프롬프트 설정화 | 기능별 프롬프트를 `settings`로 분리 — 납품처별 톤·정책 조정 |

### 운영자 (관리자)

| 기능 | 설명 |
|------|------|
| 상품 카테고리 추천 | 상품명·설명으로 적합 카테고리 자동 추천 |
| 상품 설명 생성 | 상품명·기존 설명 기반 HTML 상세설명 생성 |
| 이미지 → 상품정보 (Vision) | 상품 이미지 업로드만으로 상품명·설명 자동 작성 (Claude 멀티모달) |
| 상품 문의 답변 초안 | QnA 문의에 대한 답변 초안 1클릭 생성 |
| 문의 자동 분류 + 답변 초안 | 수신 문의를 카테고리·우선순위·감성으로 자동 분류, 답변 초안 생성 |
| 리뷰 AI 요약 + 부정 리뷰 감지 | 상품 리뷰 요약·장단점·감성 분석, 부정 리뷰 자동 표시·알림 |
| 매출 AI 분석 리포트 | 기간 매출 집계를 자연어 리포트(요약·추세·결제수단·제안)로 생성 |
| 발주 제안 *(휴리스틱)* | 판매 속도로 소진 예상일·권장 발주 수량 계산, 1클릭 입고 |
| 이상 주문 탐지 *(휴리스틱)* | 고액·단시간 다건·동일 연락처 다계정 등 의심 주문을 위험도 순 플래그 |
| 재입고 알림 메일 개인화 | 재입고 알림 메일 본문을 상품에 맞춰 AI가 개인화 |

### 고객 (프론트)

| 기능 | 설명 |
|------|------|
| 개인화 상품 추천 *(휴리스틱)* | 찜·구매 이력의 선호 카테고리 기반 추천 블록 (상품목록·찜목록) |
| 시맨틱 검색 | 검색어를 AI로 의미 확장(오타 교정·동의어·관련어)해 상품 검색 재현율 향상 |

---

## 기술 스택

| 영역 | 선택 |
|------|------|
| 백엔드 | CodeIgniter 4 (PHP 8.1+) |
| 프론트 | Bootstrap 5 · Bootstrap Icons |
| 관리자 그리드 | AG Grid (CDN, Community Edition) |
| 에디터 | TinyMCE 6 (CDN, API 키 `.env` 관리) |
| DB | MySQL / MariaDB |
| 인증 | CI4 Session 기반 (+ OAuth2 소셜 로그인) |
| 캐시 | CI4 File Cache (설정·메뉴·배너·팝업) |
| 엑셀 출력 | PhpSpreadsheet 5.x |
| PG 결제 | 토스페이먼츠·KG이니시스·나이스페이·카카오페이·네이버페이·PAYCO·무통장 |
| 테마 | 폴더 기반 멀티 테마 (레이아웃·컴포넌트·CSS/JS 분리) |

---

## 디렉토리 구조

```
app/
├── Config/
│   ├── Routes.php          # 전체 라우팅
│   ├── Filters.php         # auth:member / auth:admin 필터, CSRF 예외
│   ├── Services.php        # ThemeView를 기본 렌더러로 등록
│   ├── OAuth.php           # 소셜 로그인 설정
│   ├── PG.php              # PG사 키 설정 (.env 참조)
│   ├── Editor.php          # TinyMCE API 키
│   └── Scheduler.php       # orders:expire 5분 주기 스케줄
├── Controllers/
│   ├── BaseController.php  # 설정·메뉴·세션·카트수량 전역 주입
│   ├── Front/
│   │   ├── ShopController.php      # 상품 목록·상세·QnA·리뷰·찜
│   │   ├── CartController.php      # 장바구니
│   │   ├── OrderController.php     # 주문 생성·완료·실패
│   │   ├── PaymentController.php   # PG 콜백 처리
│   │   ├── CouponController.php    # 쿠폰 유효성 검증
│   │   ├── MyPageController.php    # 마이페이지 전체
│   │   ├── PromotionController.php # 기획전 목록·상세
│   │   ├── HomeController.php      # 홈
│   │   ├── PageController.php      # 동적 페이지 + 문의폼
│   │   ├── BoardController.php     # 게시판 전체
│   │   ├── AuthController.php      # 로그인·회원가입·이메일인증
│   │   └── SocialAuthController.php# OAuth2 소셜 로그인
│   └── Admin/
│       ├── DashboardController.php
│       ├── ProductController.php   # 상품 CRUD·복사·일괄편집·재고
│       ├── OrderController.php     # 주문 관리·배송·반품·교환·엑셀
│       ├── CouponController.php
│       ├── PointController.php
│       ├── GradeController.php
│       ├── UserController.php
│       ├── SupplierController.php
│       ├── PromotionController.php
│       ├── WelcomeController.php
│       ├── StatsController.php
│       ├── ReviewController.php
│       ├── QnaController.php
│       ├── InventoryController.php
│       ├── SalesController.php
│       ├── BannerController.php
│       ├── PopupController.php
│       ├── PageManagerController.php
│       ├── BoardManagerController.php
│       ├── PostController.php
│       ├── MenuController.php
│       ├── MediaController.php
│       ├── SettingController.php
│       └── InquiryController.php
├── Models/                 # 30개 도메인 모델
├── Filters/AuthFilter.php
├── Libraries/
│   ├── ThemeView.php       # 테마 경로 우선 해석 렌더러
│   ├── CouponService.php   # 쿠폰 적용 로직
│   ├── GradeService.php    # 회원 등급 산정 로직
│   ├── Mailer.php          # SMTP 이메일 발송
│   ├── FileUploader.php    # 게시판 첨부 (보안 확장자 검증)
│   ├── ImageUploader.php   # 배너·팝업 이미지 (2MB, 이미지만)
│   ├── MediaUploader.php   # 미디어 라이브러리 업로드
│   ├── SeoHelper.php       # OG태그 + GA 자동 생성
│   ├── OAuth/              # Google·Naver·Kakao 프로바이더
│   └── PG/                 # PG 어댑터 7종
├── Database/
│   ├── Migrations/         # 40+ 마이그레이션
│   └── Seeds/              # 상품·게시물·문의 샘플 데이터 시더
└── Views/
    ├── themes/             # 멀티 테마 폴더
    │   └── default/
    │       ├── layouts/main.php
    │       └── components/  # navbar / footer / contact_form
    ├── layouts/admin.php
    ├── shop/               # 상품 목록 / 상세 / welcome
    ├── cart/
    ├── order/
    ├── mypage/
    ├── promotion/
    ├── board/
    ├── auth/
    └── admin/
public/
└── themes/
    ├── default/            # 기본 테마 CSS / JS
    ├── dark/
    ├── violet/
    └── spring/             # 좌측 배너 사이드바 레이아웃
```

---

## DB 테이블

| 테이블 | 설명 |
|--------|------|
| `users` | 회원 (admin·member 역할, 소셜 로그인, 포인트잔액, 등급) |
| `settings` | 사이트 전역 설정 (key-value, active_theme, PG·SMTP·배송비 등) |
| `pages` | 동적 페이지 |
| `menus` | 네비게이션 메뉴 (2단계) |
| `media` | 미디어 라이브러리 |
| `banners` | 배너 (위치·기간 설정) |
| `popups` / `popup_pages` | 팝업 + 노출 페이지 URL |
| `inquiries` | 문의 수신함 |
| `boards` / `posts` / `post_files` / `post_comments` | 게시판 시스템 |
| `categories` | 상품 카테고리 (parent_id 계층) |
| `products` | 상품 (가격·할인가·재고·상태·배송비·매입처·기획전) |
| `product_images` | 상품 다중 이미지 (is_primary 플래그) |
| `product_skus` | 상품 옵션 조합 (SKU별 재고·가격) |
| `product_qnas` | 상품 문의 |
| `product_reviews` | 상품 리뷰 (평점·이미지) |
| `wishlists` | 찜 목록 |
| `cart_items` | 장바구니 (user_id 또는 session_id 게스트 지원) |
| `orders` | 주문 헤더 (상태·배송 스냅샷·쿠폰·포인트·교환·반품) |
| `order_items` | 주문 시점 상품 스냅샷 (이름·가격·수량) |
| `order_status_logs` | 주문 상태 변경 이력 |
| `order_memos` | 주문 내부 메모 |
| `shipping_addresses` | 회원 저장 배송지 |
| `payments` | PG 결제 정보 (pg_tid UNIQUE, 응답 JSON) |
| `stock_logs` | 재고 변동 감사 로그 |
| `coupons` / `user_coupons` | 쿠폰 정의 + 회원 발급 내역 |
| `point_logs` | 포인트 적립·사용 내역 |
| `exchange_items` | 교환 대체 상품 |
| `promotions` | 기획전 + 상품 연결 |
| `suppliers` | 매입처 (공급업체) |
| `access_logs` / `access_log_summaries` | 접속 통계 원본·집계 |
| `restock_alerts` | 재입고 알림 신청 |

---

## PG 결제 레이어

`PGInterface` — `buildPaymentParams()` · `confirm()` · `cancel()` 세 메서드 정의.  
`PGFactory::create(string $provider)` 로 어댑터 해결.

| 어댑터 | PG |
|--------|----|
| `TossPaymentsAdapter` | 토스페이먼츠 |
| `InicisAdapter` | KG이니시스 |
| `NicePayAdapter` | 나이스페이 |
| `KakaoPayAdapter` | 카카오페이 |
| `NaverPayAdapter` | 네이버페이 |
| `PaycoAdapter` | PAYCO |
| `BankTransferAdapter` | 무통장입금 |

PG 키는 `Config/PG.php` — 모든 값은 `.env`에서 읽어옴.

---

## 주문 상태 흐름

```
pending → [PG 결제 성공]  → paid → preparing → shipped → delivered
pending → [무통장 입금대기] → awaiting_payment → [관리자 확인] → paid
paid / preparing           → cancelled  (재고 복원)

delivered → [회원, 7일 이내] → return_requested  → [관리자 승인] → return_approved
                                                                  → [관리자 환불 확인] → refunded
                                                  → [관리자 거부] → delivered

delivered → [회원, 7일 이내] → exchange_requested → [관리자 처리] → exchanged / rejected

paid / preparing / shipped  → [회원] refund_requested → [관리자] → refunded
```

- `pending` 주문은 30분 미결제 시 스케줄러가 `expired` 처리 (재고 미차감)
- 재고 차감: PG 성공 콜백 또는 무통장 관리자 확인 시점에만 수행
- `payments.pg_tid` UNIQUE 제약 — 중복 콜백 자동 차단

---

## 설치 방법

### 1. 환경 설정
```bash
cp env .env
```
`.env` 파일에서 DB 정보 및 필수 설정 입력:
```
CI_ENVIRONMENT = development
database.default.hostname = localhost
database.default.database = your_db_name
database.default.username = your_db_user
database.default.password = your_db_password
database.default.DBDriver = MySQLi

# TinyMCE API 키 (https://www.tiny.cloud)
editor.tinymce_api_key = your-tinymce-api-key

# PG 키 (사용할 PG사만 설정)
pg.toss.client_key    = ...
pg.toss.secret_key    = ...
```

### 2. 마이그레이션 실행
```bash
php spark migrate
```
테이블 생성 + 기본 데이터(게시판·관리자 계정·기본 설정)가 한 번에 처리됩니다.

### 3. (선택) 샘플 데이터 시더
```bash
php spark db:seed ProductSeeder
php spark db:seed PostSeeder
php spark db:seed InquirySeeder
```

### 4. 업로드 폴더 권한 (Linux)
```bash
chmod -R 755 public/uploads writable
```

### 5. 개발 서버 실행
```bash
php spark serve
```

### 6. Cron 등록 (운영 서버 — 주문 만료 처리)
```bash
crontab -e
```
아래 줄 추가 (`/path/to/shop` 을 실제 경로로 교체):
```
* * * * * cd /path/to/shop && php spark schedule:run >> /dev/null 2>&1
```
CI4 스케줄러가 `orders:expire` 커맨드를 **5분 간격**으로 실행합니다.

수동 실행:
```bash
php spark orders:expire        # 기본 30분 초과 만료
php spark orders:expire 60     # 60분 초과로 기준 변경
```

---

## 기본 계정

| 구분 | 값 |
|------|----|
| 이메일 | `admin@example.com` |
| 비밀번호 | `admin1234!` |

> 최초 로그인 후 반드시 비밀번호를 변경하세요.

---

## 주요 URL

### 프론트
| URL | 설명 |
|-----|------|
| `/` | 홈 |
| `/welcome` | Welcome 페이지 (어드민 설정으로 첫화면 지정 가능) |
| `/shop` | 상품 목록 |
| `/shop/{slug}` | 상품 상세 |
| `/cart` | 장바구니 |
| `/order` | 주문서 |
| `/mypage/orders` | 내 주문 내역 |
| `/mypage/coupons` | 내 쿠폰 |
| `/mypage/points` | 포인트 내역 |
| `/mypage/wishlist` | 찜 목록 |
| `/mypage/addresses` | 배송지 관리 |
| `/promotions` | 기획전 목록 |
| `/promotion/{slug}` | 기획전 상세 |
| `/board/notice` | 공지사항 |
| `/auth/login` | 로그인 |
| `/auth/register` | 회원가입 |

### 관리자
| URL | 설명 |
|-----|------|
| `/admin` | 대시보드 |
| `/admin/products` | 상품 관리 |
| `/admin/orders` | 주문 관리 |
| `/admin/coupons` | 쿠폰 관리 |
| `/admin/points` | 포인트 관리 |
| `/admin/grades` | 회원 등급 설정 |
| `/admin/users` | 회원 관리 |
| `/admin/suppliers` | 매입처 관리 |
| `/admin/promotions` | 기획전 관리 |
| `/admin/welcome` | Welcome 페이지 설정 |
| `/admin/stats` | 접속·매출 통계 |
| `/admin/reviews` | 리뷰 관리 |
| `/admin/qna` | 상품 문의 관리 |
| `/admin/settings/general` | 사이트 기본 설정 |
| `/admin/settings/theme` | 테마 관리 |

---

## 보안 사항

- 파일 업로드: 확장자 화이트리스트 (php·exe 등 실행파일 차단), 랜덤 파일명
- 관리자 라우트: `auth:admin` 필터로 전체 보호
- 비밀번호: `password_hash()` / `password_verify()`
- CSRF: CI4 기본 CSRF 필터 적용 (PG 콜백 URL은 명시적 예외 처리)
- SQL: CI4 Query Builder + 바인딩만 사용 — 문자열 연결 금지
- XSS: 뷰 출력 시 `esc()` 래핑

---

## 테마 시스템

ZIP 업로드 또는 직접 폴더 배치 방식 모두 지원합니다.  
자세한 내용은 상위 `default/README.md`의 "테마 추가 방법" 항목을 참고하세요.

저장소에 포함된 샘플 테마:

| 테마 | 특징 |
|------|------|
| `default` | Bootstrap 기본 스타일 |
| `dark` | 다크 네이비 + 인디고 포인트 |
| `violet` | 바이올렛 브랜드, 풀 라운드 버튼 |
| `spring` | 핑크-그린 파스텔, **좌측 배너 사이드바** 레이아웃 |

---

## 변경 이력

### 2026-06-28 (최신) — AI 효율화 로드맵 적용

| 항목 | 변경 내용 |
|------|----------|
| **AI 기반 구조** | 비동기 작업 큐(`ai_jobs` + `ai:work`), 결과 캐시(`AiCache`), 프롬프트 설정화 |
| **콘텐츠 자동화** | 리뷰 요약·부정리뷰 감지, 문의 자동분류·답변초안, 이미지→상품정보(Vision) |
| **운영 인텔리전스** | 매출 AI 리포트, 발주 제안(판매추세), 이상 주문 탐지 |
| **개인화·검색** | 메일 본문 개인화, 개인화 상품 추천, 시맨틱 검색(쿼리 확장·오타보정) |
| **AI 제공자** | Groq · Claude · OpenRouter 선택, 프롬프트 설정 화면 편집 지원 |

자세한 기능은 위 [AI · 자동화 기능](#ai--자동화-기능) 섹션 참고.

### 2026-06-17

| 항목 | 변경 내용 |
|------|----------|
| **재입고 알림** | 품절 상품에서 이메일 알림 신청 가능. 관리자 재고 업데이트 시 신청자에게 자동 이메일 발송 |
| **간편 재주문** | 마이페이지 `POST mypage/orders/reorder` — 과거 주문 상품 전체를 현재 장바구니에 추가 |
| **상품 검색 필터** | 가격 범위(최소·최대) · 할인 상품 필터 추가. 기존 카테고리·키워드·정렬 필터와 조합 가능 |
| **관리자 사이드바 3단 구조** | 쇼핑몰·콘텐츠·설정 섹션으로 3단 분류, 섹션별 접기/펴기 상태 localStorage 유지 |

### 2026-06-14 ~ 16

| 항목 | 변경 내용 |
|------|----------|
| **Welcome 페이지** | `/welcome` 신규 — Hero·카테고리·기획전·신상품·할인 섹션. 어드민에서 첫화면을 기본 홈↔Welcome 전환 가능 |
| **Welcome 설정 UI** | `/admin/welcome` 탭 — 섹션별 ON/OFF·제목·수량·기획전 상품 토글, 설정 탭에서 콘텐츠 메뉴로 이전 |
| **AG Grid 페이지네이션** | 상품·주문·쿠폰·리뷰·게시물 AG Grid 목록에 서버사이드 페이지네이션 추가 |
| **샘플 데이터 시더** | `ProductSeeder`, `PostSeeder`, `InquirySeeder` 추가 — `php spark db:seed`로 테스트 데이터 즉시 생성 |
| **빠른 장바구니** | 상품 목록에서 바로 담기 버튼(모달). 옵션 있는 상품은 옵션 선택 팝업 자동 표시 |
| **상품 복사** | `POST admin/products/{id}/copy` — 이미지·옵션 포함 전체 상품 정보 복사 |
| **상품 일괄 편집** | 어드민 상품 목록에서 가격·재고를 인라인 즉시 수정 |
| **AG Grid 도입** | 관리자 상품·주문·쿠폰·리뷰·게시물·회원 목록에 AG Grid 적용 (CDN, 컬럼 정렬·필터·JSON API) |

### 2026-06-12 ~ 13

| 항목 | 변경 내용 |
|------|----------|
| **교환 프로세스** | 교환 사유 코드·대체품 지정·송장 입력. 회원 신청 → 관리자 승인/거부/완료 전 단계 구현 |
| **반품 사유** | 반품 사유 코드 선택 + DB 저장, `AddReturnReasonFields` 마이그레이션 추가 |
| **찜하기 / 최근 본 상품** | 상품 상세에서 찜 토글, 마이페이지 찜 목록, 최근 본 상품 세션 저장 |
| **배송업체 설정** | 어드민 설정에서 배송업체 태그 칩 방식 관리, 주문 상세 송장 셀렉트 박스 연동 |
| **배송 송장 일괄 등록** | CSV 업로드로 여러 주문 송장 한 번에 등록. 템플릿 CSV 다운로드 제공 |
| **주문 엑셀 다운로드** | 주문 목록을 XLSX 파일로 내보내기 (PhpSpreadsheet) |
| **주문 상태 일괄 변경** | 어드민 목록에서 체크박스 선택 후 상태 일괄 변경 |
| **주문 내부 메모** | 관리자 전용 주문 메모 CRUD (`order_memos` 테이블) |
| **재고 부족 필터** | 어드민 상품 목록에서 재고 부족 필터, 인라인 재고 수정 |
| **관리자 대시보드 위젯** | 매출·주문·회원·방문자 통계 위젯 카드 추가 |
| **PAYCO 어댑터** | `PaycoAdapter` 추가 — 7번째 PG 지원 |

### 2026-06-11

| 항목 | 변경 내용 |
|------|----------|
| **이메일 인증 가입** | 가입 즉시 로그인 → 이메일 인증 완료 후 활성화. 토큰 24시간 만료, 재발송 1분 쿨다운 |
| **회원 정보 확장** | `phone`(필수)·`gender`·`birthday`·`email_verify_token`·`email_verify_token_at` 컬럼 추가 |
| **인증 대기 화면** | `auth/verify-pending` 신규 — 재발송 버튼 포함 |
| **회원 등급 시스템** | 구매 실적 기반 자동 등급 산정, 등급별 포인트 적립률, `GradeService` + `AddGradeSystem` 마이그레이션 |
| **상품 리뷰** | 구매 확정 후 리뷰 작성·수정·삭제, 평점·이미지 첨부, 어드민 관리 |
| **상품 QnA** | 상품 상세 문의 작성, 관리자 답변, 비공개 설정 |
| **상품 옵션(SKU)** | 옵션 타입·값 조합별 재고·가격 독립 관리, `product_skus` 테이블 |
| **기획전** | 기간·할인 설정 기획전, 상품 연결, 프론트 목록·상세, 어드민 CRUD |
| **매입처 / 원가 계산** | 공급업체 관리, 상품별 매입단가·판매마진 계산 |
| **접속 통계** | UA 파싱 접속 로그, 일별·월별 차트, 집계 전 원본 삭제 로직 |
| **무통장 확인** | 관리자 `POST orders/{id}/bank_confirm` — `awaiting_payment → paid` 전환 + 재고 차감 |
| **반품 처리** | 회원 반품 신청 → 관리자 승인·거부·환불 확인 전 단계 |
| **이미지 자동 리사이즈** | 상품 이미지 업로드 시 최대 1200px 자동 리사이즈 |
| **N+1 제거 및 복합 인덱스** | 상품 목록·주문 목록 쿼리 최적화, 복합 인덱스 추가 |
| **사이드바 접기/펴기** | 어드민 사이드바 섹션별 접기 상태 localStorage 유지 |

---

## License

MIT
