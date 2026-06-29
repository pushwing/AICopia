# AiCreo 개발 로드맵

## 1. 배너 관리 ✅ 완료

### 등록 항목
- 배너 이미지 업로드 (jpg, jpeg, png, gif / 최대 2MB)
- 시작/종료 날짜 설정
- 운영/미운영 상태 (수동 처리)
- 배너 위치: 메인 상단, 메인 하단, 서브 좌측, 서브 우측
- 링크 URL + 열기 방식 (새 창 / 현재 창)
- 노출 우선순위 번호 (숫자가 낮을수록 우선)

### 리스트 표시 항목
- 썸네일 이미지
- 운영 중: 초록 뱃지 / 미운영: 회색 뱃지
- 시작/종료일
- 위치
- 우선순위

### 기술 사항
- 메인 배너 가로: 1000px (CSS max-width: 100% 반응형)
- 서브 배너 가로: 300px / `.sp-banner-slot` 내부 `<a>` 태그로 삽입 (최대 3개)
- 서브 좌측 배너: 모든 프론트 페이지 레이아웃에 고정 노출
- 메인 배너: 홈(/) 페이지 전용
- 종료일 지난 배너: 프론트에서 자동 숨김 (날짜 조건 쿼리)

### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Database/Migrations/2024-01-01-000007_CreateBannersTable.php` | banners 테이블 |
| `app/Models/BannerModel.php` | 배너 모델 |
| `app/Libraries/ImageUploader.php` | 이미지 업로드 처리 (`new ImageUploader('banners')`) |
| `app/Controllers/Admin/BannerController.php` | 관리자 CRUD |
| `app/Controllers/BaseController.php` | `$subLeftBanners` 전역 주입 (admin 경로 제외) |
| `app/Controllers/Front/HomeController.php` | `$mainTopBanners`, `$mainBotBanners` 홈 전달 |
| `app/Views/admin/banners/list.php` | 관리자 목록 뷰 |
| `app/Views/admin/banners/form.php` | 등록/수정 폼 |
| `app/Views/themes/default/components/banner_slot.php` | 메인 배너 렌더링 컴포넌트 |
| `app/Views/themes/default/layouts/main.php` | default 레이아웃 서브 좌측 배너 슬롯 |

### 테마별 추가 구현 파일 (spring / dark)
| 파일 | 설명 |
|---|---|
| `app/Views/themes/spring/layouts/main.php` | 홈/서브 조건 분기, 서브 좌측 배너 사이드바 |
| `app/Views/themes/spring/pages/home.php` | 메인 상단/하단 배너 섹션 포함 홈 뷰 |
| `public/themes/spring/css/style.css` | `.sp-main-banner-*`, `.sp-banner-img` 스타일 |
| `dark.zip / views/layouts/main.php` | 서브 좌측 배너 사이드바(`dk-layout`) |
| `dark.zip / views/pages/home.php` | 메인 상단/하단 배너 섹션 포함 홈 뷰 |
| `dark.zip / public/css/style.css` | `dk-*` 레이아웃/배너 스타일 추가 |

### 주요 트러블슈팅
| 항목 | 원인 | 해결 |
|---|---|---|
| 서브 좌측 배너 미표시 | spring 테마 layouts에 하드코딩된 플레이스홀더 | DB 데이터 기반 렌더링으로 교체 |
| 배너 시간 조건 오작동 | PHP 서버 UTC, 사용자 입력 KST | `App.php` `appTimezone = 'Asia/Seoul'` 설정 |
| CI4 NULL 조건 쿼리 오류 | `groupStart/orWhere` NULL 호환 이슈 | raw where 문자열로 변경 |
| 홈 리디자인 후 서브 배너 소실 | 레이아웃 전체에서 사이드바 제거 | `uri_string()` 조건으로 홈만 제외 |

---

## 2. 팝업 관리 ✅ 완료

### 등록 항목
- 제목
- 이미지 업로드 (jpg, jpeg, png, gif / 최대 2MB)
- 텍스트 본문 (WYSIWYG 에디터)
- 시작/종료일 설정
- 운영/미운영 상태 (수동 처리)
- 노출 위치: 전체(메인+서브) / 메인 전용 / 특정 페이지 (메뉴 목록에서 복수 선택)
- 화면 표시 좌표 (X, Y — left/top px 입력)
- 우선순위 번호 (숫자가 낮을수록 우선, 동일 위치 팝업 겹침 방지용)

