<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MySQLiTimezone\Connection as TimezoneAwareConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * 시각 컬럼을 DATETIME에서 TIMESTAMP로 전환해 "DB는 UTC 저장" 규약을 성립시킨다.
 *
 * DATETIME은 넣은 문자열을 그대로 보관하므로, 앱 타임존이 Asia/Seoul인 이 프로젝트에서는
 * KST 값이 저장돼 왔다. TIMESTAMP는 내부 저장이 항상 UTC이고 읽고 쓸 때 커넥션 세션
 * 타임존 기준으로 변환되므로, 세션을 +09:00으로 고정해두면 앱은 계속 KST로 주고받으면서
 * 디스크에는 UTC가 남는다.
 *
 * 기존 데이터는 별도 UPDATE 없이 이 ALTER만으로 보정된다 — 세션 타임존이 +09:00인 상태에서
 * 타입을 바꾸면 MySQL이 기존 값을 KST로 해석해 UTC로 변환 저장하기 때문이다. 그래서 세션
 * 타임존이 앱 타임존과 일치하는지 먼저 확인하고, 어긋나면 데이터가 망가지기 전에 중단한다.
 *
 * 대상 컬럼은 하드코딩하지 않고 information_schema에서 찾는다. 이후 추가된 컬럼도 누락되지 않는다.
 * DATE 타입(users.birthday, promotions.start_date/end_date, access_log_summaries.log_date)은
 * 시각이 아니라 순수 날짜라 대상에서 제외된다.
 */
class ConvertDatetimeColumnsToTimestamp extends Migration
{
    /**
     * TIMESTAMP가 표현할 수 있는 UTC 범위. 이 밖의 값이 있으면 ALTER가 실패하므로 미리 검사한다.
     */
    private const UTC_MIN = '1970-01-01 00:00:01';
    private const UTC_MAX = '2038-01-19 03:14:07';

    public function up(): void
    {
        $this->guardSessionTimezone();

        // cart_items.created_at만 SQL NOW()로 저장돼(CartModel::addItem) DB 서버 시계를 따랐다.
        // 다른 컬럼과 기준이 달라 KST로 해석하면 어긋나므로, 단기 데이터인 장바구니는 비우고 간다.
        $this->db->query('DELETE FROM `cart_items`');

        $this->convertColumns('datetime', 'TIMESTAMP');
    }

    public function down(): void
    {
        $this->guardSessionTimezone();

        $this->convertColumns('timestamp', 'DATETIME');
    }

    /**
     * 커넥션 세션 타임존이 앱 타임존과 일치하는지 확인한다.
     *
     * 일치하지 않으면 ALTER가 기존 값을 엉뚱한 타임존으로 해석해 전 테이블의 시각이
     * 조용히 어긋난다. 되돌리기 어려운 손상이므로 진행하지 않고 중단한다.
     */
    private function guardSessionTimezone(): void
    {
        $expected = TimezoneAwareConnection::timezoneOffsetFor(config('App')->appTimezone);
        $actual   = $this->db->query('SELECT @@session.time_zone AS tz')->getRow()->tz;

        if ($actual === $expected) {
            return;
        }

        throw new RuntimeException(sprintf(
            "커넥션 세션 타임존이 %s여야 하는데 '%s'다. 타임존 인식 드라이버가 적용되지 않은 상태에서 "
            . '전환하면 기존 시각 데이터가 모두 어긋난다. .env의 database.*.DBDriver 줄을 지워 '
            . 'Registrar의 설정(App\Database\MySQLiTimezone)이 적용되게 한 뒤 다시 실행할 것.',
            $expected,
            $actual,
        ));
    }

    /**
     * 지정한 타입의 컬럼을 모두 찾아 목표 타입으로 바꾼다. NULL 허용 여부와 기본값은 보존한다.
     */
    private function convertColumns(string $fromType, string $toType): void
    {
        $columns = $this->columnsOfType($fromType);

        if ($columns === []) {
            return;
        }

        $this->guardConvertibleValues($columns, $toType);

        foreach ($columns as $column) {
            $this->db->query(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s %s%s',
                $column['TABLE_NAME'],
                $column['COLUMN_NAME'],
                $toType,
                $column['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL',
                $this->defaultClause($column['COLUMN_DEFAULT']),
            ));
        }
    }

    /**
     * 현재 DB에서 해당 데이터 타입인 컬럼 목록을 가져온다.
     *
     * @return list<array<string, string|null>>
     */
    private function columnsOfType(string $dataType): array
    {
        return $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND DATA_TYPE = ?
              ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$this->db->getDatabase(), $dataType],
        )->getResultArray();
    }

    /**
     * TIMESTAMP 표현 범위를 벗어나는 값이 있는지 미리 확인한다.
     *
     * ALTER 도중 실패하면 일부 테이블만 바뀐 어중간한 상태가 되므로, 바꾸기 전에 전부 검사한다.
     *
     * @param list<array<string, string|null>> $columns
     */
    private function guardConvertibleValues(array $columns, string $toType): void
    {
        if ($toType !== 'TIMESTAMP') {
            return;   // DATETIME으로 되돌릴 때는 범위 제약이 없다.
        }

        // 저장값은 세션 타임존(KST) 기준이므로 UTC 경계도 같은 기준으로 옮겨 비교한다.
        $min = $this->toSessionTime(self::UTC_MIN);
        $max = $this->toSessionTime(self::UTC_MAX);

        $offenders = [];

        foreach ($columns as $column) {
            $count = $this->db->query(
                sprintf(
                    'SELECT COUNT(*) AS c FROM `%s` WHERE `%s` IS NOT NULL AND (`%s` < ? OR `%s` > ?)',
                    $column['TABLE_NAME'],
                    $column['COLUMN_NAME'],
                    $column['COLUMN_NAME'],
                    $column['COLUMN_NAME'],
                ),
                [$min, $max],
            )->getRow()->c;

            if ((int) $count > 0) {
                $offenders[] = sprintf('%s.%s (%d행)', $column['TABLE_NAME'], $column['COLUMN_NAME'], $count);
            }
        }

        if ($offenders !== []) {
            throw new RuntimeException(
                'TIMESTAMP로 표현할 수 없는 값이 있어 전환을 중단한다 (허용 범위 '
                . $min . ' ~ ' . $max . ', 세션 타임존 기준): ' . implode(', ', $offenders),
            );
        }
    }

    /**
     * UTC 시각 문자열을 현재 세션 타임존 기준 문자열로 옮긴다.
     */
    private function toSessionTime(string $utc): string
    {
        return $this->db->query(
            "SELECT CONVERT_TZ(?, '+00:00', @@session.time_zone) AS t",
            [$utc],
        )->getRow()->t;
    }

    /**
     * 기본값 절을 만든다. CURRENT_TIMESTAMP 같은 표현식은 따옴표로 감싸면 안 된다.
     */
    private function defaultClause(?string $default): string
    {
        if ($default === null) {
            return '';
        }

        if (str_contains(strtoupper($default), 'CURRENT_TIMESTAMP')) {
            return ' DEFAULT ' . $default;
        }

        return ' DEFAULT ' . $this->db->escape($default);
    }
}
