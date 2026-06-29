<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccessLogModel;
use Config\Database;

class StatsController extends BaseController
{
    /** GET /admin/stats */
    public function index(): string
    {
        $db = Database::connect();

        $from = $this->request->getGet('from') ?: date('Y-m-d', strtotime('-29 days'));
        $to   = $this->request->getGet('to')   ?: date('Y-m-d');

        // 날짜 유효성 검증 및 보정
        try {
            $cursorObj = new \DateTime($from);
            $endObj    = new \DateTime($to);
        } catch (\Exception) {
            $cursorObj = new \DateTime(date('Y-m-d', strtotime('-29 days')));
            $endObj    = new \DateTime(date('Y-m-d'));
        }
        if ($cursorObj > $endObj) [$cursorObj, $endObj] = [$endObj, $cursorObj];

        // 최대 90일 제한
        if ($cursorObj->diff($endObj)->days > 90) {
            $endObj = (clone $cursorObj)->modify('+90 days');
        }

        $from   = $cursorObj->format('Y-m-d');
        $to     = $endObj->format('Y-m-d');
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to   . ' 23:59:59';

        // 일별 PV / UV — 실시간 로그 + 집계 테이블 합산
        $dailyRaw = $db->query(
            "SELECT day, SUM(pv) AS pv, SUM(uv) AS uv FROM (
                SELECT DATE(created_at) AS day, COUNT(*) AS pv, COUNT(DISTINCT ip) AS uv
                FROM access_logs
                WHERE created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
                UNION ALL
                SELECT log_date AS day, SUM(pv) AS pv, SUM(uv) AS uv
                FROM access_log_summaries
                WHERE log_date BETWEEN ? AND ?
                GROUP BY log_date
             ) t
             GROUP BY day
             ORDER BY day ASC",
            [$fromDt, $toDt, $from, $to]
        )->getResultArray();

        // 날짜 범위 전체 채우기 (데이터 없는 날 = 0)
        $dailyMap = [];
        foreach ($dailyRaw as $row) {
            $dailyMap[$row['day']] = $row;
        }
        $dailyLabels = $dailyPv = $dailyUv = [];
        $cursor = clone $cursorObj;
        while ($cursor <= $endObj) {
            $d = $cursor->format('Y-m-d');
            $dailyLabels[] = $cursor->format('m/d');
            $dailyPv[]     = (int) ($dailyMap[$d]['pv'] ?? 0);
            $dailyUv[]     = (int) ($dailyMap[$d]['uv'] ?? 0);
            $cursor->modify('+1 day');
        }

        // 페이지별 순위 (상위 20) — 두 테이블 합산
        $topPages = $db->query(
            "SELECT page, SUM(hits) AS hits, SUM(unique_visitors) AS unique_visitors FROM (
                SELECT page, COUNT(*) AS hits, COUNT(DISTINCT ip) AS unique_visitors
                FROM access_logs
                WHERE created_at BETWEEN ? AND ?
                GROUP BY page
                UNION ALL
                SELECT page, SUM(pv) AS hits, SUM(uv) AS unique_visitors
                FROM access_log_summaries
                WHERE log_date BETWEEN ? AND ?
                GROUP BY page
             ) t
             GROUP BY page
             ORDER BY hits DESC
             LIMIT 20",
            [$fromDt, $toDt, $from, $to]
        )->getResultArray();

        // 기간 요약 — 두 테이블 합산
        $summary = $db->query(
            "SELECT SUM(pv) AS total_pv, SUM(uv) AS total_uv FROM (
                SELECT COUNT(*) AS pv, COUNT(DISTINCT ip) AS uv
                FROM access_logs
                WHERE created_at BETWEEN ? AND ?
                UNION ALL
                SELECT SUM(pv) AS pv, SUM(uv) AS uv
                FROM access_log_summaries
                WHERE log_date BETWEEN ? AND ?
             ) t",
            [$fromDt, $toDt, $from, $to]
        )->getRow();

        // 인기 상품 페이지 TOP 10 (/shop/ 경로 필터)
        $topProductPages = $db->query(
            "SELECT page, COUNT(*) AS hits, COUNT(DISTINCT ip) AS unique_visitors
             FROM access_logs
             WHERE created_at BETWEEN ? AND ?
               AND page LIKE '/shop/%'
             GROUP BY page
             ORDER BY hits DESC
             LIMIT 10",
            [$fromDt, $toDt]
        )->getResultArray();

        // 유입 경로 도메인 TOP 10
        $refererData = $db->query(
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
            [$fromDt, $toDt]
        )->getResultArray();

        // 시간대별 접속 분포 (0~23시)
        $hourlyRaw = $db->query(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS hits
             FROM access_logs
             WHERE created_at BETWEEN ? AND ?
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            [$fromDt, $toDt]
        )->getResultArray();
        $hourlyMap  = array_column($hourlyRaw, 'hits', 'hour');
        $hourlyData = array_map(fn($h) => (int) ($hourlyMap[$h] ?? 0), range(0, 23));

        $todayStats = (new AccessLogModel())->getTodayStats();

        return $this->render('admin/stats/index', [
            'from'             => $from,
            'to'               => $to,
            'dailyLabels'      => $dailyLabels,
            'dailyPv'          => $dailyPv,
            'dailyUv'          => $dailyUv,
            'topPages'         => $topPages,
            'topProductPages'  => $topProductPages,
            'refererData'      => $refererData,
            'hourlyData'       => $hourlyData,
            'totalPv'          => (int) ($summary->total_pv ?? 0),
            'totalUv'          => (int) ($summary->total_uv ?? 0),
            'todayPv'          => $todayStats['pv'],
            'todayUv'          => $todayStats['uv'],
        ]);
    }
}
