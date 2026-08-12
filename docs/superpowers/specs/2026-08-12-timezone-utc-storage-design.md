# 타임존 규약 정립: DB는 UTC 저장, 화면은 KST 표시

작성일: 2026-08-12

## 목표

시각 데이터를 **DB에는 UTC로 저장**하고 **사용자에게는 `Asia/Seoul`(KST)로 표시**한다.
애플리케이션 타임존(`$appTimezone`)은 `Asia/Seoul`을 유지한다.

## 현재 상태 (조사 결과)

| 항목 | 실태 |
|------|------|
| 서버 OS 시계 | 운영 UTC / 개발 Mac KST |
| CI4 `$appTimezone` | 로컬 `app/Config/App.php`는 `Asia/Seoul`. **단 이 파일은 gitignore** — 배포 서버에서는 vendor 기본값 `UTC`로 복원됐을 수 있다 |
| DB 컬럼 타입 | `datetime` 85개(38개 테이블), `timestamp` 0개, `date` 4개 |
| 저장 경로 | 앱 코드 66곳이 `date('Y-m-d H:i:s')`, 모델 22개가 `useTimestamps = true` → **모두 앱 타임존(KST) 값이 그대로 저장됨** |
| 예외 저장 경로 | `CartModel::109`가 SQL `NOW()` 사용 → **DB 서버 시계**를 따름(운영에서는 UTC) |
| 표시 경로 | 뷰 25개 파일 49곳이 `date(..., strtotime($v))` — 저장값을 그대로 출력 |
| MySQL | 9.7.0, `explicit_defaults_for_timestamp = 1` |

즉 **현재 DB에는 UTC가 아니라 KST가 저장되고 있으며**, `NOW()` 경로만 UTC라 두 기준이 섞여 있다.

## 채택한 접근: MySQL `TIMESTAMP` + 커넥션 세션 타임존

MySQL의 `TIMESTAMP` 컬럼은 **내부 저장이 항상 UTC**이고, 읽고 쓸 때 **커넥션 세션 타임존** 기준으로 자동 변환된다.
따라서 세션 타임존을 `+09:00`으로 고정하면:

```
PHP date() → KST 문자열 → [MySQL이 UTC로 변환] → 디스크에 UTC 저장
디스크의 UTC → [MySQL이 KST로 변환] → PHP가 KST 문자열 수신 → 뷰가 그대로 출력
```

**애플리케이션 코드를 한 줄도 고치지 않고** 목표가 달성된다. 저장 66곳·표시 49곳·모델 22개 모두 그대로 둔다.

### 검토했으나 채택하지 않은 대안

| 대안 | 기각 이유 |
|------|-----------|
| `$appTimezone = 'UTC'` + 표시 계층에서 KST 변환 | 표시 49곳을 모두 수정해야 하고, 사용자가 앱 타임존은 `Asia/Seoul` 유지를 요구 |
| `$appTimezone` 유지 + 저장 지점마다 명시적 UTC 변환 | 저장 66곳 + `useTimestamps` 모델 22개를 모두 고쳐야 하고, 이후 누군가 `date()`를 쓰면 규약이 다시 깨짐 |
| `Events::on('pre_system')`에서 `SET time_zone` | `app/Config/Events.php`가 gitignore. 매 요청 DB 강제 연결. CLI·마이그레이션 경로가 샘 |
| MySQL 서버 글로벌 `time_zone` 설정 | 코드 밖 설정이라 로컬·CI·운영 재현성이 깨짐 |

## 설계

### 1. 타임존 인식 커넥션 드라이버

`app/Database/MySQLiTimezone/Connection.php` — `CodeIgniter\Database\MySQLi\Connection`을 상속해
`connect()` 직후 `SET time_zone = '<offset>'`을 실행한다.

