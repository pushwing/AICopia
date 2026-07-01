# AICopia SEO + GEO 전략

> 검색엔진(SEO)과 생성형 엔진(GEO, Generative Engine Optimization)을 함께 겨냥한 유입 전략 문서입니다.
> AICopia는 CodeIgniter 4 기반 쇼핑몰 + 기업형 사이트 + AI 운영 보조 레이어를 갖춘 단일 솔루션이며, 이 문서는 그 자산 위에서 **검색·AI 답변 양쪽 노출**을 극대화하는 실행 계획을 정의합니다.

---

## 0. 용어 정리

| 용어 | 정의 | AICopia에서의 목표 |
|------|------|--------------------|
| **SEO** | 구글·네이버·다음 등 전통 검색엔진의 검색결과(SERP) 상위 노출 | 상품/카테고리/게시판/페이지의 오가닉 트래픽 확보 |
| **GEO** | ChatGPT·Claude·Perplexity·Gemini·네이버 Cue 등 **생성형 엔진의 답변에 인용·추천**되도록 최적화 | "○○ 추천해줘" 류 질의에서 브랜드·상품이 답변에 등장 |
| **AEO** | Answer Engine Optimization — 발췌형 답변(피처드 스니펫, AI Overviews) 최적화. GEO의 부분집합으로 취급 | FAQ/Q&A/리뷰요약을 답변 소스로 제공 |

핵심 통찰: **SEO와 GEO는 경쟁이 아니라 공유 인프라 위에 선다.** 크롤러가 읽을 수 있는 깨끗한 HTML, 구조화 데이터(Schema.org), 명확한 엔티티 정의, 신뢰 신호(리뷰·저자·업데이트일)는 두 채널 모두에 동일하게 작동한다. 따라서 본 전략은 **공통 토대 → SEO 특화 → GEO 특화** 순으로 쌓는다.

---

## 1. 현황 진단 (AICopia 코드베이스 기준)

### 이미 갖춘 자산 ✅

| 자산 | 위치 | 설명 |
|------|------|------|
| 메타/OG 태그 생성기 | `app/Libraries/SeoHelper.php` | `<title>`, `description`, `og:*`, 네이버 웹마스터 인증(`naver-site-verification`), GA 스크립트 |
| 페이지별 메타 필드 | `pages.meta_title`, `pages.meta_desc`, `pages.og_image` | 동적 페이지 단위 메타 오버라이드 |
| AI 콘텐츠 생성 | `AiProviderInterface::generateDescription()` | 상품 설명 자동 생성 |
| AI 리뷰 요약 | `summarizeReviews()` (`ReviewSummaryHandler`) | 답변형 콘텐츠 소스 |
| 시맨틱 검색 | `SemanticSearchService`, `expandSearchQuery()` | 내부 검색 품질(간접적 UX·체류시간 기여) |
| 추천 | `RecommendationService` | 내부 링크·회유 동선 |
| 테마 렌더러 | `ThemeView` | `<head>` 삽입 지점을 한 곳에서 통제 가능 |

### 공백 ❌ (본 전략이 메우는 대상)

| 공백 | 영향 | 우선순위 |
|------|------|----------|
| **`sitemap.xml` 없음** | 크롤러가 상품/페이지 발견 지연 | 🔴 P0 |
| **`robots.txt` 없음** | 크롤 예산 낭비, AI 크롤러 정책 부재 | 🔴 P0 |
| **구조화 데이터(JSON-LD) 없음** | 리치 결과·AI 인용 근거 부재 | 🔴 P0 |
| **canonical 태그 없음** | 중복 URL(정렬·필터 파라미터) 색인 분산 | 🟠 P1 |
| **`og:type=website` 고정** | 상품은 `product`, 글은 `article`이어야 함 | 🟠 P1 |
| **트위터 카드 없음** | SNS 공유 미리보기 빈약 | 🟡 P2 |
| **FAQ/Q&A 구조화 없음** | AI 답변·피처드 스니펫 기회 상실 | 🟠 P1 |
| **RSS/피드 없음** | 게시판 콘텐츠 배포·발견성 저하 | 🟡 P2 |

---

## 2. 공통 토대 (SEO·GEO 공유 인프라)

두 채널의 80%는 여기서 결정된다. **P0으로 최우선 구현.**

