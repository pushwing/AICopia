# AICopia 개발 가이드

이 문서는 AICopia 저장소의 작업 규칙이다. 사용자 전역 `~/.codex/AGENTS.md`보다 우선하며, 하위 디렉터리에 더 구체적인 `AGENTS.md`가 있으면 그 문서를 우선한다.

## 저장소 개요

- AICopia는 AI 운영 보조 기능을 갖춘 단일 CodeIgniter 4 기반 쇼핑몰 솔루션이다.
- 저장소 루트가 하나의 CI4 프로젝트다. 모든 `php spark`, `composer`, `git` 명령은 루트에서 실행한다.
- 기업형 사이트(페이지·게시판·문의)와 쇼핑몰(상품·장바구니·주문·PG 결제)을 함께 제공한다.
- 기존 아키텍처를 존중한다. 별도 Repository 계층이나 새 프레임워크·대규모 구조 변경을 임의로 도입하지 않는다.

## 개발 환경과 명령

```bash
php spark serve --host 127.0.0.1 --port 8303
php spark migrate
php spark migrate:rollback
php spark db:seed <Seeder>

composer test
composer test:parallel
composer analyse
composer cs
composer cs-fix
composer rector
composer rector-fix
composer check
```

- 개발 서버는 반드시 `--host 127.0.0.1`을 명시한다. 기본 `localhost`는 IPv6로 리슨되어 Caddy가 연결하는 `127.0.0.1:8303`에서 502가 발생한다.
- PHP 8.5 이상을 사용한다. Composer 플랫폼 버전도 8.5.0이다.
- 표준 CI4 스켈레톤 일부(`app/Config/App.php`, `system`, `spark` 등)는 gitignore 대상이다. 누락 시 `vendor/codeigniter4/framework`에서 없는 항목만 복원하고, 추적되는 커스텀 Config와 `.env`는 덮어쓰지 않는다.
- `.env`는 스테이징하거나 커밋하지 않는다.

## Git 워크플로우

브랜치 흐름은 `feature/* → dev → main`이다.

- 기능 작업은 최신 `dev`에서 `feature/<짧은-이름>` 브랜치를 만들어 진행하고, `dev`로 PR을 연다.
- `main`은 운영/릴리스 브랜치이며 `dev → main` PR로만 갱신한다.
- `main`, `dev`에 직접 커밋하거나 push하지 않는다. 단, 문서·주석만 변경한 경우에는 `dev` 직접 커밋·push를 허용한다.
- 문서 전용 예외 대상은 `CLAUDE.md`, `AGENTS.md`, `.claude/rules/`, `docs/`, `README`와 주석 변경이다. 코드, `app/`, `public/`, 마이그레이션, 설정 변경이 하나라도 섞이면 기능 브랜치와 PR을 사용한다.
- 문서 변경이라도 `main` 직접 커밋·push는 금지한다.
- `feature/*`는 PR 머지 후에만 삭제하며, `dev`는 절대 삭제하지 않는다.
- `feature → dev`는 squash merge, `dev → main` 배포 PR은 merge commit을 사용한다. 배포 PR을 squash merge하지 않는다.
- 커밋은 하나의 논리 작업 단위로 분리한다. 메시지는 이모지 + Conventional Commit 접두어 + 한국어 설명 형식이다. 예: `✨ feat: 상품 목록 필터 추가`.
- 작업 트리에 사용자의 미커밋 변경이 있으면 보존하고, 관련 없는 변경을 되돌리거나 포함하지 않는다.

## 품질 게이트와 테스트

