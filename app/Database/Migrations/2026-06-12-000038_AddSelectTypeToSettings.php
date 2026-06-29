<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSelectTypeToSettings extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE settings MODIFY type ENUM('text','textarea','image','boolean','carriers','select') NOT NULL DEFAULT 'text'");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE settings MODIFY type ENUM('text','textarea','image','boolean','carriers') NOT NULL DEFAULT 'text'");
    }
}