### 리스트 표시 항목
- 썸네일 미리보기
- 운영 중: 초록 뱃지 / 미운영: 회색 뱃지
- 노출 위치
- 표시 좌표 (X, Y)
- 우선순위
- 시작/종료일

### 기술 사항
- 팝업 크기: 이미지 비율 자동 (width 고정, height auto)
- 동일 페이지에 복수 팝업: 우선순위 순으로 정렬 후 좌표 기반 개별 표시 (겹침 없음)
- 닫기 동작: X 버튼 즉시 닫기 + "오늘 하루 보지 않기" (쿠키 24h, 체크 시 즉시 닫힘)
- 특정 페이지 지정: `menus` 테이블 기반 체크박스 다중 선택 → `popup_pages` 중간 테이블
- 좌표 기본값: X=20, Y=20 (미입력 시)

### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Database/Migrations/2024-01-01-000008_CreatePopupsTable.php` | popups + popup_pages 테이블 |
| `app/Models/PopupModel.php` | 모델, `getActiveForPage()`, `syncPages()` (트랜잭션) |
| `app/Libraries/ImageUploader.php` | 이미지 업로드 (`new ImageUploader('popups')`) |
| `app/Controllers/Admin/PopupController.php` | 관리자 CRUD |
| `app/Controllers/BaseController.php` | `$activePopups` 전역 주입 |
| `app/Views/admin/popups/list.php` | 관리자 목록 뷰 |
| `app/Views/admin/popups/form.php` | 등록/수정 폼 (TinyMCE + 이미지 + 메뉴 체크박스) |
| `app/Views/themes/default/components/popups.php` | 프론트 팝업 오버레이 렌더링 |
| `public/themes/default/js/popup.js` | 표시/닫기/쿠키 JS |
| `app/Views/themes/default/layouts/main.php` | 팝업 컴포넌트 삽입 |
| `app/Views/themes/spring/layouts/main.php` | spring 테마 팝업 컴포넌트 삽입 |
| `dark.zip / views/layouts/main.php` | dark 테마 팝업 컴포넌트 삽입 |
| `dark.zip / public/css/style.css` | `.site-popup` 다크 스타일 추가 |

### 주요 트러블슈팅
| 항목 | 원인 | 해결 |
|---|---|---|
| spring 팝업 `position:static` | spring CSS에 팝업 스타일 미포함 | `spring/css/style.css`에 `.site-popup` 블록 추가 |

---

## 3. 성능 개선 ✅ 완료

### 배너·팝업 조회 캐싱
- 매 프론트 요청마다 실행되던 배너 1쿼리 + 팝업 2쿼리를 캐시로 제거 (캐시 1시간)
- 활성 데이터 전체를 캐시하고 **노출 기간(started_at/ended_at)·스코프 판정은 PHP에서 처리** → 캐시가 살아있어도 시간 경과에 따른 노출/종료 정확히 동작
- 팝업은 URI별 캐시 키 증가 없이 단일 키(`active_popups`)로 팝업 + 페이지 URL 매핑 저장
- 캐시 무효화: 모델 콜백(`afterInsert/Update/Delete`) + `syncPages()` → 관리자 수정 즉시 반영
- 부수 효과: 날짜 문자열 SQL 직접 보간 패턴 제거

### DB 인덱스 추가 (`php spark migrate` 필요)
| 인덱스 | 용도 |
|---|---|
| `posts (board_id, is_notice, id)` | 게시판 목록·페이징·카운트 — filesort 제거 |
| `posts (deleted_at)` / `post_comments (deleted_at)` | 소프트 삭제 `deleted_at IS NULL` 조건 |
| `banners (position, is_active)` | 캐시 미스 시 활성 배너 조회 |
| `popups (is_active, show_scope)` | 캐시 미스 시 활성 팝업 조회 |
| `inquiries (is_read)` | 관리자 전 페이지 미읽음 문의 카운트 |

### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Models/BannerModel.php` | `getActiveByPosition()` 캐싱 + 무효화 콜백 |
| `app/Models/PopupModel.php` | `getActiveForPage()` 캐싱 + 무효화 콜백 |
| `app/Database/Migrations/2026-06-10-000001_AddPerformanceIndexes.php` | 인덱스 마이그레이션 |

### 비고
- 기존 캐시(설정 `site_settings`, 메뉴 `nav_menus`)와 합쳐 일반 페이지는 게시판 데이터 외 반복 쿼리 없음
- 게시판 검색은 `LIKE '%키워드%'` 구조라 인덱스 불가 — 수만 건 이상 쌓이면 FULLTEXT 인덱스 전환 검토

