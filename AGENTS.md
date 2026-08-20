# AICopia 개발 가이드

이 문서는 AICopia의 저장소 전용 규칙이다. 사용자 전역 `~/.codex/AGENTS.md`의 PHP 기본 프로필을 상속하며, 여기의 규칙이 충돌 시 우선한다.

## 저장소 개요

- AICopia는 AI 운영 보조 기능을 갖춘 단일 CodeIgniter 4 쇼핑몰 솔루션이다. 기업형 사이트(페이지·게시판·문의)와 상품·장바구니·주문·PG 결제를 함께 제공한다.
- 저장소 루트가 하나의 CI4 프로젝트다. `default/`, `shop/` 같은 하위 프로젝트는 없으며 모든 `php spark`, `composer`, `git` 명령은 루트에서 실행한다.
- 현재는 모놀리스다. 전역 MSA 원칙을 이유로 기존 기능을 임의로 서비스 분리하지 않는다.

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
- PHP는 8.5 이상이며 Composer 플랫폼 버전도 8.5.0이다.
- 표준 CI4 스켈레톤 일부(`app/Config/App.php`, `system`, `spark`, `public/index.php`)는 gitignore 대상이다. 누락 시 `vendor/codeigniter4/framework`에서 없는 항목만 복원하고, 추적되는 커스텀 Config와 `.env`는 덮어쓰지 않는다.

## Git과 품질 게이트

- 전역 기본 흐름 `feature/* → dev → main`을 따른다. 문서 전용 변경의 `dev` 직접 push 예외에는 `AGENTS.md`도 포함된다.
- `feature/* → dev` PR에는 CI가 없다. 로컬 검증과 `dev` push의 pre-push 훅이 실질적인 품질 게이트다.
- `.githooks/pre-push`는 `main` 직접 push를 차단하고, `dev` push에서 `composer cs`, `composer analyse`를 실행한다. `app/`, `tests/`, Composer, PHPUnit, PHPStan 설정 변경이 있으면 `composer test`도 실행한다.
- `composer install`은 훅을 자동 활성화한다. 필요하면 `composer hooks:install`을 실행한다.
- 긴급 우회는 `git push --no-verify` 또는 `SKIP_HOOKS=1 git push`지만 정상 작업에서 사용하지 않는다.
- Rector는 대량 변경이 될 수 있다. PR 단위로 검토하고 `composer cs-fix`, `composer analyse`, `composer test`로 재검증한다.

### 테스트 DB

- PHPUnit은 미리 마이그레이션된 MySQL 테스트 DB를 사용한다. SQLite는 MySQL 전용 DDL 때문에 사용할 수 없으며 운영 DB 사용은 절대 금지다.
- 테스트 DB의 `DBPrefix`는 빈 값이어야 한다. 일부 마이그레이션 raw DDL이 prefix 없는 테이블명을 사용한다.
- 순차 테스트는 `aicopia_test`를 사용한다. 병렬 테스트는 `TEST_TOKEN`별 `aicopia_test_<token>` DB를 사용한다.
- 병렬 테스트 전에는 템플릿 DB를 마이그레이션한 뒤 `bin/clone-test-dbs.sh`로 worker DB를 복제한다.

## 타임존과 데이터베이스

전역 UTC 저장/KST 앱 정책을 다음 구현으로 고정한다.

| 계층 | AICopia 구현 |
| --- | --- |
| CI4 앱 | `app/Config/Registrar.php`의 `appTimezone = Asia/Seoul` |
| DB 세션 | `App\Database\MySQLiTimezone\Connection`이 연결 시 `SET time_zone = '+09:00'` 적용 |
| DB 저장 | 시각 컬럼은 `TIMESTAMP`, 순수 날짜는 `DATE` |

- `app/Config/App.php`는 추적되지 않으므로 앱 타임존을 직접 수정하지 않는다.
- `.env`에 `database.default.DBDriver` 또는 `database.tests.DBDriver`를 두지 않는다. 이 값이 Registrar의 타임존 인식 드라이버를 덮어쓴다.
- PHP에서 UTC로 수동 변환하지 않는다. `date()`·`strtotime()`을 KST 기준으로 사용하고 MySQL이 변환한다.
- 새 시각 컬럼에 `DATETIME`을 사용하지 않는다. 스키마 회귀 테스트를 유지한다.
- `TIMESTAMP` 상한인 2038-01-19 이후 시각이 필요한 경우에는 별도 모델을 설계한다.

