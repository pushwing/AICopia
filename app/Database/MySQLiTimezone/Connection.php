<?php

declare(strict_types=1);

namespace App\Database\MySQLiTimezone;

use CodeIgniter\Database\MySQLi\Connection as MySQLiConnection;
use DateTime;
use DateTimeZone;
use mysqli;

/**
 * 앱 타임존을 MySQL 커넥션 세션에 적용하는 MySQLi 드라이버.
 *
 * 이 프로젝트의 타임존 규약은 "DB에는 UTC 저장, 앱·화면은 Asia/Seoul(KST)"이다.
 * MySQL의 TIMESTAMP 컬럼은 내부 저장이 항상 UTC이고, 읽고 쓸 때 커넥션 세션
 * 타임존 기준으로 자동 변환된다. 따라서 세션 타임존을 앱 타임존(+09:00)으로
 * 고정해두면 애플리케이션은 계속 KST 문자열을 주고받으면서 디스크에는 UTC가
 * 저장된다 — 저장·표시 코드를 한 줄도 고칠 필요가 없다.
 *
 * 커넥션 시점에 거는 이유는 웹 요청·spark CLI·마이그레이션·테스트 등 모든 경로가
 * 결국 connect()를 지나기 때문이다. Events 훅이나 서버 전역 설정과 달리 빠지는
 * 경로가 없고, CI4의 지연 연결 이점도 그대로 유지된다.
 *
 * 등록은 app/Config/Registrar.php의 Database()에서 한다. app/Config/Database.php는
 * gitignore 대상이라 직접 수정하면 배포 시 vendor 스켈레톤으로 복원되며 사라진다.
 *
 * 주의: .env의 `database.*.DBDriver`가 Registrar보다 우선 적용된다
 * (BaseConfig가 registerProperties() 이후 initEnvValue()를 실행). .env에 그 키가
 * 남아 있으면 이 드라이버가 무시되므로 해당 줄을 지워야 한다.
 */
class Connection extends MySQLiConnection
{
    /**
     * DBDriver 설정에 넣을 값.
     *
     * CI4의 Database::initDriver()는 DBDriver에 담긴 네임스페이스 뒤에
     * Connection·Forge·Utils를 각각 붙여 클래스를 찾는다. 따라서 설정에는
     * 클래스 FQCN이 아니라 이 네임스페이스를 넣어야 한다.
     */
    public const DRIVER = __NAMESPACE__;

    /**
     * 부모 커넥션을 연 뒤 세션 타임존을 앱 타임존 오프셋으로 고정한다.
     *
     * BaseConnection::reconnect()도 결국 이 메서드를 다시 타므로 재연결 시에도 적용된다.
     *
     * @return false|mysqli
     */
    public function connect(bool $persistent = false)
    {
        $connection = parent::connect($persistent);

        if ($connection instanceof mysqli) {
            $connection->query("SET time_zone = '" . self::timezoneOffsetFor($this->appTimezone()) . "'");
        }

        return $connection;
    }

    /**
     * 타임존 이름을 MySQL이 받는 UTC 오프셋 문자열('+09:00')로 변환한다.
     *
     * 타임존 이름을 그대로 넘기지 않는 이유는 MySQL이 이름을 해석하려면
     * mysql.time_zone_name 테이블이 적재돼 있어야 하는데, 기본 설치에서는
     * 비어 있는 경우가 많아 조용히 실패하기 때문이다.
     *
     * 오프셋은 호출 시점 기준으로 계산한다. Asia/Seoul은 서머타임이 없어 항상
     * +09:00이지만, 서머타임이 있는 타임존으로 바꾸면 커넥션을 연 시점의
     * 오프셋이 적용된다는 점에 유의한다.
     */
    public static function timezoneOffsetFor(string $timezone): string
    {
        $seconds = (new DateTimeZone($timezone))->getOffset(new DateTime('now', new DateTimeZone('UTC')));

        return sprintf(
            '%s%02d:%02d',
            $seconds < 0 ? '-' : '+',
            intdiv(abs($seconds), 3600),
            intdiv(abs($seconds) % 3600, 60),
        );
    }

    /**
     * 앱 타임존을 읽는다. 설정을 읽을 수 없는 이른 부팅 단계에서는 PHP 기본 타임존으로 폴백한다.
     */
    private function appTimezone(): string
    {
        $appConfig = config('App');

        return $appConfig->appTimezone ?? date_default_timezone_get();
    }
}
