<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCarriersToSettingsType extends Migration
{
    public function up(): void
    {
        // ENUM에 'carriers' 추가
        $this->db->query(
            "ALTER TABLE settings MODIFY COLUMN type ENUM('text','textarea','image','boolean','carriers') NOT NULL DEFAULT 'text'"
        );

        // shipping_carriers 행의 type 설정
        $this->db->table('settings')
            ->where('key', 'shipping_carriers')
            ->update(['type' => 'carriers']);
    }

    public function down(): void
    {
        $this->db->table('settings')
            ->where('key', 'shipping_carriers')
            ->update(['type' => 'text']);

        $this->db->query(
            "ALTER TABLE settings MODIFY COLUMN type ENUM('text','textarea','image','boolean') NOT NULL DEFAULT 'text'"
        );
    }
}