- 커밋·PR 전에는 `composer check`(`cs + analyse + test`)를 실행한다. 실행 불가 또는 실패 시 이유를 명확히 보고한다.
- `feature/* → dev` PR에는 CI가 없다. 로컬 검증과 `dev` push의 pre-push 훅이 실질적인 품질 게이트다.
- `.githooks/pre-push`는 `main` 직접 push를 차단하고, `dev` push에서 `composer cs`, `composer analyse`를 실행한다. `app/`, `tests/`, Composer, PHPUnit, PHPStan 설정 변경이 있으면 `composer test`도 실행한다.
- 긴급 우회는 `git push --no-verify` 또는 `SKIP_HOOKS=1 git push`다. 정상 작업에서 사용하지 않는다.
- `composer install`은 훅을 자동 활성화한다. 필요하면 `composer hooks:install`을 실행한다.
- 코드 스타일은 PHP-CS-Fixer 및 PSR-12 기준이다. 모든 PHP 파일에 `declare(strict_types=1);`을 둔다.
- PHPStan은 레벨 6이며 Views를 제외한 `app/`을 분석한다. 새 코드에 `@phpstan-ignore`를 추가해 문제를 숨기지 말고 원인을 수정한다.
- Rector는 대량 변경을 만들 수 있으므로 PR 단위로 검토하고 `cs-fix`, `analyse`, `test`로 재검증한다.
- 새 기능, 특히 Service·Model 로직에는 테스트를 함께 작성한다.

### 테스트 DB

- PHPUnit은 미리 마이그레이션된 MySQL 테스트 DB를 사용한다. 운영 DB는 절대 사용하지 않는다.
- 마이그레이션에 MySQL 전용 DDL이 있으므로 SQLite를 사용하지 않는다.
- 테스트 DB의 `DBPrefix`는 빈 값이어야 한다. raw DDL이 prefix 없는 테이블명을 사용한다.
- 순차 테스트는 `aicopia_test`를 사용한다. 병렬 테스트는 `TEST_TOKEN`별 `aicopia_test_<token>` DB를 사용한다.
- 병렬 테스트 전에는 템플릿 DB를 마이그레이션하고 `bin/clone-test-dbs.sh`로 worker DB를 복제한다.

## 타임존과 데이터베이스

서버 OS와 MySQL 디스크 저장값은 UTC, 앱·DB 세션·입출력은 `Asia/Seoul`(KST)이다.

| 계층 | 규약 |
| --- | --- |
| CI4 앱 | `Config/Registrar.php`의 `appTimezone = Asia/Seoul` |
| DB 세션 | 타임존 인식 MySQLi 드라이버가 연결 시 `SET time_zone = '+09:00'` 적용 |
| DB 디스크 | `TIMESTAMP`는 MySQL이 UTC로 저장 |
| 앱·뷰·사용자 입력 | KST |

- 시각 컬럼은 `TIMESTAMP`를 사용한다. 순수 날짜는 `DATE`를 사용한다.
- `DATETIME`은 타임존 자동 변환을 받지 않으므로 새 시각 컬럼에 사용하지 않는다. 스키마 회귀 테스트를 유지한다.
- PHP에서 UTC로 수동 변환해 저장하지 않는다. `date()`와 `strtotime()`을 KST 기준으로 평소처럼 쓰며 변환은 MySQL이 담당한다.
- `Time::now('UTC')` 등을 저장값으로 넣지 않는다. 세션 타임존을 통해 다시 KST로 해석되어 이중 변환된다.
- `app/Config/App.php`는 gitignore 대상이다. 앱 타임존은 반드시 추적되는 `Config/Registrar.php`에서 관리한다.
- `.env`에 `database.default.DBDriver` 또는 `database.tests.DBDriver`를 두지 않는다. 환경 변수가 Registrar의 타임존 인식 드라이버를 덮어쓴다.
- `TIMESTAMP` 상한은 2038-01-19다. 장기 만료일 등 이를 넘는 시각은 별도 설계를 검토한다.

## 코드 스타일과 구조

