---
name: AICopia
description: 어느 클라이언트 브랜드로도 갈아입힐 수 있는 중립 화이트라벨 쇼핑몰 기반 — Bootstrap 5.3 위의 얇은 오버레이
colors:
  primary: "#0d6efd"
  ink: "#333333"
  dark: "#1e2a38"
  success: "#198754"
  danger: "#dc3545"
  warning: "#ffc107"
  secondary: "#6c757d"
  muted: "#6c757d"
  badge-bronze: "#a0522d"
  badge-silver: "#888f94"
  surface: "#ffffff"
  subtle-bg: "#f8f9fa"
  border: "#f0f0f0"
  soldout-scrim: "rgba(0, 0, 0, 0.4)"
typography:
  display:
    fontFamily: "Noto Sans KR, -apple-system, sans-serif"
    fontSize: "1.3rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "normal"
  title:
    fontFamily: "Noto Sans KR, -apple-system, sans-serif"
    fontSize: "1.1rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "normal"
  body:
    fontFamily: "Noto Sans KR, -apple-system, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
  label:
    fontFamily: "Noto Sans KR, -apple-system, sans-serif"
    fontSize: "0.8rem"
    fontWeight: 500
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  card: "0.75rem"
  popup: "8px"
  pill: "50rem"
spacing:
  xs: "0.25rem"
  sm: "0.5rem"
  md: "1rem"
  lg: "1.5rem"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    padding: "0.375rem 0.75rem"
  button-outline-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.secondary}"
    padding: "0.375rem 0.75rem"
  product-card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.card}"
  badge-grade-bronze:
    backgroundColor: "{colors.badge-bronze}"
    textColor: "{colors.surface}"
    rounded: "{rounded.pill}"
    padding: "0.35em 0.65em"
---

# Design System: AICopia

## Overview

**Creative North Star: "The Neutral Canvas"**

AICopia의 기본 테마는 자기 목소리를 일부러 낮춘다. 이것은 완성된 브랜드가 아니라, 어느 클라이언트의 브랜드로도 갈아입힐 수 있도록 준비된 **중립 캔버스**다. 시각적 개성은 납품 시 파생 테마(dark·spring 등)와 관리자 `settings`(로고·사이트명·색·연락처)를 통해 주입되고, 이 기본 레이어의 역할은 그 주입이 깔끔하게 얹히는 견고하고 예측 가능한 토대가 되는 것이다. 중립성은 미완성이 아니라 **설계된 선택**이다.

구현은 Bootstrap 5.3 위에 236줄의 얇은 오버레이(`public/themes/default/css/style.css`)를 얹은 형태다. 팔레트·간격·컴포넌트 어휘는 대부분 Bootstrap의 시맨틱 시스템을 그대로 채택하고, 오버레이는 상품 카드 hover, 팝업 레이어, 등급 배지, 마이페이지 반응형처럼 프레임워크가 답하지 않는 지점만 손본다. 표면은 기본적으로 평면이고, 색은 장식이 아니라 의미(성공=녹, 위험=빨)로만 등장한다. 결과는 쇼핑객에게 익숙하고 즉시 읽히는, 마찰 없는 커머스 표면이다.

이 시스템은 "표현적인 브랜드 사이트"가 되기를 명시적으로 거부한다. 스토어프론트의 성공 척도는 탐색→상세→장바구니→결제 흐름의 명료함과 전환이지, 시각적 인상이 아니다. 개성은 코어가 아니라 테마 레이어의 몫이다.

**Key Characteristics:**
- Bootstrap 5.3 시맨틱 어휘를 기본값으로 채택, 커스텀은 최소
- 의도적으로 목소리를 낮춘 중립 기반 — 브랜드는 파생 테마·settings에서 주입
- 색은 의미로만: 시맨틱 팔레트 중심(`text-muted` 압도적 다수)
- 평면 우선(Flat-by-default), 그림자는 상태 반응(hover·팝업)에만
- Noto Sans KR 본문, 작고 촘촘한 타입 스케일
- 로고·사이트명·메뉴·색은 런타임 주입 — 하드코딩 없음

## Colors

팔레트는 Bootstrap 5.3 시맨틱 시스템을 그대로 채택하고, 브랜드 색은 비워 둔 중립 구성이다. 화면 위 색의 압도적 다수는 흑백 계열(잉크·뮤트·중립 배경)이고, 채도 있는 색은 의미를 전달할 때만 나타난다.

