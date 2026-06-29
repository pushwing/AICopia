<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductQnaSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // 상품 ID 조회 (slug 기준)
        $products = $this->db->table('products')
            ->select('id, slug')
            ->whereIn('slug', [
                'classic-white-tshirt',
                'slim-black-jeans',
                'oversized-hood-zipup',
                'linen-wide-pants',
                'stripe-collar-shirt',
                'cashmere-knit-sweater',
                'denim-mini-skirt',
                'wool-long-coat',
            ])
            ->get()->getResultArray();

        if (empty($products)) {
            echo "상품 데이터가 없습니다. ProductSeeder를 먼저 실행하세요.\n";
            return;
        }

        $pMap = array_column($products, 'id', 'slug');

        // 회원 ID 조회 (admin 제외)
        $members = $this->db->table('users')
            ->select('id')
            ->where('role', 'member')
            ->limit(5)
            ->get()->getResultArray();

        if (empty($members)) {
            echo "회원 데이터가 없습니다.\n";
            return;
        }

        $memberIds = array_column($members, 'id');

        // 관리자 ID 조회
        $admin = $this->db->table('users')
            ->select('id')
            ->where('role', 'admin')
            ->get()->getRowArray();
        $adminId = $admin['id'] ?? null;

        $p = function (string $slug) use ($pMap): int {
            return (int) ($pMap[$slug] ?? array_values($pMap)[0]);
        };

        $u = function (int $idx) use ($memberIds): int {
            return $memberIds[$idx % count($memberIds)];
        };

        $qnas = [
            // ── 클래식 화이트 티셔츠 ──────────────────────────────────────
            [
                'product_id'  => $p('classic-white-tshirt'),
                'user_id'     => $u(0),
                'title'       => '사이즈 추천 부탁드려요',
                'content'     => '평소 M 사이즈 입는데 이 티셔츠도 M 으로 주문하면 될까요? 키 175cm 몸무게 68kg 입니다.',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '안녕하세요! 저희 클래식 화이트 티셔츠는 일반 핏으로 제작되었습니다. 175cm/68kg 체형이시면 M 사이즈가 잘 맞으실 것 같습니다. 감사합니다 :)',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -3 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
            ],
            [
                'product_id'  => $p('classic-white-tshirt'),
                'user_id'     => $u(1),
                'title'       => '세탁 후 수축 여부',
                'content'     => '면 100% 제품인가요? 세탁 후 수축이 심한지 궁금합니다.',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '네, 면 100% 소재입니다. 세탁 시 30도 이하 찬물로 세탁하시면 수축을 최소화하실 수 있습니다. 첫 세탁 시 약간의 수축이 있을 수 있으니 한 사이즈 크게 구매하시는 것도 방법입니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -4 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
            ],

            // ── 슬림핏 블랙 청바지 ────────────────────────────────────────
            [
                'product_id'  => $p('slim-black-jeans'),
                'user_id'     => $u(2),
                'title'       => '허벅지가 두꺼운 편인데 맞을까요?',
                'content'     => '슬림핏이라 허벅지가 두꺼운 체형은 불편할 것 같아서요. 허벅지 둘레 58cm 정도인데 32 사이즈가 맞을까요?',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '안녕하세요! 슬림핏이지만 스트레치 소재가 포함되어 있어 활동성이 좋습니다. 허벅지 둘레 58cm라면 32 사이즈를 추천드립니다. 단, 슬림핏 특성상 타이트하게 착용하시게 됩니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
            ],
            [
                'product_id'  => $p('slim-black-jeans'),
                'user_id'     => $u(3),
                'title'       => '색 빠짐 있나요?',
                'content'     => '블랙 청바지 특성상 초반에 색이 많이 빠지는 경우가 있던데, 이 제품은 어떤가요?',
                'is_secret'   => 0,
                'is_answered' => 0,
                'answer'      => null,
                'answered_at' => null,
                'answered_by' => null,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -6 hours')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -6 hours')),
            ],

            // ── 오버사이즈 후드 집업 ──────────────────────────────────────
            [
                'product_id'  => $p('oversized-hood-zipup'),
                'user_id'     => $u(0),
                'title'       => '여성도 입기 좋은 사이즈인가요?',
                'content'     => '오버사이즈라 여성도 XS나 S로 구매해서 오버핏으로 입기 좋을까요?',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '물론입니다! 여성분들도 S나 M 사이즈로 구매하셔서 오버핏으로 많이 즐겨 입으십니다. 여성분 평균 체형이시면 S를 추천드립니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -3 hours')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -3 hours')),
            ],
            [
                'product_id'  => $p('oversized-hood-zipup'),
                'user_id'     => $u(4),
                'title'       => '지퍼 품질은 어떤가요?',
                'content'     => '이전에 구매한 후드 집업이 지퍼가 자꾸 끊겨서요. 이 제품 지퍼 내구성이 궁금합니다.',
                'is_secret'   => 0,
                'is_answered' => 0,
                'answer'      => null,
                'answered_at' => null,
                'answered_by' => null,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 hours')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 hours')),
            ],

            // ── 린넨 와이드 팬츠 ──────────────────────────────────────────
            [
                'product_id'  => $p('linen-wide-pants'),
                'user_id'     => $u(1),
                'title'       => '비침 정도가 어떤가요?',
                'content'     => '린넨 소재라 속이 비치지는 않나요? 흰색 계열 속옷 착용 시 어떤지 궁금합니다.',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '린넨 혼방 소재라 100% 린넨보다 비침이 적습니다. 밝은 색 속옷 착용 시 약간의 비침이 있을 수 있으니 살색 속옷을 추천드립니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -5 hours')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -5 hours')),
            ],

            // ── 스트라이프 카라 셔츠 ──────────────────────────────────────
            [
                'product_id'  => $p('stripe-collar-shirt'),
                'user_id'     => $u(2),
                'title'       => '재입고 예정이 있나요?',
                'content'     => 'L 사이즈가 품절인데 재입고 예정이 있는지 궁금합니다.',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '안녕하세요! 현재 L 사이즈 재입고를 준비 중입니다. 재입고 알림 신청을 해주시면 입고 시 문자/메일로 안내해 드리겠습니다. 감사합니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -4 hours')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -3 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -4 hours')),
            ],
            [
                'product_id'  => $p('stripe-collar-shirt'),
                'user_id'     => $u(3),
                'title'       => '주문 관련 비밀 문의',
                'content'     => '주문번호 관련해서 비밀로 문의드립니다.',
                'is_secret'   => 1,
                'is_answered' => 0,
                'answer'      => null,
                'answered_at' => null,
                'answered_by' => null,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 hour')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 hour')),
            ],

            // ── 캐시미어 니트 스웨터 ──────────────────────────────────────
            [
                'product_id'  => $p('cashmere-knit-sweater'),
                'user_id'     => $u(4),
                'title'       => '캐시미어 함량이 어떻게 되나요?',
                'content'     => '캐시미어 니트라고 표기되어 있는데 캐시미어 함량이 정확히 얼마인지 궁금합니다.',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '캐시미어 30%, 울 70% 혼방 제품입니다. 순수 캐시미어보다 내구성이 높으면서도 부드러운 착감을 유지합니다. 세탁은 드라이클리닝 또는 울 전용 세제로 손세탁을 권장드립니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -5 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -2 days')),
            ],

            // ── 울 코트 롱 ────────────────────────────────────────────────
            [
                'product_id'  => $p('wool-long-coat'),
                'user_id'     => $u(0),
                'title'       => '키 165cm 여성 기준 기장이 어떻게 되나요?',
                'content'     => '롱코트라 키에 따라 기장이 달라보일 것 같은데 165cm 기준으로 어느 정도 기장인지 알 수 있을까요?',
                'is_secret'   => 0,
                'is_answered' => 1,
                'answer'      => '165cm 기준 S 사이즈 착용 시 무릎 아래 약 15cm 정도 내려오는 기장입니다. 무릎을 충분히 덮는 롱 기장으로 보온성이 뛰어납니다.',
                'answered_at' => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
                'answered_by' => $adminId,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -3 days')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -1 day')),
            ],
            [
                'product_id'  => $p('wool-long-coat'),
                'user_id'     => $u(1),
                'title'       => '울 함량 및 관리 방법',
                'content'     => '울 100%인가요? 그리고 집에서 세탁이 가능한가요?',
                'is_secret'   => 0,
                'is_answered' => 0,
                'answer'      => null,
                'answered_at' => null,
                'answered_by' => null,
                'created_at'  => date('Y-m-d H:i:s', strtotime($now . ' -4 hours')),
                'updated_at'  => date('Y-m-d H:i:s', strtotime($now . ' -4 hours')),
            ],
        ];

        $this->db->table('product_qnas')->insertBatch($qnas);
        echo count($qnas) . "개의 상품 문의 데이터가 추가되었습니다.\n";
    }
}
