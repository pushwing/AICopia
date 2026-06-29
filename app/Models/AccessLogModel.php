<?php

namespace App\Models;

use CodeIgniter\Model;

class AccessLogModel extends Model
{
    protected $table         = 'access_logs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = ['ip', 'page', 'url', 'user_id', 'user_agent', 'referer', 'created_at'];

    /** 오늘 PV / UV — 대시보드·통계 공용 */
    public function getTodayStats(): array
    {
        $today = date('Y-m-d');
        $pv    = $this->where('DATE(created_at)', $today)->countAllResults();
        $uv    = (int) $this->db->query(
            'SELECT COUNT(DISTINCT ip) cnt FROM access_logs WHERE DATE(created_at) = ?',
            [$today]
        )->getRow()->cnt;

        return ['pv' => $pv, 'uv' => $uv];
    }

    /** 보존 기간(일) 이전 로그를 집계 테이블에 저장 후 삭제 */
    public function purgeOlderThan(int $days = 90): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // 삭제 대상 로그를 일별·페이지별로 집계해 access_log_summaries에 누적
        $this->db->query("
            INSERT INTO access_log_summaries (log_date, page, pv, uv)
            SELECT DATE(created_at), page, COUNT(*) AS pv, COUNT(DISTINCT ip) AS uv
            FROM access_logs
            WHERE created_at < ?
            GROUP BY DATE(created_at), page
            ON DUPLICATE KEY UPDATE
                pv = pv + VALUES(pv),
                uv = uv + VALUES(uv)
        ", [$cutoff]);

        $this->where('created_at <', $cutoff)->delete();
        return $this->db->affectedRows();
    }
}