### 2.1 크롤러블 HTML & 렌더링

- **원칙: 콘텐츠는 서버사이드 렌더링(SSR)된 HTML에 존재해야 한다.** AICopia는 CI4 서버 렌더링이므로 유리 — JS로만 그려지는 핵심 콘텐츠를 만들지 말 것.
- GEO 크롤러(ChatGPT-User, PerplexityBot, ClaudeBot 등) 상당수는 **JS를 실행하지 않거나 제한적**이다. 상품명·가격·설명·리뷰는 반드시 초기 HTML에 포함.
- 무한 스크롤/AJAX 목록은 **페이지네이션 링크(`rel="next/prev"` 또는 `?page=`)를 HTML에 병행 제공**.

### 2.2 구조화 데이터 (Schema.org JSON-LD) — 최우선

JSON-LD는 SEO 리치결과와 GEO 인용의 **공통 화폐**다. `SeoHelper`에 타입별 메서드를 추가하고 `<head>`에 주입한다.

| 페이지 | Schema 타입 | 필수 필드 |
|--------|-------------|-----------|
| 상품 상세 | `Product` + `Offer` + `AggregateRating` | name, image, description, sku, brand, offers(price, priceCurrency=KRW, availability), aggregateRating(ratingValue, reviewCount) |
| 상품 리뷰 | `Review` | author, reviewRating, reviewBody, datePublished |
| 카테고리/목록 | `ItemList` / `BreadcrumbList` | itemListElement |
| 게시판 글 | `Article` / `BlogPosting` | headline, author, datePublished, dateModified, image |
| 상품 Q&A | `FAQPage` (`Question`/`Answer`) | 질문·채택답변 |
| 사이트 전역 | `Organization` + `WebSite`(+`SearchAction`) | name, logo, url, sameAs(SNS), potentialAction(사이트 내 검색) |
| 문의/연락 | `LocalBusiness`(해당 시) | 사업자명, 주소, 전화, 영업시간 |

> **재고·가격 연동 주의:** `Offer.availability`는 `products.stock`·`status`와 실시간 일치해야 한다(불일치 시 구글 페널티·AI 오답). 재고는 PG 성공 콜백 시점에만 차감(기존 정책)되므로 JSON-LD도 동일 소스에서 렌더.

### 2.3 URL·정규화

- **canonical 태그 전 페이지 삽입** — 정렬(`?sort=`)·필터·페이지 파라미터가 붙은 URL은 대표 URL로 canonical.
- URL은 **의미 있는 slug** 유지(`pages.slug` 활용). 상품도 가능하면 `/shop/product/{id}-{slug}` 형태 권장.
- catch-all 라우트(`(:segment)` → `PageController::show`)는 Routes.php **맨 마지막** 유지(기존 규칙).

### 2.4 메타데이터 품질

- `<title>`: 55~60자, `핵심키워드 | 브랜드`. `meta_title`이 있으면 우선.
- `meta description`: 120~155자, 검색의도+CTA. 비면 AI(`generateDescription`)로 생성해 `meta_desc`에 캐시.
- **중복 title/description 금지** — 목록 페이지네이션은 제목에 `(N페이지)` 부기.

### 2.5 성능·Core Web Vitals

- LCP < 2.5s, CLS < 0.1, INP < 200ms 목표. 이미지 `width/height` 명시, `loading="lazy"`(단 LCP 이미지는 eager).
- 설정·메뉴·배너·팝업은 이미 파일 캐시(기존 캐싱 전략) — 유지.
- 상품 이미지 WebP 변환(`ImageUploader` 확장) 검토.

---

## 3. SEO 특화 전략

### 3.1 sitemap.xml (P0)

- 동적 생성 라우트 `GET /sitemap.xml` → `SitemapController`. 상품(활성/재고), 카테고리, 페이지, 게시판 글을 `lastmod`와 함께 출력.
- 대량 시 `sitemap_index.xml` + 타입별 분할(상품/게시판/페이지). 5만 URL·50MB 한도 준수.
- 이미지 sitemap 확장(`image:image`)으로 상품 이미지 색인 촉진.
- 생성 부하는 배치(`app/Commands/`)로 정적 파일 생성 후 서빙하거나, 캐시(1시간 TTL) 권장.

