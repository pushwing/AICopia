<?php

namespace App\Libraries;

/**
 * 회원 등급 관련 로직 중앙 관리
 * - 상수 (등급 목록, 레이블, 배지 색상)
 * - 등급별 포인트 적립률
 * - 승급 여부 판단 + DB 반영 + 보너스 지급
 */
class GradeService
{
    public const GRADES = ['bronze', 'silver', 'gold', 'platinum'];

    public const LABELS = [
        'bronze'   => '브론즈',
        'silver'   => '실버',
        'gold'     => '골드',
        'platinum' => '플래티넘',
    ];

    // Badge 클래스 (bronze·silver 는 커스텀, gold·platinum 은 Bootstrap)
    public const BADGE_CLASSES = [
        'bronze'   => 'badge-bronze',
        'silver'   => 'badge-silver',
        'gold'     => 'bg-warning text-dark',
        'platinum' => 'bg-primary',
    ];

    // 아이콘
    public const ICONS = [
        'bronze'   => 'bi-award',
        'silver'   => 'bi-award-fill',
        'gold'     => 'bi-trophy',
        'platinum' => 'bi-trophy-fill',
    ];

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    // ------------------------------------------------------------------ //
    //  등급별 적립률
    // ------------------------------------------------------------------ //

    public function getEarnRate(string $grade, array $settings): float
    {
        $key = 'point_earn_rate_' . $grade;
        return max(0, (float) ($settings[$key] ?? 1));
    }

    // ------------------------------------------------------------------ //
    //  보너스 포인트
    // ------------------------------------------------------------------ //

    public function getSignupBonus(array $settings): int
    {
        return max(0, (int) ($settings['point_bonus_signup'] ?? 1000));
    }

    public function getGradeBonus(string $grade, array $settings): int
    {
        $key = 'point_bonus_' . $grade;
        return max(0, (int) ($settings[$key] ?? 0));
    }

    // ------------------------------------------------------------------ //
    //  승급 체크 (Bronze → Silver, Silver → Gold 만 자동)
    //  Platinum 은 어드민 수동 선정
    // ------------------------------------------------------------------ //

    /**
     * 주문 배송완료 후 호출. 승급 여부 확인 후 DB 반영 + 보너스 지급.
     * @return string|null 새 등급 (승급 없으면 null)
     */
    public function checkAndUpgrade(int $userId, array $settings): ?string
    {
        $user = $this->db->table('users')
            ->select('grade')
            ->where('id', $userId)
            ->get()->getRowArray();

        if (! $user) return null;

        $grade = $user['grade'];

        // platinum, gold 는 자동 승급 없음
        if (in_array($grade, ['gold', 'platinum'], true)) return null;

        $stats = $this->getOrderStats($userId);

        $newGrade = null;

        if ($grade === 'bronze') {
            $reqOrders = (int) ($settings['grade_silver_orders'] ?? 10);
            $reqAmount = (int) ($settings['grade_silver_amount'] ?? 100000);
            if ($stats['count'] >= $reqOrders && $stats['amount'] >= $reqAmount) {
                $newGrade = 'silver';
            }
        } elseif ($grade === 'silver') {
            $reqOrders = (int) ($settings['grade_gold_orders'] ?? 20);
            $reqAmount = (int) ($settings['grade_gold_amount'] ?? 200000);
            if ($stats['count'] >= $reqOrders && $stats['amount'] >= $reqAmount) {
                $newGrade = 'gold';
            }
        }

        if ($newGrade) {
            $this->applyUpgrade($userId, $newGrade, $settings);
        }

        return $newGrade;
    }

    /**
     * 등급 변경 DB 반영 + 보너스 포인트 지급 + 로그 + 승급 쿠폰 발급
     */
    public function applyUpgrade(int $userId, string $newGrade, array $settings): void
    {
        $bonus = $this->getGradeBonus($newGrade, $settings);
        $now   = date('Y-m-d H:i:s');

        $this->db->transStart();

        $this->db->table('users')->where('id', $userId)->update([
            'grade'      => $newGrade,
            'updated_at' => $now,
        ]);

        if ($bonus > 0) {
            $this->db->query(
                'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
                [$bonus, $userId]
            );
            $this->db->table('point_logs')->insert([
                'user_id'    => $userId,
                'type'       => 'admin',
                'amount'     => $bonus,
                'order_id'   => null,
                'note'       => self::LABELS[$newGrade] . ' 등급 승급 보너스',
                'created_at' => $now,
            ]);
        }

        $this->db->transComplete();

        // 승급 쿠폰 발급 (트랜잭션 밖 — 실패해도 승급은 유지)
        $this->issueGradeCoupon($userId, $newGrade, $settings, $now);
    }

