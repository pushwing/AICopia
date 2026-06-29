<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * StatsController 추가 통계 쿼리 검증
 *
 * - 인기 상품 페이지 TOP 10 (/shop/ 필터)
 * - 유입 경로 도메인 집계
 * - 시간대별 접속 분포 (0~23)
 */
final class StatsDetailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private array $cleanup = ['access_logs' => []];

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['access_logs'] !== []) {
            $db->table('access_logs')->whereIn('id', $this->cleanup['access_logs'])->delete();
        }
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function insertLog(array $override = []): int
    {
        $db = db_connect();
        $db->table('access_logs')->insert(array_merge([
            'ip'         => '1.2.3.4',
            'page'       => '/shop/test-product',
            'url'        => 'http://localhost/shop/test-product',
            'referer'    => '',
            'created_at' => date('Y-m-d H:i:s'),
        ], $override));
        $id = (int) $db->insertID();
        $this->cleanup['access_logs'][] = $id;
        return $id;
    }

    private function buildTopProductPages(string $from, string $to): array
    {
        return db_connect()->query(
            "SELECT page, COUNT(*) AS hits, COUNT(DISTINCT ip) AS unique_visitors
             FROM access_logs
             WHERE created_at BETWEEN ? AND ?
               AND page LIKE '/shop/%'
             GROUP BY page
             ORDER BY hits DESC
             LIMIT 10",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->getResultArray();
    }

    private function buildRefererData(string $from, string $to): array
    {
        return db_connect()->query(
            "SELECT
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(
                        REPLACE(REPLACE(TRIM(LEADING 'https://' FROM TRIM(LEADING 'http://' FROM referer)), 'https://', ''), 'http://', ''),
                    '/', 1),
                '?', 1) AS domain,
                COUNT(*) AS hits
             FROM access_logs
             WHERE created_at BETWEEN ? AND ?
               AND referer IS NOT NULL AND referer != '' AND referer NOT LIKE '%localhost%'
             GROUP BY domain
             ORDER BY hits DESC
             LIMIT 10",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->getResultArray();
    }

    private function buildHourlyData(string $from, string $to): array
    {
        $raw = db_connect()->query(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS hits
             FROM access_logs
             WHERE created_at BETWEEN ? AND ?
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        )->getResultArray();
        $map = array_column($raw, 'hits', 'hour');
        return array_map(fn($h) => (int) ($map[$h] ?? 0), range(0, 23));
    }

    // ── 인기 상품 페이지 ─────────────────────────────────────────────────────

    public function testTopProductPagesOnlyIncludesShopPaths(): void
    {
        $today = date('Y-m-d');
        $this->insertLog(['page' => '/shop/product-a', 'ip' => '1.1.1.1']);
        $this->insertLog(['page' => '/shop/product-a', 'ip' => '1.1.1.2']);
        $this->insertLog(['page' => '/admin/orders',   'ip' => '1.1.1.3']);

        $rows = $this->buildTopProductPages($today, $today);
        $pages = array_column($rows, 'page');

        $this->assertContains('/shop/product-a', $pages, '/shop/ 경로가 포함돼야 함');
        $this->assertNotContains('/admin/orders', $pages, '/admin/ 경로가 제외돼야 함');
    }

    public function testTopProductPagesOrderedByHitsDesc(): void
    {
        $today = date('Y-m-d');
        foreach (range(1, 5) as $_) $this->insertLog(['page' => '/shop/popular']);
        foreach (range(1, 2) as $_) $this->insertLog(['page' => '/shop/less-popular']);

        $rows = $this->buildTopProductPages($today, $today);
        $pages = array_column($rows, 'page');

        $popularIdx    = array_search('/shop/popular',      $pages);
        $lessPopularIdx = array_search('/shop/less-popular', $pages);

        if ($popularIdx !== false && $lessPopularIdx !== false) {
            $this->assertLessThan($lessPopularIdx, $popularIdx, '조회수 높은 페이지가 먼저 와야 함');
        }
    }

    public function testTopProductPagesAtMostTen(): void
    {
        $today = date('Y-m-d');
        foreach (range(1, 12) as $i) {
            $this->insertLog(['page' => "/shop/product-$i"]);
        }
        $rows = $this->buildTopProductPages($today, $today);
        $this->assertLessThanOrEqual(10, count($rows), 'TOP 10을 초과하면 안 됨');
    }

    // ── 유입 경로 ─────────────────────────────────────────────────────────────

    public function testRefererDataExcludesLocalhost(): void
    {
        $today = date('Y-m-d');
        $this->insertLog(['referer' => 'http://localhost/']);
        $this->insertLog(['referer' => 'https://google.com/search?q=test']);

        $rows    = $this->buildRefererData($today, $today);
        $domains = array_column($rows, 'domain');

        foreach ($domains as $d) {
            $this->assertStringNotContainsString('localhost', $d, 'localhost 도메인이 제외돼야 함');
        }
    }

    public function testRefererDataExcludesEmpty(): void
    {
        $today = date('Y-m-d');
        $this->insertLog(['referer' => '']);
        $this->insertLog(['referer' => 'https://naver.com']);

        $rows = $this->buildRefererData($today, $today);
        foreach ($rows as $row) {
            $this->assertNotSame('', $row['domain'], '빈 referer는 제외돼야 함');
        }
    }

    public function testRefererDataAtMostTen(): void
    {
        $today = date('Y-m-d');
        foreach (range(1, 12) as $i) {
            $this->insertLog(['referer' => "https://domain{$i}.com/path"]);
        }
        $rows = $this->buildRefererData($today, $today);
        $this->assertLessThanOrEqual(10, count($rows));
    }

    // ── 시간대별 분포 ─────────────────────────────────────────────────────────

    public function testHourlyDataHas24Entries(): void
    {
        $today = date('Y-m-d');
        $data  = $this->buildHourlyData($today, $today);
        $this->assertCount(24, $data, '0~23시 24개 항목이어야 함');
    }

    public function testHourlyDataAreNonNegativeIntegers(): void
    {
        $today = date('Y-m-d');
        $data  = $this->buildHourlyData($today, $today);
        foreach ($data as $idx => $val) {
            $this->assertIsInt($val, "{$idx}시 값이 정수여야 함");
            $this->assertGreaterThanOrEqual(0, $val);
        }
    }

    public function testHourlyDataCountsLogsInCorrectHour(): void
    {
        $today = date('Y-m-d');
        $this->insertLog(['created_at' => $today . ' 14:30:00']);
        $this->insertLog(['created_at' => $today . ' 14:55:00']);
        $this->insertLog(['created_at' => $today . ' 09:00:00']);

        $data = $this->buildHourlyData($today, $today);

        $this->assertGreaterThanOrEqual(2, $data[14], '14시 접속 2건 이상이어야 함');
        $this->assertGreaterThanOrEqual(1, $data[9],  '9시 접속 1건 이상이어야 함');
    }
}
