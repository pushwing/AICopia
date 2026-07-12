# 개발 품질 게이트 (필수)

커밋·PR 전에 아래를 통과시킵니다. 한 번에 `composer check`(= cs + analyse + test).

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
