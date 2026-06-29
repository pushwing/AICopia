<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PostSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $posts = [
            // 공지사항 (board_id=1)
            [
                'board_id'    => 1,
                'user_id'     => 1,
                'title'       => '서비스 이용약관 안내',
                'content'     => '<p>서비스 이용약관이 업데이트되었습니다. 자세한 내용은 본문을 확인해 주세요.</p>',
                'author_name' => '관리자',
                'is_notice'   => 1,
                'is_secret'   => 0,
                'views'       => 320,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 1,
                'user_id'     => 1,
                'title'       => '개인정보 처리방침 개정 안내',
                'content'     => '<p>2026년 6월 1일부터 개인정보 처리방침이 개정됩니다.</p>',
                'author_name' => '관리자',
                'is_notice'   => 1,
                'is_secret'   => 0,
                'views'       => 218,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 1,
                'user_id'     => 1,
                'title'       => '시스템 정기점검 안내 (6/15 02:00~04:00)',
                'content'     => '<p>정기점검으로 인해 6월 15일 새벽 2시부터 4시까지 서비스가 중단됩니다.</p>',
                'author_name' => '관리자',
                'is_notice'   => 1,
                'is_secret'   => 0,
                'views'       => 154,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            // 자유게시판 (board_id=2)
            [
                'board_id'    => 2,
                'user_id'     => 1,
                'title'       => '첫 구매 후기 남겨요!',
                'content'     => '<p>처음 구매했는데 배송도 빠르고 상품 품질이 너무 좋아요. 재구매 의사 있습니다!</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 0,
                'views'       => 87,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 2,
                'user_id'     => 1,
                'title'       => '여름 신상 언제 나오나요?',
                'content'     => '<p>여름 시즌 신상품 출시 예정이 언제인지 궁금합니다.</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 0,
                'views'       => 45,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 2,
                'user_id'     => 1,
                'title'       => '사이즈 추천 부탁드려요',
                'content'     => '<p>평소 M 사이즈 입는데 오버사이즈 후드 집업은 어떤 사이즈가 좋을까요?</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 0,
                'views'       => 63,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            // 문의게시판 (board_id=3)
            [
                'board_id'    => 3,
                'user_id'     => 1,
                'title'       => '교환 및 반품 기간 문의',
                'content'     => '<p>상품 수령 후 교환이나 반품은 며칠 이내에 신청해야 하나요?</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 0,
                'views'       => 39,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 3,
                'user_id'     => 1,
                'title'       => '해외 배송 가능한가요?',
                'content'     => '<p>해외(일본)로 배송이 가능한지 문의드립니다.</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 1,
                'views'       => 12,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'board_id'    => 3,
                'user_id'     => 1,
                'title'       => '결제 오류 문의',
                'content'     => '<p>카드 결제 중 오류가 발생했는데 결제가 된 건지 확인 부탁드립니다.</p>',
                'author_name' => '관리자',
                'is_notice'   => 0,
                'is_secret'   => 1,
                'views'       => 8,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ];

        $this->db->table('posts')->insertBatch($posts);
    }
}