---

## 4. 쇼핑 기능

### 4-1. 상품 목록 · 상세 ✅ 완료 (이슈 #3, #4)

### 4-2. 장바구니 ✅ 완료 (이슈 #5)

### 4-3. 주문 · 결제 ✅ 완료 (이슈 #6)

#### 재고 차감 방식: 결제 확정 후 차감 (방식 A)
- **OrderController**: 주문 생성(status: pending) → PG 결제창 호출
- **PaymentController**: PG 리다이렉트 콜백 처리 → 금액 검증 → 재고 차감 → 주문 확정(status: paid)
- pending 주문은 재고를 차감하지 않으므로 결제 실패 시 복구 로직 불필요

#### 결제 흐름
```
장바구니 → POST /order/create (pending 생성)
  ↓
PG 결제창 (프론트)
  ↓
GET|POST /payment/callback/{pg} (사용자 브라우저 리다이렉트 콜백)
  ↓
금액 검증 → SELECT FOR UPDATE → UPDATE stock (조건부) → 주문 paid
  ↓
GET /order/complete/{orderNumber}
```

#### 재고 복구 시나리오
| 상황 | 처리 |
|---|---|
| 결제창 이탈 / 시간 초과 | `orders:expire` 커맨드가 5분마다 30분 초과 pending → expired 처리 |
| PG 결제 실패 | 콜백 미수신 → pending 상태 유지 → 스케줄러가 expired 전환 |
| PG 콜백 수신 후 재고 부족 | confirmPaid 롤백 → 운영 TODO의 PG 자동 취소 구현 필요 |
| 주문 취소 (paid) | stock + qty 복구 + sold_out → on_sale 자동 전환 |

#### 이중 결제 방지
- `payments.pg_tid` UNIQUE 제약으로 PG 콜백 중복 처리 차단

#### 운영 TODO
- PG 승인 성공 후 재고 부족으로 `confirmPaid()`가 실패한 경우, PG 자동 취소 API 호출 구현 필요
- `/payment/callback/{pg}`는 현재 로그인 세션 기반 브라우저 리다이렉트 콜백이므로, PG 서버 웹훅을 받을 경우 별도 엔드포인트/서명 검증/멱등 처리 필요

#### 지원 PG
| PG | 어댑터 | 설정 환경변수 |
|---|---|---|
| 토스페이먼츠 | `TossPaymentsAdapter` | `TOSS_CLIENT_KEY`, `TOSS_SECRET_KEY` |
| KG이니시스 | `InicisAdapter` | `INICIS_MERCHANT_ID`, `INICIS_SIGN_KEY` |
| 나이스페이 | `NicePayAdapter` | `NICEPAY_CLIENT_ID`, `NICEPAY_SECRET_KEY` |
| 카카오페이 | `KakaoPayAdapter` | `KAKAOPAY_SECRET_KEY`, `KAKAOPAY_CID` |
| 네이버페이 | `NaverPayAdapter` | `NAVERPAY_CLIENT_ID`, `NAVERPAY_CLIENT_SECRET`, `NAVERPAY_CHAIN_ID` |
| PAYCO | `PaycoAdapter` | `PAYCO_SELLER_KEY`, `PAYCO_SECRET_KEY` |

#### DB 스키마
| 테이블 | 설명 |
|---|---|
| `orders` | 주문 (배송지 스냅샷 포함) — status: pending/paid/preparing/shipped/delivered/cancelled/expired/refund_requested/refunded |
| `order_items` | 주문 상품 (상품명·단가 스냅샷) |
| `shipping_addresses` | 회원 배송지 이력 (주소록, is_default 지원) |
| `payments` | 결제 기록 (pg_tid UNIQUE) |