### 3.2 robots.txt (P0)

- `GET /robots.txt`. 관리자(`/admin/*`), 장바구니/주문/마이페이지, 검색결과 파라미터 URL은 `Disallow`.
- `Sitemap: https://.../sitemap.xml` 명시.
- **AI 크롤러 정책은 3.6절 GEO에서 별도 결정**(허용/차단 트레이드오프).

### 3.3 네이버·다음 대응 (국내 필수)

- **네이버 서치어드바이저** 등록: `naver-site-verification`은 이미 `SeoHelper`가 지원 → `settings.naver_verify`에 값만 넣으면 됨.
- 네이버 전용 사이트맵 제출 + RSS 제출(게시판).
- **다음(카카오) 검색등록**.
- 네이버는 여전히 **블로그/지식형 콘텐츠·표준 마크업**을 선호 → 게시판을 콘텐츠 허브로 활용.

### 3.4 콘텐츠·정보구조

- **카테고리 페이지 = 랜딩페이지**로 취급: 상단에 카테고리 소개문(200~400자, 키워드 포함), 하단 관련 FAQ.
- 게시판(`boards/posts`)을 **주제 클러스터**로 운영: 대표 가이드(필러) 1개 + 세부 글 다수 → 상호 내부링크.
- 상품 상세: 스펙 표 + AI 리뷰요약 + Q&A를 한 페이지에 → 롱테일 커버리지.

### 3.5 기술 SEO 체크리스트

- [ ] `og:type` 페이지별 분기(product/article/website)
- [ ] 트위터 카드(`summary_large_image`)
- [ ] `hreflang`(다국어 도입 시)
- [ ] 404/410 정상 상태코드, 소프트404 제거
- [ ] 301 리다이렉트(상품 단종 → 대체/카테고리)
- [ ] `BreadcrumbList` + 화면 브레드크럼 일치
- [ ] HTTPS 강제, HSTS

### 3.6 색인 관리

- `noindex`: 장바구니·결제·마이페이지·로그인·검색결과·중복 파라미터 페이지.
- `index`: 상품·카테고리·게시판 글·정적 페이지.

---

## 4. GEO 특화 전략 (생성형 엔진 최적화)

> 목표: 사용자가 AI에게 "가성비 좋은 ○○ 추천", "○○ 브랜드 어때?"라고 물을 때 **AICopia의 상품·브랜드·콘텐츠가 답변에 인용/추천**되게 한다.

### 4.1 GEO는 SEO와 무엇이 다른가

| 관점 | SEO | GEO |
|------|-----|-----|
| 소비 주체 | 사람(링크 클릭) | LLM(답변에 합성·인용) |
| 랭킹 단위 | 페이지 | **문장·사실 단위(청크)** |
| 승리 조건 | 상위 10위 노출 | **답변에 언급·출처로 인용** |
| 핵심 신호 | 백링크·키워드·CWV | **명료한 사실 진술·엔티티·인용 가능성·신뢰도** |
| 측정 | GSC 노출/클릭 | AI 답변 내 브랜드 언급률(별도 추적) |

### 4.2 인용 가능성(citability)을 높이는 콘텐츠 작성

LLM은 **자기완결적이고 사실 밀도가 높은 문장**을 인용한다.

- **한 문장 = 한 사실.** "이 제품은 무게 1.2kg, 배터리 12시간, 방수 IP68입니다." 처럼 수치·단위를 문장에 직접.
- **질문형 헤딩 + 즉답.** `## ○○의 배터리 지속시간은?` → 첫 문장에 결론.
- **표·목록으로 비교 정보 제공** — LLM이 파싱·인용하기 쉽다(스펙표, 사이즈표, 가격비교).
- **정의·엔티티 명시.** 브랜드/제품을 "무엇이며 누구를 위한 것인지" 한 단락으로 정의 → `Organization`/`Product` 스키마와 일치시킴.
- **최신성 신호.** `dateModified` 노출, "2026년 기준" 등 시점 명기 → LLM이 최신 정보로 신뢰.

### 4.3 AI가 근거로 삼는 구조

