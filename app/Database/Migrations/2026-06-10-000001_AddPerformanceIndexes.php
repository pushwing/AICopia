<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPerformanceIndexes extends Migration
{
    public function up()
    {
        // 게시판 목록·카운트: WHERE board_id + is_notice ORDER BY id DESC
        $this->db->query('ALTER TABLE posts ADD INDEX idx_posts_board_notice_id (board_id, is_notice, id)');

        // 소프트 삭제 필터 (모든 posts 쿼리에 deleted_at IS NULL 포함)
        $this->db->query('ALTER TABLE posts ADD INDEX idx_posts_deleted_at (deleted_at)');

        // 댓글 소프트 삭제 필터
        $this->db->query('ALTER TABLE post_comments ADD INDEX idx_comments_deleted_at (deleted_at)');

        // 배너·팝업 활성 조회 (캐시 미스 시)
        $this->db->query('ALTER TABLE banners ADD INDEX idx_banners_position_active (position, is_active)');
        $this->db->query('ALTER TABLE popups ADD INDEX idx_popups_active_scope (is_active, show_scope)');

        // 관리자 미읽음 문의 카운트 (관리자 모든 페이지에서 실행)
        $this->db->query('ALTER TABLE inquiries ADD INDEX idx_inquiries_is_read (is_read)');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE posts DROP INDEX idx_posts_board_notice_id');
        $this->db->query('ALTER TABLE posts DROP INDEX idx_posts_deleted_at');
        $this->db->query('ALTER TABLE post_comments DROP INDEX idx_comments_deleted_at');
        $this->db->query('ALTER TABLE banners DROP INDEX idx_banners_position_active');
        $this->db->query('ALTER TABLE popups DROP INDEX idx_popups_active_scope');
        $this->db->query('ALTER TABLE inquiries DROP INDEX idx_inquiries_is_read');
    }
}
