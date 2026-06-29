<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDeliveredAtAndReturnApproved extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('orders', [
            'delivered_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'return_reason',
            ],
        ]);

        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded',
                  'return_requested','return_approved')
             NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('orders', 'delivered_at');

        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded','return_requested')
             NOT NULL DEFAULT 'pending'"
        );
    }
}