- **JSON-LD(2.2절)는 GEO에도 핵심** — 가격·평점·재고·FAQ를 기계가 명확히 읽음.
- **FAQPage / Q&A**를 상품·카테고리에 적극 배치. `product_qnas`, `generateQnaAnswer()`를 활용해 자동 초안 → 관리자 검수.
- **리뷰 요약(`summarizeReviews`)을 페이지에 노출** — "장점/단점" 구조화된 요약은 AI가 그대로 인용하기 좋음. 단, 원문 리뷰도 함께 두어 신뢰도 유지.

### 4.4 AI 크롤러 접근 정책 (전략적 결정 필요)

GEO의 전제는 **AI 크롤러가 사이트를 읽을 수 있어야** 한다는 것. `robots.txt`에서 아래를 결정한다.

| 봇 | 용도 | 권장 |
|----|------|------|
| `GPTBot` (OpenAI 학습) | 모델 학습 | 선택 — 브랜드 노출 원하면 Allow |
| `OAI-SearchBot` / `ChatGPT-User` | ChatGPT 검색·브라우징 인용 | **Allow 권장**(실시간 인용 기회) |
| `PerplexityBot` | Perplexity 인용 | **Allow 권장** |
| `ClaudeBot` / `Claude-User` | Claude 검색·인용 | **Allow 권장** |
| `Google-Extended` | Gemini/AI Overviews 학습 | 선택 |

> **트레이드오프:** 전면 차단은 콘텐츠 무단학습을 막지만 **AI 답변 인용 기회도 잃는다.** 커머스는 노출=매출이므로 **검색·인용용 봇은 Allow, 순수 학습봇은 정책에 따라 선택** 조합을 권장. `/admin`·주문·개인정보 경로는 봇 무관 전면 Disallow.

### 4.5 오프사이트 GEO (사이트 밖 신호)

LLM은 자사 사이트만이 아니라 **제3자 언급**을 종합한다.

- **위키·나무위키·리뷰 커뮤니티·비교 사이트**에 정확한 브랜드/제품 정보가 존재하도록 관리.
- **일관된 NAP/엔티티**(브랜드명·설명·로고·SNS `sameAs`)를 모든 채널에서 통일.
- **보도자료·블로그·제휴 리뷰**로 "제3자가 사실을 진술"하게 만들기 → LLM 신뢰 가중.
- 네이버 지식iN·블로그·카페 등 국내 UGC 채널은 네이버 Cue/검색 AI에 직접 영향.

### 4.6 국내 생성형 채널

- **네이버 Cue:, 네이버 통합검색 AI** — 네이버 생태계(블로그·쇼핑·지식iN) 신호 비중이 큼.
- **네이버쇼핑 연동**(`NaverShoppingProvider` 이미 존재) — 상품 피드 정합성이 검색·AI 노출에 직결.
- 카카오·삼성 가우스 등 국내 LLM 확산 대비, 표준 스키마·개방형 크롤링 정책 유지.

---

## 5. 구현 로드맵

### Phase 0 — 토대 (2주, P0)
1. `robots.txt` 라우트 + AI 봇 정책 확정
2. `sitemap.xml`(+index) 동적 생성 + 캐시
3. `SeoHelper`에 JSON-LD 메서드 추가: `Organization`, `WebSite+SearchAction`, `Product`, `BreadcrumbList`
4. canonical 태그 전역 삽입, `og:type` 페이지별 분기
5. 네이버 서치어드바이저·구글 서치콘솔 등록 및 사이트맵 제출

### Phase 1 — 리치·답변 최적화 (2~3주, P1)
6. `AggregateRating`/`Review` 스키마(리뷰 데이터 연동)
7. `FAQPage` 스키마(상품 Q&A·카테고리 FAQ) + `generateQnaAnswer` 초안 파이프라인
8. 리뷰요약·Q&A를 상품 페이지에 SSR 노출
9. 카테고리 랜딩 카피 + FAQ 블록
10. 트위터 카드, `Article` 스키마(게시판)

### Phase 2 — 콘텐츠·오프사이트·측정 (지속)
11. 게시판 주제 클러스터 콘텐츠 운영
12. RSS 피드(게시판) + 네이버 RSS 제출
13. 오프사이트 엔티티 정합성(위키·SNS `sameAs`·네이버쇼핑 피드)
14. GEO 노출 측정 체계 구축(6절)
15. 이미지 WebP·CWV 튜닝

---

## 6. 측정 & KPI

