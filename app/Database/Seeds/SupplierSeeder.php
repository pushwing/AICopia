<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $suppliers = [
            [
                'name'           => '동대문패션타운(주)',
                'contact_person' => '김민준',
                'phone'          => '02-1234-5678',
                'email'          => 'contact@ddm-fashion.co.kr',
                'memo'           => '동대문 의류 전문 도매업체. 신상품 매주 화·금 업데이트.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'name'           => '스타일리시 어패럴',
                'contact_person' => '이수진',
                'phone'          => '031-567-8901',
                'email'          => 'order@stylish-apparel.kr',
                'memo'           => '여성 캐주얼 전문. 최소 주문 수량 30장.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'name'           => '한국섬유(주)',
                'contact_person' => '박준혁',
                'phone'          => '053-234-5678',
                'email'          => 'supply@hankook-textile.com',
                'memo'           => '대구 원단·소재 전문 공급사. OEM 제작 가능.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'name'           => '제이앤케이 트레이딩',
                'contact_person' => '정유나',
                'phone'          => '02-9876-5432',
                'email'          => 'jnk@jnktrading.co.kr',
                'memo'           => '남성 아웃도어·스포츠웨어 수입 대리점.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'name'           => '글로벌패션임포트',
                'contact_person' => '최서연',
                'phone'          => '032-345-6789',
                'email'          => 'import@gfi.co.kr',
                'memo'           => '유럽·미국 브랜드 병행 수입. 관부가세 포함가 협의.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [
                'name'           => '부산항 패션물류',
                'contact_person' => '강태훈',
                'phone'          => '051-678-9012',
                'email'          => 'busan@bplogistics.kr',
                'memo'           => '중국산 의류·잡화 대량 수입 전문. 컨테이너 단위 발주.',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
        ];

        $db = \Config\Database::connect();

        foreach ($suppliers as $supplier) {
            $exists = $db->table('suppliers')->where('name', $supplier['name'])->countAllResults();
            if (! $exists) {
                $db->table('suppliers')->insert($supplier);
            }
        }
    }
}
