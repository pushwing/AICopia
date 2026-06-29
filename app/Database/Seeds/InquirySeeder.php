<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InquirySeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $inquiries = [
            [
                'name'       => '김민준',
                'email'      => 'minjun@example.com',
                'phone'      => '010-1234-5678',
                'subject'    => '상품 재입고 문의',
                'message'    => '스트라이프 카라 셔츠 M 사이즈 재입고 예정이 있나요? 꼭 구매하고 싶습니다.',
                'ip_address' => '127.0.0.1',
                'is_read'    => 0,
                'created_at' => $now,
            ],
            [
                'name'       => '이서연',
                'email'      => 'seoyeon@example.com',
                'phone'      => '010-2345-6789',
                'subject'    => '배송 지연 문의',
                'message'    => '주문한 지 5일이 지났는데 아직 배송이 안 됐습니다. 확인 부탁드립니다.',
                'ip_address' => '127.0.0.1',
                'is_read'    => 0,
                'created_at' => $now,
            ],
            [
                'name'       => '박지훈',
                'email'      => 'jihoon@example.com',
                'phone'      => '010-3456-7890',
                'subject'    => '사이즈 교환 문의',
                'message'    => '구매한 청바지 사이즈가 맞지 않아 교환하고 싶습니다. 절차를 알려주세요.',
                'ip_address' => '127.0.0.1',
                'is_read'    => 1,
                'created_at' => $now,
            ],
            [
                'name'       => '최수아',
                'email'      => 'sua@example.com',
                'phone'      => null,
                'subject'    => '세탁 방법 문의',
                'message'    => '캐시미어 니트 스웨터 세탁은 손세탁만 가능한가요?',
                'ip_address' => '127.0.0.1',
                'is_read'    => 1,
                'created_at' => $now,
            ],
            [
                'name'       => '정도현',
                'email'      => 'dohyun@example.com',
                'phone'      => '010-5678-9012',
                'subject'    => '대량 구매 할인 문의',
                'message'    => '법인 구매로 50벌 이상 주문 시 추가 할인이 가능한지 문의드립니다.',
                'ip_address' => '127.0.0.1',
                'is_read'    => 0,
                'created_at' => $now,
            ],
        ];

        $this->db->table('inquiries')->insertBatch($inquiries);
    }
}