#### 스케줄러 설정
```
# crontab -e
* * * * * cd /path/to/shop && php spark schedule:run >> /dev/null 2>&1
```
- `App\Commands\ExpireOrders` — `orders:expire` 커맨드
- `App\Config\Scheduler` — 5분 주기 등록

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Database/Migrations/2026-06-10-000005_CreateOrderTables.php` | orders · order_items · shipping_addresses · payments 테이블 |
| `app/Models/OrderModel.php` | 주문 모델 (createPending / confirmPaid / cancelOrder / expirePending) |
| `app/Models/ShippingAddressModel.php` | 배송지 이력 모델 |
| `app/Libraries/PG/PGInterface.php` | PG 어댑터 인터페이스 |
| `app/Libraries/PG/TossPaymentsAdapter.php` | 토스페이먼츠 어댑터 |
| `app/Libraries/PG/InicisAdapter.php` | KG이니시스 어댑터 |
| `app/Libraries/PG/NicePayAdapter.php` | 나이스페이 어댑터 |
| `app/Libraries/PG/KakaoPayAdapter.php` | 카카오페이 어댑터 |
| `app/Libraries/PG/NaverPayAdapter.php` | 네이버페이 어댑터 |
| `app/Libraries/PG/PaycoAdapter.php` | PAYCO 어댑터 |
| `app/Libraries/PG/PGFactory.php` | PG 팩토리 (provider 문자열 → 어댑터 인스턴스) |
| `app/Controllers/Front/OrderController.php` | 주문서 · 주문 생성 · 완료/실패 · 취소 |
| `app/Controllers/Front/PaymentController.php` | PG 리다이렉트 콜백 처리 · 금액 검증 · 재고 차감 · 이중처리 방지 |
| `app/Config/Scheduler.php` | CI4 스케줄러 설정 (5분 주기 expire) |
| `app/Commands/ExpireOrders.php` | `php spark orders:expire` 커맨드 |
| `app/Views/shop/checkout.php` | 주문서 뷰 |
| `app/Views/shop/order_complete.php` | 주문 완료 뷰 |
| `app/Views/shop/order_fail.php` | 결제 실패 뷰 |

---

### 4-4. 무통장입금 결제 ✅ 완료

#### 결제 흐름
```
장바구니 → POST /order/create
  ↓ pg_provider = bank_transfer
주문 status: awaiting_payment, payments.status: pending 생성
  ↓
GET /order/bank_transfer/{orderNumber} (입금 안내 페이지)
  ↓ 관리자 확인
POST /admin/orders/{id}/bank_confirm → status: paid + 재고 차감
```

#### 주요 구현
- `BankTransferAdapter`: PG 콜백 없음, `buildPaymentParams()` → 입금 안내 URL 반환
- `orders.status` ENUM에 `awaiting_payment` 추가 (마이그레이션)
- `payments.pg_provider` ENUM에 `bank_transfer` 추가
- `payments.pg_tid` NULL 허용 (UNIQUE KEY는 NULL 복수 허용)
- 관리자 입금 확인 시 `SELECT FOR UPDATE` 재고 차감

#### 트러블슈팅
| 항목 | 원인 | 해결 |
|---|---|---|
| "유효하지 않은 주문" | ENUM에 awaiting_payment·bank_transfer·pending 미존재 | `2026-06-10-000008_AlterOrderEnums.php` ENUM 확장 |
| `duplicate entry '' for key 'pg_tid'` | `pg_tid = ''` 빈 문자열이 UNIQUE 충돌 | `pg_tid = null` 로 변경 |

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Libraries/PG/BankTransferAdapter.php` | 무통장 어댑터 |
| `app/Database/Migrations/2026-06-10-000007_SeedBankTransferSettings.php` | bank_name·bank_account·bank_holder 설정 시드 |
| `app/Database/Migrations/2026-06-10-000008_AlterOrderEnums.php` | orders·payments ENUM 확장 |
| `app/Views/shop/bank_transfer.php` | 입금 안내 페이지 (계좌 복사 버튼) |
| `app/Views/shop/checkout.php` | 무통장 선택 시 계좌 정보 패널 표시 |

---

### 4-5. 재고 관리 (관리자) ✅ 완료

#### 주요 기능
- 요약 카드: 전체 상품 수 / 품절 수 / 부족(10개 이하) 수
- 상품 목록: 키워드 검색 + 전체/품절/부족 필터 + 페이징
- 재고 조정 모달: in(입고)/out(출고)/adjust(직접 설정) 타입, 변경 후 수량 미리보기, 메모 입력
- 조정 이력 모달: 상품별 재고 변경 로그 (타입·수량·변경 전후·메모·관리자명)