- PSR-12, `declare(strict_types=1)`, PHP 8.5 문법을 따른다.
- 파라미터·프로퍼티·반환 타입을 선언한다. 컬렉션·배열은 PHPStan 제네릭 타입을 명시한다.
- 분기에는 `match`를 우선 사용하고, 상태·역할은 매직 값보다 Backed Enum을 우선 검토한다.
- 클래스는 PascalCase, 메서드·변수·프로퍼티는 camelCase, 상수는 UPPER_SNAKE_CASE, 배열 키와 DB 식별자는 snake_case를 쓴다.
- 테이블은 복수 snake_case, PK는 `id`, FK는 `{단수}_id`, 불리언은 `is_` 접두어를 쓴다. 인덱스는 `idx_{table}_{column}`, 유니크 인덱스는 `uniq_{table}_{column}` 형식이다.
- Controller는 유효성 검사 → Service/Model 호출 → 렌더 또는 응답만 수행한다. 비즈니스 로직은 `app/Libraries/`의 Service 또는 Model 메서드로 추출한다.
- 하나의 Service 메서드는 하나의 유스케이스를 담당한다. 트랜잭션은 Service/Model 계층에서 관리한다.
- 데이터 접근은 Model과 Query Builder를 사용한다. 복잡한 쿼리는 Model 메서드로 캡슐화하며 별도 Repository 계층은 만들지 않는다.
- Model은 `CodeIgniter\Model`을 상속하고 `$allowedFields`를 명시한다.
- 뷰는 컨트롤러가 넘긴 데이터만 렌더링한다. 뷰에서 Model을 직접 생성하거나 조회하지 않는다.
- 도메인 예외는 `app/Exceptions/`에 정의한다. 예외를 잡은 뒤 무시하지 말고 최소한 `log_message()`로 기록한다.

### 금지 사항

- `@` 오류 억제, `extract()`, `global`, 비즈니스 로직의 `die()`/`exit()`.
- 커밋되는 `var_dump()`, `print_r()`, `dd()`, 주석 처리된 죽은 코드.
- 느슨한 비교(`==`), 타입 선언 없는 함수, `null`·`false` 반환 혼용, catch 후 예외 무시.
- `SELECT *`, N+1 쿼리, 페이지네이션 없는 목록, 인덱스 없는 빈번한 조회 조건.

## 보안

- 원시 `$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`를 직접 사용하지 않는다.
- 입력은 `$this->request->getPost()` 또는 `getGet()`으로 받고, 사용 전 `$this->validate()`로 검증한다.
- SQL은 Query Builder나 바인딩만 사용한다. 문자열 연결 raw SQL은 금지한다.
- 모든 뷰 출력은 `esc()`로 이스케이프한다. HTML 문맥은 `esc($value, 'html')`을 사용한다.
- CSRF 예외가 아닌 모든 POST/PUT/DELETE 폼에 `<?= csrf_field() ?>`를 넣는다.
- 비밀번호는 `password_hash()`로 저장한다. `md5()`·`sha1()`은 금지한다. 비밀번호·토큰 등 민감 데이터는 JSON 응답과 디버그 로그에 포함하지 않는다.
- 시크릿·API 키·PG 키·OAuth 키는 `.env` 또는 Config를 통해 읽는다. 코드에 하드코딩하지 않는다.
- 파일 업로드는 `FileUploader`, `ImageUploader`, `MediaUploader`를 사용한다. 이들은 확장자·MIME·용량을 검증한다.
- 업로드 임시 파일은 `writable/uploads/` 등 웹 루트 밖에서 처리하고 완료 즉시 삭제한다.
- CSRF 제외는 `api/*`, `payment/callback/*`, `board/image-upload`, `admin/media/upload`로 한정한다. 새로운 예외 추가는 외부 콜백의 검증 필요성을 먼저 확인한다.

## 애플리케이션 아키텍처

### 렌더링과 라우팅

