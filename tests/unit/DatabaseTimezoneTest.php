<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\MySQLiTimezone\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 타임존 규약 검증 — DB는 UTC 저장, 앱·화면은 Asia/Seoul(KST).
 *
 * MySQL의 TIMESTAMP 컬럼은 내부 저장이 항상 UTC이고 읽고 쓸 때 커넥션 세션
 * 타임존 기준으로 자동 변환된다. 따라서 세션 타임존을 앱 타임존(+09:00)으로
 * 고정해두면 애플리케이션은 계속 KST 문자열을 주고받으면서도 디스크에는
 * UTC가 저장된다. 이 테스트는 그 두 축(세션 타임존 고정, UTC 왕복)을 검증한다.
 */
final class DatabaseTimezoneTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = 'tz_probe_' . substr(uniqid(), -8);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
        parent::tearDown();
    }

    /**
     * 앱 타임존이 Asia/Seoul이면 MySQL 세션에 걸 오프셋은 +09:00이다.
     * (MySQL에 타임존 이름 테이블이 로드돼 있지 않은 환경이 많아 이름 대신 오프셋을 쓴다.)
     */
    public function testOffsetIsDerivedFromAppTimezone(): void
    {
        $this->assertSame('+09:00', Connection::timezoneOffsetFor('Asia/Seoul'));
        $this->assertSame('+00:00', Connection::timezoneOffsetFor('UTC'));
        $this->assertSame('-05:00', Connection::timezoneOffsetFor('America/Panama'));
    }

    /**
     * 실제 커넥션의 세션 타임존이 앱 타임존 오프셋으로 설정돼 있어야 한다.
     * 이게 걸려 있지 않으면 TIMESTAMP 변환이 서버 시계 기준으로 어긋난다.
     */
    public function testConnectionSessionTimezoneMatchesAppTimezone(): void
    {
        $expected = Connection::timezoneOffsetFor(config('App')->appTimezone);

        $actual = $this->db->query('SELECT @@session.time_zone AS tz')->getRow()->tz;

        $this->assertSame($expected, $actual, '커넥션 세션 타임존이 앱 타임존 오프셋과 다르다 — 커스텀 드라이버가 적용되지 않았을 수 있다.');
    }

    /**
     * KST 문자열로 저장한 TIMESTAMP 값이 디스크에는 UTC로 남는다.
     * 세션을 UTC로 바꿔 같은 행을 읽으면 9시간 이른 값이 나와야 한다.
     */
    public function testTimestampIsStoredAsUtcAndReadBackAsKst(): void
    {
        $this->db->query('CREATE TABLE `' . $this->table . '` (id INT PRIMARY KEY, at TIMESTAMP NULL)');

        // 앱이 하듯 KST 문자열을 그대로 저장한다.
        $kstInput = '2026-08-12 14:00:00';
        $this->db->query('INSERT INTO `' . $this->table . '` (id, at) VALUES (1, ?)', [$kstInput]);

        // 같은(KST) 세션에서 읽으면 넣은 값 그대로 돌아온다.
        $readBack = $this->db->query('SELECT at FROM `' . $this->table . '` WHERE id = 1')->getRow()->at;
        $this->assertSame($kstInput, $readBack, '앱은 저장·조회 모두 KST로 다뤄야 한다.');

        // 세션을 UTC로 바꿔 읽으면 9시간 이른 값 = 디스크에 UTC로 저장됐다는 증거.
        $this->db->query("SET time_zone = '+00:00'");
        $utcValue = $this->db->query('SELECT at FROM `' . $this->table . '` WHERE id = 1')->getRow()->at;
        $this->db->query("SET time_zone = '" . Connection::timezoneOffsetFor(config('App')->appTimezone) . "'");

        $this->assertSame('2026-08-12 05:00:00', $utcValue, 'TIMESTAMP 컬럼이 UTC로 저장되지 않았다.');
    }

    /**
     * 스키마 전체에 DATETIME 컬럼이 남아 있으면 안 된다.
     *
     * DATETIME은 넣은 문자열을 그대로 보관해 타임존 변환을 받지 않으므로, 새 마이그레이션이
     * DATETIME 컬럼을 추가하면 그 컬럼만 KST로 저장되어 규약이 조용히 깨진다.
     * (DATE 타입은 순수 날짜라 대상이 아니다.)
     */
    public function testNoDatetimeColumnsRemainInSchema(): void
    {
        $rows = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND DATA_TYPE = ?
              ORDER BY TABLE_NAME, COLUMN_NAME',
            [$this->db->getDatabase(), 'datetime'],
        )->getResultArray();

        $offenders = array_map(
            static fn (array $row): string => $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'],
            $rows,
        );

        $this->assertSame(
            [],
            $offenders,
            'DATETIME 컬럼이 남아 있다 — 시각 컬럼은 TIMESTAMP로 정의해야 UTC로 저장된다: ' . implode(', ', $offenders),
        );
    }
}
