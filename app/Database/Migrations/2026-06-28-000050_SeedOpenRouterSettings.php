<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedOpenRouterSettings extends Migration
{
    private array $settings = [
        ['key' => 'openrouter_api_key', 'value' => '',                                      'type' => 'password'],
        ['key' => 'openrouter_model',   'value' => 'meta-llama/llama-3.1-8b-instruct:free', 'type' => 'text'],
    ];

    public function up(): void
    {
        foreach ($this->settings as $row) {
            $exists = $this->db->table('settings')->where('key', $row['key'])->countAllResults();
            if ($exists === 0) {
                $this->db->table('settings')->insert([
                    'group'      => 'api',
                    'key'        => $row['key'],
                    'value'      => $row['value'],
                    'type'       => $row['type'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->settings as $row) {
            $this->db->table('settings')->where('key', $row['key'])->delete();
        }
    }
}
