# 개발 품질 게이트 (필수)

커밋·PR 전에 아래를 통과시킵니다. 한 번에 `composer check`(= cs + analyse + test).

## 0. pre-push 훅 (자동 게이트)

push 전 품질 게이트를 로컬에서 강제해 **"push → CI 실패 → 수정 → 재push" 왕복을 제거**한다. 훅 본체는 저장소에 추적되는 `.githooks/pre-push`.

- **활성화**: `composer install` 시 자동(`post-install-cmd` → `core.hooksPath=.githooks`). 수동은 `composer hooks:install`.
- **동작**: `cs` + `analyse`는 항상 실행(~2초). `test`는 `app/`·`tests/`·`composer.*`·`phpunit.xml`·`phpstan.neon`가 바뀐 push에서만 실행(문서 전용 push는 생략).
- **테스트 DB 미설정**이면 훅이 감지해 이 문서(아래 4번)로 안내한다. 로컬 테스트 DB를 먼저 잡아야 훅의 test 단계가 통과한다.
- **긴급 우회**: `git push --no-verify`.

## 1. 코드 스타일 — PHP-CS-Fixer

```bash
composer cs       # 검사 (변경 없음)
composer cs-fix   # 자동 수정
```

- 설정: `.php-cs-fixer.dist.php` — **PSR-12** 기반 + `declare(strict_types=1)` 강제 + import 정렬·short array 등.
- 대상: `app/`, `tests/`(Views 제외). CI에서 `composer cs`가 실패하면 머지 불가.
- **모든 PHP 파일 첫 줄에 `declare(strict_types=1);`** — cs-fixer가 자동 삽입/검사.

## 2. 정적 분석 — PHPStan

```bash
composer analyse
```

- 레벨 **5**(`phpstan.neon`), CI4 확장(`codeigniter/phpstan-codeigniter`) 사용. 대상은 `app/` 코드 디렉토리(Views 제외).
- 기존 억제는 `phpstan-baseline.neon`. 새 코드는 `@phpstan-ignore`로 덮지 말고 원인 수정.
- 새 클래스·메서드에는 제네릭 타입(`array<string, mixed>` 등) 명시.
- 점진적 상향 목표: 5 → 6 → 8 (baseline으로 단계적 적용).

## 3. 자동 현대화 — Rector

```bash
composer rector       # 미리보기(dry-run)
composer rector-fix   # 적용
```

- 설정: `rector.php` — PHP 8.5 셋 + CODE_QUALITY / DEAD_CODE / TYPE_DECLARATION / EARLY_RETURN.
- 대량 변경이 나올 수 있으므로 **반드시 PR 단위로 검토**하고 cs-fix·analyse·test로 재검증.

## 4. 테스트 — PHPUnit

```bash
composer test
```

- 단위 테스트는 `tests/unit/`(`CIUnitTestCase` + `DatabaseTestTrait`, `$DBGroup = 'tests'`, `$migrate = false`).
- 테스트는 **미리 마이그레이션된 `tests` DB 그룹**을 가정한다(스스로 마이그레이션하지 않음).
- 마이그레이션은 **MySQL 전용 DDL**(`ALTER TABLE ... ADD UNIQUE KEY` 등)을 사용하므로 SQLite로는 실행 불가 — 테스트 DB도 **MySQL**이어야 한다.
- 마이그레이션이 prefix 없는 raw DDL(`ALTER TABLE users ...`)을 쓰므로 **DBPrefix는 빈 값**이어야 한다(프레임워크 기본 tests 그룹의 `db_`를 쓰면 안 됨).
- 로컬 테스트 준비 예시(default·tests 그룹을 같은 MySQL DB로):

```bash
# .env (default·tests 그룹 모두 동일 MySQL, prefix 없음)
#   database.default.DBDriver = MySQLi
#   database.default.database = aicopia_test
#   database.default.DBPrefix =
#   database.tests.DBDriver   = MySQLi
#   database.tests.database   = aicopia_test
#   database.tests.DBPrefix   =
php spark migrate --all      # default 그룹에 테이블 생성
composer test                # tests 그룹이 같은 DB를 읽음
```

- 운영 DB는 절대 사용 금지. CI는 `.github/workflows/ci.yml`의 `test` 잡이 동일 흐름을 MySQL 서비스로 자동 수행.
- 새 기능(특히 Service/Model 로직)은 테스트를 함께 작성.

### 병렬 테스트 (ParaTest) — CI 기본

CI는 `composer test`(순차) 대신 **`composer test:parallel`**(ParaTest 4-worker)로 실행해 시간을 대폭 단축한다(로컬 실측 77초 → 22초).

- 테스트는 트랜잭션으로 격리되지 않고 **실제 커밋 + `tearDown()` 수동 정리**를 쓴다. 따라서 worker가 DB를 공유하면 하드코딩된 unique 값 충돌·락 경합이 발생 → **worker별 전용 DB가 필수**.
- ParaTest는 worker마다 `TEST_TOKEN`(1..N)을 주입한다. `tests/bootstrap.php`가 이를 읽어 `tests` 그룹 DB명을 `aicopia_test_<token>`으로 바꾼다(testing 환경은 `defaultGroup='tests'`라 모든 연결이 따라온다). `TEST_TOKEN`이 없으면 순차 실행이라 그대로 `aicopia_test`.
- worker DB는 템플릿(`aicopia_test`)을 마이그레이션한 뒤 **`bin/clone-test-dbs.sh`**로 복제한다.

로컬에서 병렬로 돌리려면:

```bash
php spark migrate --all                                          # 템플릿(aicopia_test) 준비
MYSQL_PWD=<비번> bin/clone-test-dbs.sh 4 127.0.0.1 <user> aicopia_test   # worker DB 4개 복제
composer test:parallel
```

> 순차 `composer test`(단일 `aicopia_test`)는 그대로 동작한다 — pre-push 훅은 순차를 쓰므로 로컬에서 worker DB를 만들지 않아도 된다.
