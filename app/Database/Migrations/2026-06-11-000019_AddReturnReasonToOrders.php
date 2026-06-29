<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReturnReasonToOrders extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('orders', [
            'return_reason' => [
                'type'       => 'TEXT',
                'null'       => true,
                'default'    => null,
                'after'      => 'cancelled_at',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('orders', 'return_reason');
    }
}
