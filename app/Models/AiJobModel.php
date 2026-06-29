<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * AI 작업 큐.
 *
 * 무거운 AI 호출(리뷰 요약 등)을 즉시 처리하지 않고 큐에 적재한 뒤,
 * ai:work 워커(크론)가 백그라운드에서 처리한다. 관리자/프론트 요청을 막지 않는다.
 *
 * 상태 흐름: pending → processing → done
 *                              ↘ (실패) → pending(재시도 backoff) → … → failed(소진)
 */
class AiJobModel extends Model
{
    protected $table         = 'ai_jobs';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false; // 직접 관리 (worker_token·processed_at 등과 함께)
    protected $allowedFields = [
        'type', 'payload', 'status', 'result', 'attempts', 'max_attempts',
        'error', 'worker_token', 'available_at', 'created_at', 'updated_at', 'processed_at',
    ];

    /** 작업을 큐에 적재하고 job id를 반환한다. */
    public function enqueue(string $type, array $payload = [], int $maxAttempts = 3): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->insert([
            'type'         => $type,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * 처리 가능한 작업 1건을 원자적으로 선점하여 반환한다.
     * 토큰을 부여한 conditional UPDATE로 동시 워커 간 중복 처리를 방지한다.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $now   = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(16));

        $this->db->query(
            "UPDATE {$this->table}
             SET status = 'processing', worker_token = ?, updated_at = ?
             WHERE status = 'pending' AND (available_at IS NULL OR available_at <= ?)
             ORDER BY id ASC
             LIMIT 1",
            [$token, $now, $now]
        );

        if ($this->db->affectedRows() < 1) {
            return null;
        }

        return $this->where('worker_token', $token)
            ->where('status', 'processing')
            ->first();
    }

    /** 작업 성공 처리 — 결과 저장. */
    public function markDone(int $id, array $result): bool
    {
        $now = date('Y-m-d H:i:s');

        return $this->update($id, [
            'status'       => 'done',
            'result'       => json_encode($result, JSON_UNESCAPED_UNICODE),
            'error'        => null,
            'worker_token' => null,
            'updated_at'   => $now,
            'processed_at' => $now,
        ]);
    }

    /**
     * 작업 실패 처리.
     * 시도 횟수가 max_attempts 미만이면 backoff 후 재시도(pending)로, 이상이면 failed로 전환.
     */
    public function markFailed(int $id, string $error): bool
    {
        $job = $this->find($id);
        if ($job === null) {
            return false;
        }

        $attempts = (int) $job['attempts'] + 1;
        $now      = date('Y-m-d H:i:s');
        $error    = mb_substr($error, 0, 500);

        if ($attempts >= (int) $job['max_attempts']) {
            return $this->update($id, [
                'status'       => 'failed',
                'attempts'     => $attempts,
                'error'        => $error,
                'worker_token' => null,
                'updated_at'   => $now,
                'processed_at' => $now,
            ]);
        }

        // 지수적 backoff: 시도당 60초 * 2^(attempts-1)
        $delaySec = 60 * (2 ** ($attempts - 1));

        return $this->update($id, [
            'status'       => 'pending',
            'attempts'     => $attempts,
            'error'        => $error,
            'worker_token' => null,
            'available_at' => date('Y-m-d H:i:s', time() + $delaySec),
            'updated_at'   => $now,
        ]);
    }
}