    /**
     * 등급 승급 쿠폰 발급 — 설정에 coupon_grade_{grade}_id 가 있을 때만 발급
     */
    public function issueGradeCoupon(int $userId, string $grade, array $settings, string $now = ''): void
    {
        if ($now === '') $now = date('Y-m-d H:i:s');

        $couponId = (int) ($settings['coupon_grade_' . $grade . '_id'] ?? 0);
        if ($couponId <= 0) return;

        $coupon = $this->db->table('coupons')->where('id', $couponId)->where('is_active', 1)->get()->getRowArray();
        if (! $coupon) return;

        // 중복 발급 방지 — 같은 쿠폰이 이미 issued 상태로 있으면 스킵
        $exists = $this->db->table('user_coupons')
            ->where('user_id', $userId)
            ->where('coupon_id', $couponId)
            ->where('status', 'issued')
            ->countAllResults();
        if ($exists > 0) return;

        // total_qty 체크
        if ($coupon['total_qty'] !== null && (int) $coupon['used_count'] >= (int) $coupon['total_qty']) return;

        $this->db->table('user_coupons')->insert([
            'user_id'    => $userId,
            'coupon_id'  => $couponId,
            'source'     => 'admin',
            'status'     => 'issued',
            'issued_at'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        log_message('info', "[GradeService] user_id={$userId} 승급쿠폰 발급 coupon_id={$couponId} grade={$grade}");
    }

    /**
     * 가입 보너스 포인트 지급 (이메일 인증 완료 또는 소셜 가입 직후)
     */
    public function awardSignupBonus(int $userId, array $settings): void
    {
        $bonus = $this->getSignupBonus($settings);
        if ($bonus <= 0) return;

        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE users SET point_balance = point_balance + ? WHERE id = ?',
            [$bonus, $userId]
        );
        $this->db->table('point_logs')->insert([
            'user_id'    => $userId,
            'type'       => 'admin',
            'amount'     => $bonus,
            'order_id'   => null,
            'note'       => '신규 가입 보너스',
            'created_at' => $now,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  플래티넘 선정용 골드 회원 통계
    // ------------------------------------------------------------------ //

    /**
     * 골드 회원 목록 + 주문 통계 (플래티넘 선정 화면용)
     */
    public function getGoldMembersForPromotion(string $keyword = '', int $page = 1, int $perPage = 20): array
    {
        $builder = $this->db->table('users u')
            ->select("
                u.id, u.email, u.nickname, u.phone, u.grade, u.created_at,
                COALESCE(s.order_count, 0) AS order_count,
                COALESCE(s.total_amount, 0) AS total_amount,
                TIMESTAMPDIFF(YEAR, u.created_at, NOW()) AS years_since_signup
            ")
            ->join("(
                SELECT o.user_id,
                       COUNT(*) AS order_count,
                       SUM(o.payable_amount) AS total_amount
                FROM orders o
                WHERE o.status IN ('paid','preparing','shipped','delivered')
                GROUP BY o.user_id
            ) s", 's.user_id = u.id', 'left')
            ->where('u.grade', 'gold');

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('u.nickname', $keyword)
                ->orLike('u.email', $keyword)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $items = $builder->orderBy('total_amount', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return [
            'items'       => $items,
            'total'       => $total,
            'totalPages'  => (int) ceil($total / $perPage),
            'currentPage' => $page,
        ];
    }

    // ------------------------------------------------------------------ //
    //  내부 헬퍼
    // ------------------------------------------------------------------ //

    private function getOrderStats(int $userId): array
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS order_count, COALESCE(SUM(payable_amount), 0) AS total_amount
            FROM orders
            WHERE user_id = ?
              AND status IN ('paid','preparing','shipped','delivered')
        ", [$userId])->getRowArray();

        return [
            'count'  => (int) ($row['order_count'] ?? 0),
            'amount' => (int) ($row['total_amount'] ?? 0),
        ];
    }
}