## AICopia 아키텍처

### 렌더링과 라우팅

- 모든 컨트롤러는 `BaseController`를 상속한다. 화면 렌더링에는 `$this->render('view/path', $extraData)`를 사용해 `$viewData`의 전역 데이터를 보존한다.
- `ThemeView`는 `app/Views/themes/{active_theme}/`, `app/Views/themes/default/`, `app/Views/` 순서로 뷰를 찾는다. 새 테마는 `app/Views/themes/{name}/`와 `public/themes/{name}/`에 추가하고, default와 다른 파일만 오버라이드한다.
- `/admin/*`에는 `auth:admin`이 필요하다. 장바구니 조회·수정·삭제에는 `auth:member`가 필요하고 `cart/add` POST는 비회원 세션 장바구니를 허용한다.
- 동적 페이지 catch-all 라우트 `(:segment)` → `Front\PageController::show`는 항상 `Routes.php`의 마지막에 둔다.

### 보안 구현 예외

- 입력은 `$this->request->getPost()` 또는 `getGet()`으로 받고 `$this->validate()`로 검증한다.
- 모든 출력은 `esc()`로 이스케이프하며 HTML 문맥은 `esc($value, 'html')`을 사용한다.
- CSRF 제외는 `api/*`, `payment/callback/*`, `board/image-upload`, `admin/media/upload`로 한정한다. 새 예외를 추가하려면 외부 콜백의 검증 필요성을 확인한다.
- 업로드는 `FileUploader`, `ImageUploader`, `MediaUploader`만 사용한다.

### 결제·주문·재고

- PG 구현은 `PGInterface`와 `PGFactory`를 따른다. 키는 `Config/PG.php`를 통해 `.env`에서 읽는다.
- 재고는 PG 성공 콜백 또는 관리자 무통장입금 확인 시점에만 차감한다. 장바구니에 담을 때는 차감하지 않는다.
- 재고 차감은 `OrderModel::confirmPaid()`/`confirmBankTransfer()`의 트랜잭션, `SELECT ... FOR UPDATE`, 조건부 UPDATE 패턴을 유지한다.
- `payments.pg_tid` UNIQUE 제약으로 중복 PG 콜백을 막고, 재고 조정은 `stock_logs`에 감사 기록한다.
- 주문 상태 전이는 `OrderModel::updateStatus()`의 단방향 흐름을 따른다. 상태 건너뛰기나 역전이를 만들지 않는다.
- 반품·교환 가능 기간은 `delivered_at` 기준 7일이며 null인 레거시 주문은 기존 정책대로 허용한다.

### AI·캐시·배치

- AI Provider는 `AiProviderInterface`와 기존 Provider/Factory 패턴을 사용한다. API 키는 설정값 또는 환경 변수에서만 읽는다.
- 오래 걸리는 AI 작업은 `ai_jobs`에 큐잉하고 `php spark ai:work`가 처리한다. 등록 핸들러는 `review_summary`, `inquiry_classify`다.
- 설정·메뉴·배너·팝업은 CI4 파일 캐시를 사용하며, Model 콜백에서 관련 캐시를 무효화한다.
- 운영 크론은 매분 저장소 루트에서 `php spark tasks:run`을 실행한다. 스케줄 활성화와 주기는 `/admin/schedule`에서 관리한다.

## CI/CD

- GitHub Actions CI는 `main` 대상 PR에서만 실행하며 self-hosted macOS ARM64 러너를 사용한다.
- CI의 MySQL 컨테이너는 호스트 포트 13306을 사용하고, 테스트는 `composer test:parallel`로 실행한다.
- `main` 머지는 SSH 배포로 이어진다. `dev → main` PR은 추가 승인 게이트 없이 즉시 배포될 수 있으므로 신중히 머지한다.
- 일반 배포는 DB 마이그레이션을 실행하지 않는다. 필요한 경우에만 배포 workflow를 `run_migration = true`로 수동 실행한다.
- 운영 마이그레이션 전에는 백업·복구 방법을 확인한다.

## 참고 문서

- 고객·관리자 화면의 사용 흐름: `docs/manual.md`
- 타임존 전환 설계: `docs/superpowers/specs/2026-08-12-timezone-utc-storage-design.md`
- 엑셀 입출력은 `phpoffice/phpspreadsheet`를 사용하며 1만 행 이상은 `ChunkReadFilter`로 청크 처리한다.
