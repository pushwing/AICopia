<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * OrderController::create()의 "배송지 저장" 체크박스가 첫 주소를 기본
 * 배송지(is_default)로 지정하지 않던 버그(#178에서 코드는 수정됨) 를 배포
 * 이전에 저장된 기존 행에 소급 적용한다.
 *
 * 기본 배송지가 하나도 없는 사용자에 한해, 가장 먼저 저장된(최소 id) 주소를
 * 기본으로 승격시킨다. 이미 기본 배송지가 있는 사용자는 건드리지 않는다.
 */
class BackfillShippingAddressDefault extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE shipping_addresses sa
             JOIN (
                 SELECT user_id, MIN(id) AS first_id
                 FROM shipping_addresses
                 WHERE user_id NOT IN (
                     SELECT user_id FROM shipping_addresses WHERE is_default = 1
                 )
                 GROUP BY user_id
             ) first_addr ON first_addr.first_id = sa.id
             SET sa.is_default = 1, sa.updated_at = ?',
            [$now],
        );
    }

    public function down(): void
    {
        // 데이터 보정 마이그레이션 — 되돌리면 버그 상태(기본 배송지 없음)로 회귀하므로 no-op.
    }
}
