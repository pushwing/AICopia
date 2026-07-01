<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLandingFieldsToCategories extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('categories', [
            // 카테고리 소개 카피(랜딩 상단 노출)
            'description' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'name',
            ],
            // FAQ 목록 JSON: [{"question": "...", "answer": "..."}, ...]
            'faq' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'description',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('categories', ['description', 'faq']);
    }
}
