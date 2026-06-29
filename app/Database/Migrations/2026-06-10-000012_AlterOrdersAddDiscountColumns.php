<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterOrdersAddDiscountColumns extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'coupon_id'              => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'total_amount'],
            'coupon_discount_amount' => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'coupon_id'],
            'point_used_amount'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'coupon_discount_amount'],
            'point_earned_amount'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'point_used_amount'],
            'payable_amount'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'after' => 'point_earned_amount'],
        ]);

        // 기존 주문은 payable_amount = total_amount 로 초기화
        $this->db->query('UPDATE orders SET payable_amount = total_amount WHERE payable_amount = 0');
    }

    public function down()
    {
        $this->forge->dropColumn('orders', 'payable_amount');
        $this->forge->dropColumn('orders', 'point_earned_amount');
        $this->forge->dropColumn('orders', 'point_used_amount');
        $this->forge->dropColumn('orders', 'coupon_discount_amount');
        $this->forge->dropColumn('orders', 'coupon_id');
    }
}