- 오프셋은 하드코딩하지 않고 `$appTimezone`에서 계산한다:
  `(new DateTimeZone(config('App')->appTimezone))->getOffset(new DateTime('now', new DateTimeZone('UTC')))` → `+09:00`
  MySQL에 타임존 이름을 넘기지 않는 이유는 이름 해석에 `mysql.time_zone_name` 테이블이 필요한데
  기본 설치에서는 비어 있는 경우가 많아 조용히 실패하기 때문이다.
- `BaseConnection::reconnect()`도 결국 `connect()`를 다시 타므로 재연결 시에도 적용된다.
- CI4 4.7은 커스텀 드라이버를 지원한다(`Database::checkDbExtension()`이 `\` 포함 시 통과).
  단 `Database::initDriver()`는 **DBDriver 값을 네임스페이스로 취급해** 뒤에 클래스명을 붙인다
  (`$driver . '\\' . $class`). 따라서 설정에 넣는 값은 FQCN이 아니라 네임스페이스이며,
  `Connection` 외에 **`Forge`·`Utils`·`Result`·`PreparedQuery`·`Builder`**도 같은 네임스페이스에
  있어야 한다(`BaseConnection`이 `static::class`의 `Connection`을 치환해 찾는다).
  타임존 동작은 `Connection`에만 필요하므로 나머지는 MySQLi 것을 상속한 얇은 클래스로 둔다.

이 방식이면 웹 요청·`spark` CLI·마이그레이션·테스트 등 **모든 커넥션에 자동 적용**되고, CI4의 지연 연결 이점도 유지된다.

### 2. Registrar 등록

`app/Config/App.php`와 `app/Config/Database.php`는 둘 다 gitignore라 직접 수정하면 배포 시 사라진다.
추적 대상인 `app/Config/Registrar.php`에 등록한다(이미 `permittedURIChars`에 쓰이는 기존 패턴).

```php
public static function App(): array
{
    return [
        'permittedURIChars' => '...',   // 기존
        'appTimezone'       => 'Asia/Seoul',
    ];
}

public static function Database(): array
{
    return [
        // Connection::DRIVER = __NAMESPACE__ (FQCN이 아니라 네임스페이스를 넘긴다)
        'default' => ['DBDriver' => TimezoneAwareConnection::DRIVER],
        'tests'   => ['DBDriver' => TimezoneAwareConnection::DRIVER],
    ];
}
```

`BaseConfig::registerProperties()`는 배열 프로퍼티를 `array_merge`하므로 `DBDriver` 키만 교체되고
`hostname`·`database` 등 나머지 설정은 보존된다.

**중요 — `.env`가 Registrar보다 우선한다.** `BaseConfig::__construct()`는 `registerProperties()`를 먼저 호출한 뒤
`initEnvValue()`로 `.env` 값을 덮어쓴다. 이 프로젝트 `.env`에는 `database.default.DBDriver = MySQLi`와
`database.tests.DBDriver = MySQLi`가 명시돼 있으므로 **이 두 줄을 삭제해야** Registrar 설정이 적용된다.
`.env`는 커밋되지 않으므로 로컬·CI·운영 각 환경에서 1회씩 수동 조치가 필요하다.

### 3. 스키마 전환 마이그레이션

`datetime` 85개 컬럼을 `TIMESTAMP`로 전환한다.

- 컬럼 목록을 하드코딩하지 않고 `information_schema.COLUMNS`에서
  `DATA_TYPE = 'datetime'`인 컬럼을 조회해 동적으로 `ALTER`한다. 이후 추가된 컬럼도 누락되지 않는다.
- `IS_NULLABLE`·`COLUMN_DEFAULT`를 읽어 NULL 허용 여부와 기본값을 보존한다.
- `date` 타입 4개(`users.birthday`, `promotions.start_date`/`end_date`, `access_log_summaries.log_date`)는
  순수 날짜라 필터에서 자연히 제외된다.
- `explicit_defaults_for_timestamp = 1`이라 레거시 자동 `DEFAULT CURRENT_TIMESTAMP ON UPDATE`가 붙지 않는다.

**기존 데이터 자동 보정** — 이 `ALTER`가 세션 타임존 `+09:00` 상태에서 실행되므로,
기존에 저장된 KST 값이 KST로 해석되어 UTC로 변환 저장된다. 별도 `UPDATE` 데이터 마이그레이션이 필요 없다.
따라서 **1번(커넥션 드라이버)이 반드시 먼저 적용된 상태여야 한다.**

`down()`은 `DATETIME` 역전환을 제공한다(같은 세션 타임존 전제).

### 4. `cart_items` 처리

`CartModel::109`가 SQL `NOW()`로 저장해 운영에서는 이미 UTC일 수 있다. 그 값은 전환 시 KST로 잘못 해석돼 9시간 밀린다.
장바구니는 단기 데이터이고 아직 운영 서비스가 없으므로, **마이그레이션에서 전환 직전에 `cart_items`를 비운다.**

### 5. 애플리케이션 코드

**수정하지 않는다.** `date()`가 만드는 KST 값은 MySQL이 UTC로 변환해 저장하고, 조회 시 KST로 돌려준다.
`NOW()`·`CURDATE()`도 세션이 `+09:00`이라 KST 기준으로 일관된다.

## 검증 (실행 결과)

`tests/unit/DatabaseTimezoneTest.php`:

- 오프셋 계산이 타임존 이름에서 올바로 파생되는지 (`Asia/Seoul` → `+09:00`)
- 실제 커넥션의 `@@session.time_zone`이 앱 타임존 오프셋과 일치하는지
- `TIMESTAMP` 컬럼에 KST로 쓴 값이 `SET time_zone='+00:00'` 조회 시 9시간 이르게 나오는지(= UTC 저장)
- 스키마에 `DATETIME` 컬럼이 남아 있지 않은지 (새 마이그레이션의 실수를 잡는 회귀 테스트)

실제 전환 검증 결과:

| 항목 | 결과 |
|------|------|
| 타입 분포 | `datetime` 85 → 0, `timestamp` 0 → 85, `date` 4 유지 |
| KST 세션 조회값 | 전환 전과 **동일**(앱이 보는 값 불변) |
| UTC 세션 조회값 | 정확히 **9시간 이름**(디스크에 UTC 저장 확인) |
| NULL 허용 분포 | NOT NULL 8 / NULL 77 — 전환 전과 동일하게 보존 |
| 롤백 → 재적용 왕복 | 값 손실·변형 없음 |
| 전체 테스트 | **1116개 전부 통과** — 앱 코드를 고치지 않아도 된다는 설계 전제가 실증됨 |
| `composer cs` / `analyse` | 통과 |

## 리스크

| 리스크 | 대응 |
|--------|------|
| `TIMESTAMP` 상한 **2038-01-19** | 그 이후 날짜 입력 시 에러. 쿠폰 만료·프로모션 기간에 12년 뒤 날짜를 넣을 일은 없다고 판단하고 수용. 문서에 명시 |
| `ALTER` 중 테이블 잠금 (38개 테이블) | 아직 운영 서비스가 없어 영향 없음. 향후 대용량 전환 시에는 점검 시간 확보 필요 |
| 드라이버 미적용 상태에서 마이그레이션 실행 | 기존 KST 값이 서버 시계 기준으로 잘못 변환됨. 마이그레이션 시작 시 세션 오프셋을 검사해 불일치면 중단 |
| `.env` 수정 누락 | Registrar 설정이 무시되고 표준 `MySQLi` 드라이버가 쓰여 세션 타임존이 안 걸림. 위 검사에서 잡힌다 |

## 문서

저장소 `CLAUDE.md`와 전역 `~/.claude/CLAUDE.md`의 타임존 규약을 이 방식에 맞게 다시 쓴다
(현재는 "저장 시 UTC로 명시 변환" 기준으로 적혀 있어 이 설계와 어긋난다).
