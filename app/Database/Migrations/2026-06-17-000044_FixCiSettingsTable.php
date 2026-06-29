<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * codeigniter4/settings 라이브러리 테이블을 ci_settings로 분리.
 * 기존 migrations(batch 25)가 앱의 settings 테이블에 context 컬럼을 잘못 추가했으므로 제거.
 */
class FixCiSettingsTable extends Migration
{
    public function up(): void
    {
        // ci_settings: codeigniter4/settings 라이브러리 전용 테이블
        if (! $this->db->tableExists('ci_settings')) {
            $this->forge->addField('id');
            $this->forge->addField([
                'class'      => ['type' => 'varchar', 'constraint' => 255],
                'key'        => ['type' => 'varchar', 'constraint' => 255],
                'value'      => ['type' => 'text', 'null' => true],
                'type'       => ['type' => 'varchar', 'constraint' => 31, 'default' => 'string'],
                'context'    => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
                'created_at' => ['type' => 'datetime'],
                'updated_at' => ['type' => 'datetime'],
            ]);
            $this->forge->createTable('ci_settings');
        }

        // 앱 settings 테이블에서 라이브러리가 잘못 추가한 context 컬럼 제거
        if ($this->db->fieldExists('context', 'settings')) {
            $this->forge->dropColumn('settings', 'context');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('ci_settings', true);

        if (! $this->db->fieldExists('context', 'settings')) {
            $this->forge->addColumn('settings', [
                'context' => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            ]);
        }
    }
}
