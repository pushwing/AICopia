<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedPromotions extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $db  = \Config\Database::connect();

        // 상품 ID 조회 (없으면 시드 스킵)
        $productIds = array_column(
            $db->table('products')->select('id')->orderBy('id')->limit(8)->get()->getResultArray(),
            'id'
        );

        if (empty($productIds)) {
            return;
        }

        // ── 기획전 3개 ──────────────────────────────────────────────────────────
        $promotions = [
            [
                'title'       => '여름 신상 기획전',
                'slug'        => 'summer-new-arrivals',
                'description' => '<p>시원하고 스타일리시한 여름 신상품을 모았습니다.</p>',
                'grade_access'=> 'all',
                'start_date'  => date('Y-m-d'),
                'end_date'    => date('Y-m-d', strtotime('+30 days')),
                'is_active'   => 1,
                'sort_order'  => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'title'       => 'VIP 단독 특가전',
                'slug'        => 'vip-exclusive-sale',
                'description' => '<p>VIP 회원만을 위한 특별 할인 상품입니다.</p>',
                'grade_access'=> 'vip',
                'start_date'  => date('Y-m-d'),
                'end_date'    => date('Y-m-d', strtotime('+14 days')),
                'is_active'   => 1,
                'sort_order'  => 2,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'title'       => '봄 시즌 오프',
                'slug'        => 'spring-season-off',
                'description' => '<p>봄 시즌 재고 정리 할인 이벤트입니다.</p>',
                'grade_access'=> 'all',
                'start_date'  => date('Y-m-d', strtotime('-60 days')),
                'end_date'    => date('Y-m-d', strtotime('-1 day')),
                'is_active'   => 0,
                'sort_order'  => 3,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ];

        $db->table('promotions')->insertBatch($promotions);
        $firstId = (int) $db->insertID();

        // ── 기획전별 상품 연결 ──────────────────────────────────────────────────
        $half    = (int) ceil(count($productIds) / 2);
        $first4  = array_slice($productIds, 0, $half);
        $last4   = array_slice($productIds, $half);

        $pp = [];
        foreach ($first4 as $i => $pid) {
            $pp[] = ['promotion_id' => $firstId,     'product_id' => $pid, 'sort_order' => $i + 1];
        }
        foreach ($last4 as $i => $pid) {
            $pp[] = ['promotion_id' => $firstId + 1, 'product_id' => $pid, 'sort_order' => $i + 1];
        }
        foreach ($first4 as $i => $pid) {
            $pp[] = ['promotion_id' => $firstId + 2, 'product_id' => $pid, 'sort_order' => $i + 1];
        }

        if (! empty($pp)) {
            $db->table('promotion_products')->insertBatch($pp);
        }
    }

    public function down(): void
    {
        $db = \Config\Database::connect();
        $slugs = ['summer-new-arrivals', 'vip-exclusive-sale', 'spring-season-off'];

        $ids = array_column(
            $db->table('promotions')->select('id')->whereIn('slug', $slugs)->get()->getResultArray(),
            'id'
        );

        if (! empty($ids)) {
            $db->table('promotion_products')->whereIn('promotion_id', $ids)->delete();
            $db->table('promotions')->whereIn('id', $ids)->delete();
        }
    }
}
