<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCouponTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'                => ['type' => 'VARCHAR', 'constraint' => 50],
            'name'                => ['type' => 'VARCHAR', 'constraint' => 100],
            'type'                => ['type' => 'ENUM', 'constraint' => ['fixed', 'percent'], 'default' => 'fixed'],
            'discount_value'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'min_order_amount'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_discount_amount' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],  // 0 = 제한없음
            'total_qty'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],   // null = 무제한
            'used_count'          => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'per_user_limit'      => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'starts_at'           => ['type' => 'DATETIME', 'null' => true],
            'expires_at'          => ['type' => 'DATETIME', 'null' => true],
            'is_active'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('is_active');
        $this->forge->createTable('coupons');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'coupon_id'  => ['type' => 'INT', 'unsigned' => true],
            'order_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'source'     => ['type' => 'ENUM', 'constraint' => ['admin', 'code'], 'default' => 'admin'],
            'status'     => ['type' => 'ENUM', 'constraint' => ['issued', 'used', 'expired'], 'default' => 'issued'],
            'issued_at'  => ['type' => 'DATETIME', 'null' => true],
            'used_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addUniqueKey(['user_id', 'coupon_id']);
        $this->forge->createTable('user_coupons');
    }

    public function down()
    {
        $this->forge->dropTable('user_coupons', true);
        $this->forge->dropTable('coupons', true);
    }
}
