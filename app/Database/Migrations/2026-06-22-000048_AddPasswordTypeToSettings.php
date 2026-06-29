<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPasswordTypeToSettings extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE settings MODIFY COLUMN `type` ENUM('text','textarea','image','boolean','carriers','select','password') NOT NULL DEFAULT 'text'"
        );
        $this->db->table('settings')
            ->where('key', 'smtp_pass')
            ->update(['type' => 'password']);
    }

    public function down(): void
    {
        $this->db->table('settings')
            ->where('key', 'smtp_pass')
            ->update(['type' => 'text']);
        $this->db->query(
            "ALTER TABLE settings MODIFY COLUMN `type` ENUM('text','textarea','image','boolean','carriers','select') NOT NULL DEFAULT 'text'"
        );
    }
}
