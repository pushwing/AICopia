<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReturnRequestedToOrderStatus extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded','return_requested')
             NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded')
             NOT NULL DEFAULT 'pending'"
        );
    }
}