### SEO 지표 (기존 도구)
- Google Search Console: 노출수·클릭·평균순위·색인 커버리지·CWV.
- 네이버 서치어드바이저: 수집/색인 현황.
- 내부 `access_logs` / GA(`SeoHelper::gaScript`): 오가닉 세션·전환.
- 리치결과: 구글 리치결과 테스트 통과율(Product/FAQ/Breadcrumb).

### GEO 지표 (신규 필요)
- **AI 답변 내 브랜드/상품 언급률** — 대표 질의 세트(예: "○○ 추천")를 ChatGPT/Perplexity/Claude/네이버 Cue에 주기적 질의해 언급·인용 여부 로깅(수동 또는 배치).
- **AI 리퍼러 트래픽** — `access_logs`의 referer에서 `chatgpt.com`, `perplexity.ai`, `claude.ai` 등 집계.
- **AI 크롤러 방문량** — 서버 로그에서 `GPTBot`, `PerplexityBot`, `ClaudeBot`, `OAI-SearchBot` UA 빈도 추적(`app/Config/UserAgents.php` 확장).
- **인용 정확도** — AI가 인용한 가격·재고·스펙이 실제와 일치하는지 점검(불일치는 스키마 신선도 문제).

### 목표(예시, 6개월)
| 지표 | 현재 | 목표 |
|------|------|------|
| 색인된 상품 URL 비율 | 미측정 | 95%+ |
| Product 리치결과 유효율 | 0% | 90%+ |
| 오가닉 세션(MoM) | 기준선 | +30% |
| 대표 질의 AI 언급률 | 미측정 | 대표 20질의 중 30%+ |
| AI 리퍼러 세션 | 미측정 | 추적 시작 → 증가 추세 |

---

## 7. 리스크 & 주의사항

- **스키마-실데이터 불일치**: 가격·재고 오류는 구글 수동조치·AI 오답을 부른다. JSON-LD는 항상 DB 실시간 소스에서 렌더.
- **AI 크롤러 전면 차단의 기회비용**: 커머스는 인용=노출=매출. 학습·인용 봇을 구분해 정책 수립.
- **자동생성 콘텐츠 품질**: `generateDescription`/`generateQnaAnswer` 산출물은 **관리자 검수 후 게시**. 저품질 대량생성은 스팸 패널티 위험.
- **중복 콘텐츠**: 정렬·필터 파라미터 URL은 canonical·`noindex`로 통제.
- **개인정보/보안 경로 노출 금지**: 주문·마이페이지·관리자·API는 봇 무관 색인·크롤 차단.
- **성능 회귀**: sitemap·스키마 렌더가 요청 지연을 유발하지 않도록 캐시/배치.

---

## 부록 A. `robots.txt` 초안

```
User-agent: *
Disallow: /admin/
Disallow: /cart
Disallow: /order
Disallow: /mypage
Disallow: /auth
Disallow: /*?sort=
Disallow: /*?page=

# 검색·인용용 AI 봇 — 허용(브랜드 노출 목적)
User-agent: OAI-SearchBot
Allow: /
User-agent: ChatGPT-User
Allow: /
User-agent: PerplexityBot
Allow: /
User-agent: ClaudeBot
Allow: /

Sitemap: https://example.com/sitemap.xml
```
> 관리자·개인정보 경로는 위 그룹 뒤에도 유효하도록 각 AI 봇 그룹에 `Disallow` 재기재 또는 공통 정책 관리 필요(로봇 표준상 매칭 그룹 하나만 적용됨에 유의).

## 부록 B. `Product` JSON-LD 예시

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "상품명",
  "image": ["https://example.com/uploads/p1.jpg"],
  "description": "상품 설명",
  "sku": "SKU-123",
  "brand": {"@type": "Brand", "name": "브랜드명"},
  "offers": {
    "@type": "Offer",
    "url": "https://example.com/shop/product/123",
    "priceCurrency": "KRW",
    "price": "39000",
    "availability": "https://schema.org/InStock"
  },
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.6",
    "reviewCount": "128"
  }
}
</script>
```

---

*작성 기준일: 2026-07-01 · 대상 저장소: AICopia (CodeIgniter 4) · 참고 자산: `SeoHelper`, `AiProviderInterface`, `SemanticSearchService`, `NaverShoppingProvider`*
