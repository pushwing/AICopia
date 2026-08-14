<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 홈페이지 "기획전" 섹션명을 "PICK 상품"으로 변경.
 *
 * 상품 관리 ⭐(is_featured)로 지정하는 이 섹션과, 완전히 별개 기능인 프로모션
 * 캠페인(/admin/promotions, 별도 랜딩페이지)이 둘 다 "기획전"이라 불려 운영자가
 * 혼동했다 — 프로모션에 상품을 등록해도 이 섹션엔 자동으로 반영되지 않는다.
 *
 * welcome_featured_title 값이 옛 기본값 '기획전' 그대로인 설치만 새 기본값으로
 * 옮기고, 관리자가 이미 직접 다른 제목으로 바꿔둔 경우는 건드리지 않는다.
 */
class RenameWelcomeFeaturedToPick extends Migration
{
    public function up(): void
    {
        $this->db->table('settings')
            ->where('key', 'welcome_featured_title')
            ->where('value', '기획전')
            ->update(['value' => 'PICK 상품', 'updated_at' => date('Y-m-d H:i:s')]);

        // saveSettings()를 거치지 않는 직접 UPDATE라 site_settings 캐시(최대 1시간)가
        // 안 지워지면 프론트에 옛 제목이 계속 보인다 — 마이그레이션에서 직접 무효화.
        cache()->delete('site_settings');
    }

    public function down(): void
    {
        $this->db->table('settings')
            ->where('key', 'welcome_featured_title')
            ->where('value', 'PICK 상품')
            ->update(['value' => '기획전', 'updated_at' => date('Y-m-d H:i:s')]);

        cache()->delete('site_settings');
    }
}
