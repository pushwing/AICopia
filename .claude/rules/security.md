# 보안 규칙

프로젝트 전역 보안 지침. 코드 작성·리뷰 시 반드시 준수한다.

## 핵심 원칙

- 입력은 `$this->request->getPost()` / `getGet()`로 받고, 처리 전 `$this->validate()`로 검증한다. `$_GET`·`$_POST` 직접 사용 금지.
- 뷰의 모든 출력은 `esc()`로 감싼다(HTML은 `esc($v, 'html')`). 뷰에서 `echo $변수` 직접 출력 금지.
- DB 접근은 Query Builder / 바인딩만 사용 — 문자열 연결 raw SQL 금지.
- 모든 POST/PUT/DELETE 폼에 `<?= csrf_field() ?>` 포함(아래 CSRF 예외 라우트 제외).
- 비밀번호는 `password_hash()`로 저장 — `md5()`/`sha1()` 금지. 비밀번호·토큰 등 민감 데이터는 JSON 응답·디버그 로그에서 제외.
- 시크릿·API키 하드코딩 금지 — `.env` + `env('KEY')` / Config 클래스 사용. `.env`는 절대 스테이징하지 말 것.
- 파일 업로드는 `$_FILES` 직접 처리 금지 — `FileUploader`/`ImageUploader`/`MediaUploader` 경유(확장자·MIME·용량 검증).
- 모델에는 `$allowedFields`를 반드시 명시 — 의도치 않은 mass assignment 방지.

## 안티패턴 (금지)

| 금지 | 이유 | 대신 |
|------|------|------|
| `$_GET`·`$_POST` 직접 사용 | 필터링 없는 원시 입력 | `$this->request->getPost()` / `getGet()` |
| SQL 문자열 직접 조합 | SQL Injection | Query Builder / 바인딩 |
| 뷰에서 `echo $변수` | XSS | `echo esc($변수)` (HTML은 `esc($v, 'html')`) |
| `md5()`/`sha1()`로 비밀번호 저장 | 취약한 해시 | `password_hash()` |
| 시크릿·API키 하드코딩 | 노출 위험 | `.env` + `env('KEY')` |
| CSRF 토큰 없이 POST 처리 | CSRF 공격 | `csrf_field()` (예외 라우트는 `Config/Filters.php`에 한정) |
| `$_FILES` 직접 처리 | 악성 파일 업로드 | `FileUploader`/`ImageUploader` 경유(확장자·MIME 검증) |
| `$db->query("... WHERE id = $id")` | SQL Injection | Query Builder / 바인딩 |
| `$allowedFields` 없는 Model | 의도치 않은 mass assignment | `$allowedFields` 명시 |

## CSRF 예외 라우트 (`Config/Filters.php`)

PG 서버 등 외부에서 CSRF 토큰 없이 POST가 들어오는 라우트만 제외한다. **예외 라우트를 무분별하게 추가하면 보호 구멍이 생기므로 아래로 한정한다.**

- `api/*`
- `payment/callback/*` (PG 서버 콜백)
- `board/image-upload`
- `admin/media/upload`
