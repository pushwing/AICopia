# 운영 DB 마이그레이션 드리프트 정리 — `AddContextColumn`

`php spark migrate --all` 이 `Duplicate column name 'context'` 로 막히는 문제를 해소한다.

## 원인

앱 마이그레이션 `2026-06-17-000044_FixCiSettingsTable` 이 `ci_settings` 테이블을 **`context` 컬럼까지 포함한 최종 형태로** 생성한다. 그런데 `codeigniter4/settings` 라이브러리의 `2021-11-14-143905_AddContextColumn` 은 그 컬럼을 나중에 추가하려 든다.

- `2021-07-04-041948_CreateSettingsTable` → `createTable($table, true)` 라 이미 있으면 **조용히 통과하고 기록됨**
- `2021-11-14-143905_AddContextColumn` → `addColumn` 은 가드가 없어 **중복 컬럼 오류로 실패**

즉 **스키마는 이미 올바르고, 기록만 빠져 있다.** 그 한 줄을 채우는 작업이다.

> 앱이 `app/Config/Settings.php` 에서 대상 테이블을 `ci_settings`, 그룹을 `default` 로 오버라이드하고 있다. 라이브러리 기본값(`settings`)이 아니다.

---

## 0. 백업 (필수)

```bash
cd <DEPLOY_PATH>
mkdir -p writable/backups
mysqldump -h <HOST> -u <USER> -p <DBNAME> migrations \
  > "writable/backups/migrations_$(date +%F_%H%M%S).sql"
```

`migrations` 테이블만 있으면 이 작업은 되돌릴 수 있다. 여유가 있으면 전체 덤프를 권한다.

---

## 1. 전제 확인 — 정말 "스키마는 있고 기록만 없는" 상태인가

세 가지가 모두 참이어야 진행한다. 하나라도 다르면 **멈추고 상황을 다시 판단할 것.**

```sql
-- (1) context 컬럼이 실제로 존재하는가 → 1 이어야 함
SELECT COUNT(*) AS has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'ci_settings'
  AND COLUMN_NAME  = 'context';

-- (2) AddContextColumn 기록이 없는가 → 0 이어야 함
SELECT COUNT(*) AS already_recorded
FROM migrations
WHERE class = 'CodeIgniter\\Settings\\Database\\Migrations\\AddContextColumn';

-- (3) 형제 마이그레이션(CreateSettingsTable)은 기록돼 있는가 → 1 이어야 함
--     여기서 group·namespace 실제 값을 확인한다. 아래 INSERT 에 그대로 쓴다.
SELECT version, class, `group`, namespace, batch
FROM migrations
WHERE class = 'CodeIgniter\\Settings\\Database\\Migrations\\CreateSettingsTable';
```

**(3) 이 0 건이면 INSERT 하지 말 것.** vendor 마이그레이션이 아예 한 번도 안 돈 것이므로 다른 처리가 필요하다.

⚠️ MySQL 문자열 리터럴에서 백슬래시는 이스케이프 문자다. 클래스명의 `\` 는 **반드시 `\\` 로 두 번** 써야 한다.

---

## 2. 기록 삽입

1단계 (3) 에서 확인한 `group` 값을 `<GROUP>` 자리에 넣는다 (보통 `default`).
`batch` 는 기존 최대값을 재사용해 같은 배치로 묶는다.

```sql
INSERT INTO migrations (version, class, `group`, namespace, time, batch)
VALUES (
  '2021-11-14-143905',
  'CodeIgniter\\Settings\\Database\\Migrations\\AddContextColumn',
  '<GROUP>',
  'CodeIgniter\\Settings',
  UNIX_TIMESTAMP(),
  (SELECT * FROM (SELECT MAX(batch) FROM migrations) AS b)
);
```

> 안쪽 `SELECT * FROM (...) AS b` 감싸기는 MySQL 이 "삽입 대상 테이블을 같은 문장에서 조회" 하는 걸 막는 제약을 우회하기 위한 것이다.

---

## 3. 검증

```sql
-- 기록이 1 건 생겼는지
SELECT version, class, `group`, namespace, batch
FROM migrations
WHERE namespace = 'CodeIgniter\\Settings'
ORDER BY version;
```

이어서 **실제로 통과하는지** 확인한다. 이 명령은 남은 마이그레이션이 없으면 아무것도 바꾸지 않는다.

```bash
cd <DEPLOY_PATH>
php spark migrate --all
```

기대 출력: `Migrations complete.` 또는 `No migrations were found.`
`[...Exception]` 이 보이면 **실패한 것이다** — 3단계 롤백 참고.

---

## 4. 롤백

```sql
DELETE FROM migrations
WHERE class = 'CodeIgniter\\Settings\\Database\\Migrations\\AddContextColumn';
```

또는 0단계 덤프로 `migrations` 테이블 복원.

스키마는 건드리지 않으므로 이 작업의 위험은 `migrations` 테이블 한 행에 국한된다.

---

## 5. 함께 확인할 것 — `codeigniter4/queue`

`--all` 은 Settings 뿐 아니라 **`codeigniter4/queue` 의 마이그레이션 3건**도 실행한다.

```
2023-10-12-112040_AddQueueTables
2023-11-05-064053_AddPriorityField
2024-12-27-110712_ChangePayloadFieldTypeInSqlsrv
```

`composer.json` 은 queue 를 직접 요구하지 않는다(전이 의존성). 이 저장소는 AI 작업 큐를 `ai_jobs` 테이블로 자체 구현하므로 라이브러리 큐 테이블은 쓰지 않는다.

3단계에서 `migrate --all` 을 돌릴 때 이 3건이 **처음 실행되며 큐 테이블이 새로 생길 수 있다.** 동작에 해는 없지만 예상치 못한 테이블이 생기는 게 싫다면, 같은 방식으로 기록만 넣어 건너뛰게 할 수 있다. 어느 쪽이든 3단계 출력에서 무엇이 실행됐는지 확인할 것.

---

## 왜 지금 해야 하나

지금 당장 장애를 일으키지는 않는다. 다만 **다음에 스키마가 바뀌는 배포에서 반드시 막힌다.**

배포 워크플로는 이제 마이그레이션 실패를 배포 실패로 잡으므로(#134), 예전처럼 조용히 넘어가 "컬럼 없는 DB 위에 새 코드" 상태가 되지는 않는다. 대신 **배포 자체가 중단된다.** 급한 보안 패치를 배포해야 하는 순간에 이 문제로 막히는 게 최악이므로, 여유 있을 때 미리 정리해 두는 것이 좋다.
