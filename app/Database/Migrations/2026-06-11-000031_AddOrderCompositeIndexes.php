<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOrderCompositeIndexes extends Migration
{
    public function up(): void
    {
        // getByUser(): WHERE user_id = ? ORDER BY id DESC
        $this->db->query('ALTER TABLE orders ADD INDEX idx_orders_user_id (user_id, id)');
        // adminGetAll(): WHERE status = ? ORDER BY id DESC
        $this->db->query('ALTER TABLE orders ADD INDEX idx_orders_status_id (status, id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE orders DROP INDEX idx_orders_user_id');
        $this->db->query('ALTER TABLE orders DROP INDEX idx_orders_status_id');
    }
}
