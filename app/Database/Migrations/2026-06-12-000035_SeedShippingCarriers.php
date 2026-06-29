<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedShippingCarriers extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // 이미 등록되어 있으면 중복 삽입 방지
        if ($this->db->table('settings')->where('key', 'shipping_carriers')->countAllResults() > 0) {
            return;
        }

        $this->db->table('settings')->insert([
            'group'      => 'shop',
            'key'        => 'shipping_carriers',
            'value'      => json_encode(['CJ대한통운', '한진택배', '로젠택배'], JSON_UNESCAPED_UNICODE),
            'label'      => '배송업체 목록',
            'type'       => 'carriers',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $this->db->table('settings')->where('key', 'shipping_carriers')->delete();
    }
}