- 모든 컨트롤러는 `BaseController`를 상속한다. 화면 렌더링에는 `$this->render('view/path', $extraData)`를 사용해 전역 `$viewData`를 보존한다.
- `ThemeView`는 `app/Views/themes/{active_theme}/`, `app/Views/themes/default/`, `app/Views/` 순서로 뷰를 찾는다.
- 새 테마는 `app/Views/themes/{name}/`와 `public/themes/{name}/`에 추가하고, `default`와 다른 파일만 오버라이드한다.
- `/admin/*`에는 `auth:admin`이 필요하다. 장바구니 조회·수정·삭제에는 `auth:member`가 필요하고, `cart/add` POST는 비회원 세션 장바구니를 허용한다.
- 동적 페이지 catch-all 라우트 `(:segment)` → `Front\PageController::show`는 항상 `Routes.php`의 마지막에 둔다.

### 결제·주문·재고

- PG 구현은 `PGInterface`와 `PGFactory`를 따른다. 새 PG는 기존 어댑터 패턴과 `.env` 기반 키 관리를 따른다.
- 재고는 PG 성공 콜백 또는 관리자 무통장입금 확인 시점에만 차감한다. 장바구니에 담을 때 차감하지 않는다.
- 재고 차감은 `OrderModel::confirmPaid()`/`confirmBankTransfer()`의 트랜잭션·행 잠금·조건부 업데이트 패턴을 유지한다.
- `payments.pg_tid`의 UNIQUE 제약으로 중복 PG 콜백을 방지한다. 재고 조정은 `stock_logs`에 감사 기록한다.
- 주문 상태 전이는 `OrderModel::updateStatus()`의 단방향 흐름을 따른다. 임의의 상태 건너뛰기나 역전이를 만들지 않는다.
- 반품·교환 가능 기간은 `delivered_at` 기준 7일이며, null인 레거시 주문은 기존 정책대로 허용한다.

### 비동기·캐시·외부 연동

- 이메일, 대량 집계, AI 추론 등 무거운 작업은 요청 사이클에서 처리하지 않는다. 배치 명령 또는 `ai_jobs` 큐로 위임한다.
- AI Provider는 `AiProviderInterface`와 기존 Provider/Factory 패턴을 사용한다. 오래 걸리는 작업은 `php spark ai:work`가 처리하는 `ai_jobs`에 큐잉한다.
- 설정·메뉴·배너·팝업은 기존 CI4 파일 캐시와 Model 콜백 무효화 방식을 사용한다. 쓰기 동작에는 관련 캐시 무효화를 포함한다.
- 외부 API·PG 호출에는 적절한 타임아웃과 오류 처리를 둔다. 시크릿은 설정값 또는 환경 변수에서만 가져온다.

## 운영과 CI/CD

- 스케줄러는 `php spark tasks:run`을 사용한다. 운영 cron은 매분 저장소 루트에서 이 명령을 실행한다.
- GitHub Actions CI는 `main` 대상 PR에서만 실행한다. `dev → main` 배포 PR에서 PHPStan, PHPUnit, 의존성 감사가 통과해야 한다.
- CI는 self-hosted macOS ARM64 러너를 사용하며, MySQL 컨테이너는 호스트 포트 13306을 사용한다.
- `main` 머지 시 배포 워크플로가 SSH로 운영 서버에 배포한다. `dev → main` 머지는 즉시 배포로 이어질 수 있으므로 특히 신중히 검토한다.
- 일반 배포는 DB 마이그레이션을 실행하지 않는다. 필요한 경우에만 배포 워크플로의 `run_migration = true`를 명시적으로 선택한다.
- 운영 마이그레이션 전에는 복구 방법과 백업을 확인한다.

## 문서와 도메인 참고

- 고객·관리자 화면의 사용 흐름은 `docs/manual.md`를 참조한다.
- 타임존 전환 설계는 `docs/superpowers/specs/2026-08-12-timezone-utc-storage-design.md`를 참조한다.
- 엑셀 입출력은 이미 포함된 `phpoffice/phpspreadsheet`를 사용한다. 1만 행 이상은 `ChunkReadFilter`로 청크 처리한다.
