<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class WithdrawnUserModel extends Model
{
    /** 보관기간 경과 시 NULL 로 비우는 개인정보 컬럼 */
    public const PERSONAL_COLUMNS = [
        'username', 'email', 'nickname', 'phone', 'gender', 'birthday',
        'avatar', 'social_provider', 'social_id', 'reason_text',
    ];

    protected $table         = 'withdrawn_users';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id',
        'username', 'email', 'nickname', 'phone', 'gender', 'birthday',
        'avatar', 'social_provider', 'social_id', 'reason_text',
        'grade', 'point_balance', 'coupon_count', 'order_count', 'joined_at',
        'reason_code', 'withdrawn_by', 'withdrawn_at', 'purged_at',
    ];

    /**
     * 탈퇴 회원의 개인정보 스냅샷 저장
     *
     * @param array<string, mixed> $user 마스킹 전 users 행
     * @param array{point_balance: int, coupon_count: int, order_count: int} $meta
     * @return int 생성된 withdrawn_users.id
     */
    public function snapshot(array $user, array $meta, string $reasonCode, ?string $reasonText, string $by): int
    {
        $this->insert([
            'user_id'         => (int) $user['id'],
            'username'        => $user['username'] ?? null,
            'email'           => $user['email'] ?? null,
            'nickname'        => $user['nickname'] ?? null,
            'phone'           => $user['phone'] ?? null,
            'gender'          => $user['gender'] ?? null,
            'birthday'        => $user['birthday'] ?? null,
            'avatar'          => $user['avatar'] ?? null,
            'social_provider' => $user['social_provider'] ?? null,
            'social_id'       => $user['social_id'] ?? null,
            'reason_text'     => $reasonText,
            'grade'           => $user['grade'] ?? null,
            'point_balance'   => $meta['point_balance'],
            'coupon_count'    => $meta['coupon_count'],
            'order_count'     => $meta['order_count'],
            'joined_at'       => $user['created_at'] ?? null,
            'reason_code'     => $reasonCode,
            'withdrawn_by'    => $by,
            'withdrawn_at'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->getInsertID();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->where('user_id', $userId)->orderBy('id', 'DESC')->first();
    }

    /**
     * 보관기간이 지난 행의 개인정보 컬럼을 NULL 로 비우고 purged_at 기록
     *
     * 행 자체는 지우지 않는다 — 탈퇴 사유·시점 통계는 남아야 한다.
     *
     * @return int 파기한 행 수
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $update = array_fill_keys(self::PERSONAL_COLUMNS, null);
        $update['purged_at'] = date('Y-m-d H:i:s');
        $update['updated_at'] = date('Y-m-d H:i:s');

        $builder = $this->builder()
            ->where('withdrawn_at <', $cutoff)
            ->where('purged_at IS NULL');

        $builder->update($update);

        return $this->db->affectedRows();
    }

    /**
     * 관리자 목록 — 검색어는 이메일·닉네임에만 건다(파기된 행은 NULL 이라 검색되지 않는다)
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginateList(string $keyword, int $page, int $perPage): array
    {
        $builder = $this->builder();

        if ($keyword !== '') {
            $builder->groupStart()
                ->like('email', $keyword)
                ->orLike('nickname', $keyword)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('withdrawn_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }
}
