<?php

declare(strict_types=1);

namespace Config;

use App\Database\MySQLiTimezone\Connection as TimezoneAwareConnection;

/**
 * app/Config/App.php·Database.php는 표준 CI4 스켈레톤이라 gitignore 대상이며
 * 커밋되지 않는다 (CI/배포 시 vendor에서 매번 새로 복원됨). 그래서 설정을 직접
 * 수정하는 대신 CI4가 제공하는 Registrar 메커니즘(BaseConfig::registerProperties())으로
 * 값을 오버라이드한다. 이 파일은 추적 대상이라 배포 환경에도 항상 반영된다.
 */
class Registrar
{
    /**
     * @return array<string, string>
     */
    public static function App(): array
    {
        return [
            // 한글 상품 슬러그(가-힣) URL 접근 시 라우터가 400(disallowed characters)을
            // 던지던 문제 수정 (이슈 #72). 기본값 'a-z 0-9~%.:_\-'에 완성형 한글 범위만 추가.
            'permittedURIChars' => 'a-z 0-9~%.:_\-\x{AC00}-\x{D7A3}',

            // 앱·화면의 기준 타임존. vendor 스켈레톤 기본값은 'UTC'라서 여기서 고정하지 않으면
            // 신규 서버나 스켈레톤 복원 시 조용히 UTC로 되돌아간다. DB 커넥션 세션 타임존도
            // 이 값에서 파생되므로(아래 Database()) 타임존 정책의 단일 진실 소스다.
            'appTimezone' => 'Asia/Seoul',
        ];
    }

    /**
     * DB 커넥션을 타임존 인식 드라이버로 교체한다.
     *
     * registerProperties()가 배열 프로퍼티를 array_merge하므로 DBDriver 키만 교체되고
     * hostname·database 등 나머지 설정(.env에서 오는 값 포함)은 그대로 유지된다.
     *
     * 주의: .env의 `database.*.DBDriver`가 이 설정보다 우선한다(BaseConfig가
     * registerProperties() 이후 initEnvValue()를 실행). .env에 그 키가 남아 있으면
     * 표준 MySQLi 드라이버가 쓰여 세션 타임존이 걸리지 않으므로 해당 줄을 지워야 한다.
     *
     * @return array<string, array<string, string>>
     */
    public static function Database(): array
    {
        return [
            'default' => ['DBDriver' => TimezoneAwareConnection::DRIVER],
            'tests'   => ['DBDriver' => TimezoneAwareConnection::DRIVER],
        ];
    }
}
