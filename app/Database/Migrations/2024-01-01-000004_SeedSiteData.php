<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedSiteData extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        // 기본 사이트 설정
        $this->db->table('settings')->insertBatch([
            ['group' => 'general', 'key' => 'site_name',    'value' => '내 회사',           'label' => '사이트명',      'type' => 'text',    'updated_at' => $now],
            ['group' => 'general', 'key' => 'site_desc',    'value' => '믿을 수 있는 파트너', 'label' => '사이트 설명',   'type' => 'textarea','updated_at' => $now],
            ['group' => 'general', 'key' => 'site_logo',    'value' => '',                  'label' => '로고 이미지',   'type' => 'image',   'updated_at' => $now],
            ['group' => 'general', 'key' => 'favicon',      'value' => '',                  'label' => '파비콘',        'type' => 'image',   'updated_at' => $now],
            ['group' => 'contact', 'key' => 'phone',        'value' => '02-0000-0000',      'label' => '전화번호',      'type' => 'text',    'updated_at' => $now],
            ['group' => 'contact', 'key' => 'email',        'value' => 'contact@example.com','label' => '이메일',       'type' => 'text',    'updated_at' => $now],
            ['group' => 'contact', 'key' => 'address',      'value' => '서울시 강남구',      'label' => '주소',          'type' => 'text',    'updated_at' => $now],
            ['group' => 'contact', 'key' => 'kakao',        'value' => '',                  'label' => '카카오채널',    'type' => 'text',    'updated_at' => $now],
            ['group' => 'sns',     'key' => 'instagram',    'value' => '',                  'label' => '인스타그램',    'type' => 'text',    'updated_at' => $now],
            ['group' => 'sns',     'key' => 'youtube',      'value' => '',                  'label' => '유튜브',        'type' => 'text',    'updated_at' => $now],
            ['group' => 'sns',     'key' => 'blog',         'value' => '',                  'label' => '블로그',        'type' => 'text',    'updated_at' => $now],
            ['group' => 'seo',     'key' => 'ga_id',        'value' => '',                  'label' => 'GA 측정 ID',   'type' => 'text',    'updated_at' => $now],
            ['group' => 'seo',     'key' => 'naver_verify', 'value' => '',                  'label' => '네이버 인증',   'type' => 'text',    'updated_at' => $now],
            ['group' => 'footer',  'key' => 'copyright',    'value' => '© 2025 내 회사. All rights reserved.', 'label' => '저작권', 'type' => 'text', 'updated_at' => $now],
            ['group' => 'footer',  'key' => 'business_num', 'value' => '000-00-00000',      'label' => '사업자번호',    'type' => 'text',    'updated_at' => $now],
        ]);

        // 기본 페이지
        $this->db->table('pages')->insertBatch([
            [
                'slug'       => 'about',
                'title'      => '회사소개',
                'content'    => '<h2>우리는 최고의 파트너입니다</h2><p>회사 소개 내용을 여기에 작성하세요.</p>',
                'layout'     => 'default',
                'meta_title' => '회사소개 | 내 회사',
                'meta_desc'  => '믿을 수 있는 파트너, 내 회사를 소개합니다.',
                'sort_order' => 1,
                'status'     => 'published',
                'created_at' => $now,
            ],
            [
                'slug'       => 'service',
                'title'      => '서비스',
                'content'    => '<h2>우리의 서비스</h2><p>서비스 내용을 작성하세요.</p>',
                'layout'     => 'default',
                'meta_title' => '서비스 | 내 회사',
                'meta_desc'  => '내 회사의 서비스를 안내합니다.',
                'sort_order' => 2,
                'status'     => 'published',
                'created_at' => $now,
            ],
            [
                'slug'       => 'contact',
                'title'      => '문의하기',
                'content'    => '',
                'layout'     => 'contact',
                'meta_title' => '문의하기 | 내 회사',
                'meta_desc'  => '내 회사에 문의하세요.',
                'sort_order' => 3,
                'status'     => 'published',
                'created_at' => $now,
            ],
        ]);

        // 기본 메뉴
        $this->db->table('menus')->insertBatch([
            ['parent_id' => null, 'title' => '홈',      'url' => '/',          'sort_order' => 0, 'is_active' => 1],
            ['parent_id' => null, 'title' => '회사소개', 'url' => '/about',     'sort_order' => 1, 'is_active' => 1],
            ['parent_id' => null, 'title' => '서비스',   'url' => '/service',   'sort_order' => 2, 'is_active' => 1],
            ['parent_id' => null, 'title' => '공지사항', 'url' => '/board/notice', 'sort_order' => 3, 'is_active' => 1],
            ['parent_id' => null, 'title' => '문의하기', 'url' => '/contact',   'sort_order' => 4, 'is_active' => 1],
        ]);
    }

    public function down()
    {
        $this->db->table('settings')->truncate();
        $this->db->table('pages')->truncate();
        $this->db->table('menus')->truncate();
    }
}