#### DB 스키마
| 테이블 | 설명 |
|---|---|
| `stock_logs` | id, product_id, type ENUM(adjust/order/cancel/return/in/out), quantity, stock_before, stock_after, note, admin_id, created_at |

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Database/Migrations/2026-06-10-000006_CreateStockLogTable.php` | stock_logs 테이블 |
| `app/Models/StockLogModel.php` | `record()` · `getByProduct()` |
| `app/Controllers/Admin/InventoryController.php` | 재고 목록·조정·로그 API |
| `app/Views/admin/inventory/index.php` | 재고 관리 뷰 |

---

### 4-6. 마이페이지 (주문 이력) ✅ 완료

#### 주요 기능
- 주문 목록: 기간 필터(1개월/3개월/전체) + 상태 탭 + 키워드 검색(상품명·설명·가격) + 페이징
- 주문 상세:
  - 상품 썸네일 + 제목 링크(slug 기반 `/shop/{slug}`)
  - 운송장 번호 복사 버튼
  - 무통장 입금일 경우 은행명·계좌번호·예금주·입금 금액 표시, 계좌 복사 버튼
  - 배송 완료 확인 버튼 (shipped → delivered)
- 즉시 취소: pending/awaiting_payment/paid 주문 취소
- 내비바: 닉네임 드롭다운 → 주문내역 / 내 정보 / 로그아웃

#### CI4 Model 클론 버그 (핵심 트러블슈팅)
`$this->where()` 체이닝은 `$this`(Model 객체)를 반환. `clone $model`은 내부 `$db` 커넥션 객체를 공유하는 얕은 복사 → `countAllResults()`가 빌더 상태를 리셋해 후속 `findAll()` 결과 오염.
**해결**: `$this->db->table('테이블명')`으로 DB Builder 직접 생성 → PHP clone이 배열 프로퍼티를 copy-on-write로 처리해 안전.

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Controllers/Front/MyPageController.php` | 주문 목록·상세·배송완료확인·취소 |
| `app/Views/shop/orders/list.php` | 주문 목록 뷰 (검색창·페이징) |
| `app/Views/shop/orders/detail.php` | 주문 상세 뷰 |
| `app/Views/themes/default/components/navbar.php` | 마이페이지 드롭다운 |

---

### 4-7. 주문 관리 (관리자) ✅ 완료

#### 주요 기능
- 주문 목록: 상태 필터 + 키워드(주문번호/수취인명/이메일) 검색 + 결제수단 컬럼 + 페이징
- 상태 변경: paid → preparing → shipped → delivered (단방향 흐름)
- 환불 요청 → 환불 완료 처리 (PG 콘솔 취소 후 상태 변경)
- 송장번호·택배사 입력
- 강제 취소: pending/awaiting_payment/paid/preparing 주문 취소 + 재고 자동 복구
- 무통장 입금 확인: awaiting_payment → paid + 재고 차감

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Controllers/Admin/OrderController.php` | 주문 목록·상세·상태변경·송장·취소·환불·무통장확인 |
| `app/Views/admin/orders/list.php` | 관리자 주문 목록 뷰 (결제수단 컬럼) |
| `app/Views/admin/orders/detail.php` | 관리자 주문 상세 뷰 |

---

### 4-8. 매출 관리 (관리자) ✅ 완료

#### 주요 기능
- 요약 카드: 조회 기간 내 총 매출 / 총 주문 수 / 평균 주문 금액
- 기간별 집계: 일별 / 주별 / 월별 전환, 날짜 범위 직접 입력, 빠른 기간 버튼(이번달·지난달·최근7일·최근30일·올해)
- 결제수단별 집계: pg_provider + method GROUP BY, 비율 프로그레스 바
- 검색: 주문번호·수취인명·회원명·이메일 키워드 필터
- 결제 완료 주문 목록: 최근 50건 (검색 조건 적용), 주문 상세 링크

#### 집계 기준
- 현재 총 매출은 `paid/preparing/shipped/delivered/refund_requested/refunded` 주문의 `total_amount` 합계 기준
- `refunded` 주문도 포함되는 gross 매출 기준이므로, 순매출(net) 기준이 필요하면 환불 차감/제외 지표 추가 필요

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Controllers/Admin/SalesController.php` | 매출 집계 (기간별·결제수단별·요약) |
| `app/Views/admin/sales/index.php` | 매출 관리 뷰 |
| `app/Views/layouts/admin.php` | 사이드바 "매출 관리" 메뉴 추가 |

---

### 4-9. 쿠폰 · 포인트 ✅ 완료 (이슈 #11)

- 쿠폰 테이블 설계 (발급·사용·만료·조건 할인)
- 포인트 적립/차감 이력 테이블
- 주문서 쿠폰·포인트 적용 UI
- 관리자 쿠폰 발급 화면

---

### 4-10. 배송지 주소록 ✅ 완료

