<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedShopSettings extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('settings')->insertBatch([
            [
                'group'      => 'shop',
                'key'        => 'shipping_policy',
                'value'      => "■ 배송 안내\n배송 방법: 택배\n배송 기간: 결제 확인 후 2~3 영업일 이내 출고\n배송비: 상품 페이지 기재 기준\n\n■ 교환·반품 안내\n교환·반품 신청 기간: 수령 후 7일 이내\n교환·반품이 불가한 경우\n- 상품 사용 또는 일부 소비한 경우\n- 포장 훼손으로 상품 가치가 훼손된 경우\n- 고객 단순 변심으로 인한 경우 (왕복 배송비 고객 부담)\n\n■ 환불 안내\n반품 확인 후 3~5 영업일 이내 환불 처리",
                'label'      => '배송·교환·반품 안내',
                'type'       => 'textarea',
                'updated_at' => $now,
            ],
        ]);
    }

    public function down()
    {
        $this->db->table('settings')->where('key', 'shipping_policy')->delete();
    }
}