### Primary
- **Signal Blue** (`#0d6efd`): Bootstrap 기본 블루와 동일한 링크·기본 액션 색(`--primary`). 주 CTA(장바구니 담기·주문·검색)와 링크에만 쓴다. 브랜드 색이 아니라 "기본 액션" 신호이며, 납품 시 클라이언트 색으로 retint되는 자리다.

### Neutral
- **Ink** (`#333333`): 본문 기본 텍스트색(`body { color:#333 }`). Bootstrap 기본(#212529)보다 살짝 부드럽다.
- **Slate Dark** (`#1e2a38`): 진한 표면·강조 텍스트용 커스텀 다크(`--dark`). 푸터 등 어두운 영역.
- **Surface** (`#ffffff`): 카드·팝업·기본 배경.
- **Subtle Background** (`#f8f9fa`): 표 헤더(`.board-table th`)·구획 배경 등 은은한 면.
- **Hairline Border** (`#f0f0f0`): 네비 하단선·팝업 푸터 구분선 등 얇은 경계.
- **Muted Text** (`#6c757d`): 보조 정보·메타(`text-muted`) — 화면에서 가장 자주 쓰이는 텍스트 톤.

### Semantic (Bootstrap 값 그대로)
- **Success Green** (`#198754`): 재고 있음·무료배송·완료 상태.
- **Danger Red** (`#dc3545`): 할인율 배지·품절 경고·삭제·오류.
- **Warning Amber** (`#ffc107`): 주의·대기 상태.
- **Secondary Gray** (`#6c757d`): 보조 버튼(`btn-outline-secondary`)·중립 배지.

### Accent (등급 배지 — 유일한 장식성 색)
- **Bronze** (`#a0522d`) / **Silver** (`#888f94`): 회원 등급 배지 전용(`.badge-bronze`·`.badge-silver`). 시스템에서 시맨틱 규칙의 유일한 예외이며, 등급이라는 특정 도메인에만 국한된다.

### Named Rules
**The Semantic-Color Rule.** 채도 있는 색은 의미를 전달할 때만 쓴다 — 녹=성공/가능, 빨=위험/오류/할인, 파랑=기본 액션. 장식 목적으로 색을 얹지 않는다. 화면 대부분은 잉크·뮤트·중립 배경으로 말한다.

**The Empty-Brand Rule.** 기본 테마는 브랜드 색을 갖지 않는다(Primary는 "액션 신호"이지 브랜드가 아니다). 브랜드 색은 파생 테마와 `settings`에서 주입되며, default를 특정 브랜드 색으로 물들이지 않는다.

## Typography

**Display / Body Font:** Noto Sans KR (fallback: -apple-system, sans-serif)
**Label Font:** 동일 (별도 서체 없음)

**Character:** 단일 산세리프 하나로 전 화면을 운용하는 실용적 타이포. 한글 가독성을 위해 Noto Sans KR를 1순위로 두고, 미설치 환경에서는 OS 시스템 폰트로 폴백한다. 표현적 대비보다 **읽기 편한 균일함**이 목적이다.

> ⚠️ **실측 사실:** 현재 레이아웃(`layouts/main.php`)에 Noto Sans KR 웹폰트 링크가 없다 — `font-family`로 선언만 되어 있어, 사용자 OS에 해당 폰트가 없으면 시스템 산세리프로 폴백한다. 타이포 일관성이 중요해지면 웹폰트 링크 추가가 다음 개선점이다(현 상태를 사실대로 기록).

### Hierarchy
- **Display / Brand** (700, 1.3rem): 사이트명 텍스트 로고(`.navbar-brand`). 로고 이미지가 있으면 이미지가 대신한다(높이 40px).
- **Title** (600, ~1.1rem): 섹션 제목·상품명 강조.
- **Body** (400, 1rem, line-height 1.6): 본문 기본. 게시글 본문은 line-height 1.8(`.post-content`)로 더 넉넉하다.
- **Label / Meta** (500, 0.8~0.9rem): 내비 링크(.9rem)·표 셀(.875rem)·배지(.8rem). 전반적으로 작고 촘촘한 스케일.

### Named Rules
**The One-Typeface Rule.** 서체는 Noto Sans KR 하나로 통일한다. 위계는 서체 교체가 아니라 굵기(400/500/600/700)와 크기로만 만든다.

## Layout

Bootstrap의 `.container`·플렉스 그리드·반응형 유틸리티를 그대로 쓴다. 상단 고정 네비 아래, 선택적 좌측 배너 사이드바(`.sp-banner-slot`, 데스크톱 전용)와 `flex-grow-1`의 본문 `<main>`을 나란히 두는 2단 구조다.

- **본문 폭:** Bootstrap 컨테이너 기준. 히어로는 `min-height:70vh`, 메인 배너는 `max-width:1000px` 중앙 정렬.
- **스크롤바 안정화:** `html { scrollbar-gutter: stable }` — 콘텐츠 길이에 따라 스크롤바가 생겼다 사라지며 중앙 정렬 본문이 좌우로 흔들리는 현상을 막는다.
- **플렉스 오버플로 가드:** 본문 `<main>`에 `.min-w-0`(=`min-width:0`)를 준다. 긴 상품명·주문번호가 `min-width:auto` 플렉스 아이템을 밀어 페이지 전체가 가로로 넘치는 것을 막는 필수 장치.
- **반응형 사이드 메뉴:** 마이페이지 좌측 메뉴는 데스크톱(≥992px) sticky 세로 목록, 모바일(<992px)에서는 아이콘 위·라벨 아래로 쌓은 3열 그리드로 접힌다. (가로 스크롤 탭 금지 — 페이지 가로 넘침 유발.)
- **긴 텍스트:** 상품명은 `.text-clamp-2`로 2줄 말줄임. 에디터 표는 `.page-content`/`.post-content`의 `overflow-x:auto`로 본문 안에서만 스크롤.

## Elevation & Depth

이 시스템은 **평면 우선**이다. 표면은 기본적으로 그림자가 없고, 깊이는 상태 변화에 대한 반응으로만 나타난다. 톤 레이어링(중립 배경 `#f8f9fa` vs 흰 표면)과 얇은 경계선(`#f0f0f0`)이 정적 구획을 담당한다.

### Shadow Vocabulary
- **Card Hover Lift** (`box-shadow: 0 4px 12px rgba(0,0,0,.1)`): 상품 카드에 마우스를 올렸을 때만(`.product-card:hover`). 정지 상태 카드는 그림자 없음.
- **Popup Float** (`box-shadow: 0 8px 32px rgba(0,0,0,.22)`): 사이트 팝업 레이어(`.site-popup`)를 배경에서 강하게 띄운다 — 유일하게 정적으로 강한 그림자.

### Named Rules
**The Flat-By-Default Rule.** 표면은 정지 상태에서 평면이다. 그림자는 상태(hover)나 오버레이(팝업)의 응답으로만 등장한다. 카드·패널에 장식용 상시 그림자를 얹지 않는다. 정적 구획은 배경 톤과 헤어라인으로 만든다.

## Shapes

부드럽지만 절제된 코너 언어. 과한 라운딩도, 날카로운 직각도 아닌 중간값이다.

- **카드:** `border-radius: .75rem`(Bootstrap 기본보다 살짝 크게 오버라이드, `--bs-card-border-radius`). 상품·정보 카드의 기본 형태.
- **팝업:** `8px` 라운딩, 상단 이미지는 상단 두 모서리만 라운딩(`8px 8px 0 0`).
- **배지·태그·필터 칩:** `rounded-pill`(50rem) — 등급 배지, 검색어 태그, 상태 배지.
- **아바타·아이콘 버튼:** `rounded-circle`.
- **경계:** 색면 분리보다 얇은 경계선을 선호(`1px solid #f0f0f0`). Bootstrap Icons(1.11.0)를 아이콘 시스템으로 사용.

## Components

각 컴포넌트는 Bootstrap 변형을 기본으로 하고, 오버레이가 손댄 부분만 커스텀이다.

### Buttons
- **Shape:** Bootstrap 기본 라운딩(`0.375rem`). 별도 오버라이드 없음.
- **Primary:** `btn btn-primary` — Signal Blue 배경(`#0d6efd`)·흰 텍스트. 주 액션(장바구니·주문·검색). 목록에서 `flex-fill`/`w-100`으로 폭을 채워 쓰는 패턴이 흔하다.
- **Outline Secondary:** `btn btn-outline-secondary` — 흰 배경·회색 텍스트·회색 테두리. 스토어프론트에서 **가장 많이 쓰는 보조 버튼**(취소·닫기·부가 액션). 작게는 `btn-sm`.
- **Semantic:** `btn-success`(확정·완료), `btn-dark`(강조 액션), `btn-outline-danger`(삭제) 등 의미에 맞는 Bootstrap 변형을 그대로.
- **Hover / Focus:** Bootstrap 기본 상태 스타일(배경 톤 시프트 + 포커스 링)을 그대로 사용.

### Product Card
- **Markup:** `<div class="card h-100 product-card">` — 카드 안에 이미지·상품명(`.text-clamp-2`)·가격·배지.
- **Corner:** `.75rem`. **Background:** 흰 표면. **Border:** Bootstrap 기본 카드 테두리.
- **Shadow Strategy:** 정지 시 없음 → hover 시 `0 4px 12px rgba(0,0,0,.1)` 리프트(Elevation 참조).
- **Overlay 배지:** 할인율 `bg-danger`(우상단 절대배치), 품절 `bg-dark`, 무료배송 `bg-light text-success border border-success`.
- **품절 스크림:** `.product-soldout-scrim`(`Soldout Scrim`, `rgba(0,0,0,0.4)`) — 이미지 전체를 덮는 반투명 오버레이 + 중앙 "품절" 배지. 목록·추천 카드가 공유하는 단일 클래스(인라인 색 중복·`inset-0` 미정의 버그 제거).

### Grade Badge (signature)
- 회원 등급 표시 전용 pill 배지. `.badge-bronze`(#a0522d)·`.badge-silver`(#888f94) — 시스템에서 유일하게 시맨틱 규칙을 벗어난 장식성 색이며 등급 도메인에만 쓴다.

### Popups
- `.site-popup` — `position:fixed`, `z-index:9999`, 흰 배경, `8px` 라운딩, `Popup Float` 그림자, `max-width: min(380px, calc(100vw - 40px))`. 상단 이미지 + 본문 + "오늘 그만 보기" 푸터 체크. 닫기 버튼은 우상단 무테 아이콘 버튼(`.site-popup-close`, hover 시 #333).

### Navigation
- `.navbar` — 하단 헤어라인(`1px solid #f0f0f0`), `position:relative; z-index:1030`(Bootstrap 고정 네비 계층과 동일, 결제 페이지 sticky 요약 카드 위로 드롭다운이 덮이도록).
- **Brand:** `settings['site_logo']`가 있으면 로고 이미지(높이 40px), 없으면 사이트명 텍스트(`.navbar-brand.fw-bold`, 700/1.3rem). — 브랜드는 항상 런타임 주입.
- **Links:** `.nav-link` 500/0.9rem.

### MyPage Sidebar
- 데스크톱: `position:sticky; top:1rem` 세로 `list-group`.
- 모바일(<992px): `card-header` 숨김 + 3열 그리드, 아이콘 위·라벨 아래 스택, 셀 사이 세로 구분선(3n마다 제거).

## Do's and Don'ts

### Do:
- **Do** Bootstrap 5.3 시맨틱 클래스·컴포넌트를 기본값으로 쓴다. 커스텀은 프레임워크가 답하지 않는 지점(카드 hover·팝업·등급 배지·반응형 사이드바)에만 최소로 추가한다.
- **Do** 색을 의미로만 쓴다(`The Semantic-Color Rule`). 녹=성공, 빨=위험/할인, 파랑=기본 액션. 나머지는 잉크·뮤트·중립 배경.
- **Do** 표면을 평면으로 유지하고, 그림자는 hover·오버레이 상태에만 얹는다(`The Flat-By-Default Rule`).
- **Do** 로고·사이트명·색·연락처를 `settings`/DB에서 주입받는 전제로 만든다. 값이 비었을 때(빈 상태)도 레이아웃이 무너지지 않게 한다.
- **Do** 카드 `.75rem`·배지 pill·헤어라인 경계의 형태 언어를 지킨다.
- **Do** 플렉스 본문에 `.min-w-0`, 긴 상품명에 `.text-clamp-2`를 적용해 가로 넘침을 막는다.

### Don't:
- **Don't** 기본 테마를 특정 클라이언트 브랜드 색으로 물들이지 않는다(`The Empty-Brand Rule`). retint·브랜드 표현은 **파생 테마(dark/spring 등)와 `settings`**에서 한다. default는 재색칠 가능한 중립 캔버스로 남긴다.
- **Don't** 장식 목적의 색·상시 그림자·그라디언트를 기본 테마에 추가하지 않는다. 중립성이 이 레이어의 가치다.
- **Don't** 서체를 여러 개 섞지 않는다(`The One-Typeface Rule`). 위계는 굵기·크기로만.
- **Don't** 코어 뷰(`app/Views/shop/**`)의 구조를 특정 납품처에 맞춰 하드코딩하지 않는다 — 표현 차이는 테마 오버라이드로.
- **Don't** 모바일에서 가로 스크롤 탭(nowrap + overflow-x) 메뉴를 만들지 않는다 — 페이지 전체 가로 넘침을 유발한다(마이페이지 사이드바 주석 참조).