#### 주요 기능
- 배송지 주소록 관리: 추가/삭제/기본 배송지 설정
- 주문서에서 저장된 배송지 선택 및 기본 배송지 자동 입력
- 주문서에서 "배송지 저장" 선택 시 주소록 저장
- 첫 번째 배송지는 자동으로 기본 배송지 설정
- 동일 주소(zipcode + address1 + address2)는 중복 생성 대신 수취인/연락처 갱신

#### 구현 파일
| 파일 | 설명 |
|---|---|
| `app/Models/ShippingAddressModel.php` | 배송지 목록·기본 배송지·저장/삭제 |
| `app/Controllers/Front/MyPageController.php` | 배송지 관리 화면·저장·기본 설정·삭제 |
| `app/Controllers/Front/OrderController.php` | 주문서 기본 배송지/저장 배송지 연동 |
| `app/Views/shop/addresses/index.php` | 마이페이지 배송지 관리 뷰 |
| `app/Views/shop/checkout.php` | 주문서 저장 배송지 선택 UI |
| `app/Config/Routes.php` | `/mypage/addresses` 라우트 |

---

## 5. 라이센스 관리 📋 예정

### 개요

배포된 쇼핑몰 인스턴스의 정품 사용 여부를 외부 라이센스 서버와 연동해 검증한다.  
라이센스 키는 관리자가 최초 1회 입력 후 수정 불가로 고정되며, 어드민 로그인 시마다 서버 검증을 거친다.

### 기능 요구사항

#### 5-1. 라이센스 키 등록 (관리자 설정)
- 어드민 설정 화면에 "라이센스 키" 입력란 추가
- 저장 후에는 입력란을 마스킹 처리(\*\*\*\*\*\*\*\*)하고 수정 불가 상태로 전환
- 키 미등록 상태에서는 어드민 접근 시 라이센스 등록 안내 페이지로 리다이렉트
- 등록된 키는 `settings` 테이블의 `license_key` 항목에 저장 (캐시 포함)

#### 5-2. 어드민 로그인 시 라이센스 검증
- 로그인 성공 직후 라이센스 서버 API 호출
- 검증 실패(키 무효·만료·서버 오류) 시 로그인 차단 및 오류 메시지 표시
- API 응답 타임아웃(예: 5초) 초과 시 처리 방침 결정 필요 (허용 또는 차단)
- 검증 결과(유효 여부, 만료일)를 세션에 캐싱 — 매 요청마다 API 호출하지 않음

#### 5-3. 라이센스 서버 API 연동
- API 엔드포인트 URL은 `.env`의 `LICENSE_API_URL` 환경변수에서 읽기
- 요청 방식: POST, Body: `{ "license_key": "...", "domain": "..." }`
- 응답 예시: `{ "valid": true, "expires_at": "2027-01-01" }`
- API 키(서명 시크릿 등)가 필요한 경우 `LICENSE_API_SECRET`으로 추가

### 정책 결정 필요 항목

| 항목 | 선택지 |
|------|--------|
| API 타임아웃 시 처리 | 허용(관대) vs 차단(엄격) |
| 검증 캐싱 유효 시간 | 1시간 / 1일 / 세션 종료까지 |
| 라이센스 만료 시 처리 | 로그인 차단 vs 경고 배너만 표시 |
| 도메인 바인딩 여부 | 서버 도메인과 등록 도메인 일치 여부 확인 |

### 구현 계획 파일

| 파일 | 설명 |
|------|------|
| `app/Libraries/LicenseService.php` | 라이센스 서버 API 호출·검증 로직 |
| `app/Filters/LicenseFilter.php` | 어드민 전체 라우트에 적용되는 라이센스 유효성 필터 |
| `app/Controllers/Admin/AuthController.php` | 로그인 성공 후 `LicenseService::verify()` 호출 |
| `app/Controllers/Admin/SettingController.php` | 라이센스 키 저장 (최초 1회, 이후 수정 불가 처리) |
| `app/Views/admin/settings/index.php` | 라이센스 키 입력란 추가 (등록 후 마스킹·읽기전용) |
| `app/Views/admin/license_required.php` | 라이센스 미등록 시 안내 페이지 |
| `.env` | `LICENSE_API_URL`, `LICENSE_API_SECRET` 환경변수 추가 |

### 환경변수 예시 (`.env`)

```
LICENSE_API_URL = https://license.example.com/api/verify
LICENSE_API_SECRET = your-hmac-secret
```
