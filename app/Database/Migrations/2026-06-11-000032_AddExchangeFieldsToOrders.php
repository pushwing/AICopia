<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddExchangeFieldsToOrders extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('orders', [
            'exchange_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
                'default'    => null,
                'after'      => 'return_reason',
            ],
            'exchange_request_note' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
                'after'   => 'exchange_reason',
            ],
        ]);

        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded',
                  'return_requested','return_approved',
                  'exchange_requested','exchange_approved','exchange_completed')
             NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('orders', 'exchange_reason');
        $this->forge->dropColumn('orders', 'exchange_request_note');

        $this->db->query(
            "ALTER TABLE orders MODIFY COLUMN status
             ENUM('pending','awaiting_payment','paid','preparing','shipped','delivered',
                  'cancelled','expired','refund_requested','refunded',
                  'return_requested','return_approved')
             NOT NULL DEFAULT 'pending'"
        );
    }
}
