<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedCouponPointSettings extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('settings')->insertBatch([
            [
                'group'      => 'shop',
                'key'        => 'point_earn_rate',
                'value'      => '1',
                'label'      => '포인트 적립률 (%)',
                'type'       => 'text',
                'updated_at' => $now,
            ],
            [
                'group'      => 'shop',
                'key'        => 'min_payable_amount',
                'value'      => '10000',
                'label'      => '최소 결제 금액 (원)',
                'type'       => 'text',
                'updated_at' => $now,
            ],
        ]);
    }

    public function down()
    {
        $this->db->table('settings')
            ->whereIn('key', ['point_earn_rate', 'min_payable_amount'])
            ->delete();
    }
}
