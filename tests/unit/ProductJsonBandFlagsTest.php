<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ProductController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductController::json() 의 in_new_band / in_discount_band 플래그 검증
 *
 * 상품관리에서 PICK 지정 시 "이미 신상품/할인 상품에 노출 중인 상품"을 경고하기
 * 위한 플래그다. welcome_new_count/welcome_discount_count 설정과 동일한 개수로
 * ProductModel::getLatest()/getDiscounted() 결과에 포함되는지를 그대로 반영한다.
 */
final class ProductJsonBandFlagsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<int, int> */
    private array $cleanupProductIds = [];

    /** @var array<string, string> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PJBF' . substr(uniqid(), -6);

        foreach (['welcome_new_count', 'welcome_discount_count'] as $key) {
            $row = db_connect()->table('settings')->where('key', $key)->get()->getRowArray();
            $this->originalSettings[$key] = $row['value'];
            db_connect()->table('settings')->where('key', $key)->update(['value' => '1']);
        }
        cache()->delete('site_settings');
    }

    protected function tearDown(): void
    {
        if ($this->cleanupProductIds !== []) {
            db_connect()->table('products')->whereIn('id', $this->cleanupProductIds)->delete();
        }
        foreach ($this->originalSettings as $key => $value) {
            db_connect()->table('settings')->where('key', $key)->update(['value' => $value]);
        }
        cache()->delete('site_settings');
        parent::tearDown();
    }

    private function insertProduct(string $suffix, ?int $discountPrice): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => $this->prefix . $suffix,
            'slug'           => strtolower($this->prefix . $suffix),
            'price'          => 10000,
            'discount_price' => $discountPrice,
            'stock'          => 10,
            'status'         => 'on_sale',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanupProductIds[] = $id;

        return $id;
    }

    private function fetchJson(): array
    {
        $controller = new ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $response = $controller->json();
        $decoded  = json_decode($response->getBody(), true);

        return array_column($decoded['data'], null, 'id');
    }

    public function test_newest_product_is_flagged_in_new_band_only(): void
    {
        // welcome_new_count=1 이므로, 나중에(더 높은 id로) 넣는 쪽이 신상품 밴드를 독차지한다.
        $this->insertProduct('DISCOUNT', discountPrice: 8000);
        $newId = $this->insertProduct('NEW', discountPrice: null);

        $rows = $this->fetchJson();

        $this->assertTrue($rows[$newId]['in_new_band'], '가장 최근 등록 상품은 신상품 밴드에 포함돼야 한다');
        $this->assertFalse($rows[$newId]['in_discount_band'], '할인가 없는 상품은 할인 밴드에 포함되면 안 된다');
    }

    public function test_discounted_older_product_is_flagged_in_discount_band_only(): void
    {
        $discountId = $this->insertProduct('DISCOUNT', discountPrice: 8000);
        $this->insertProduct('NEW', discountPrice: null);

        $rows = $this->fetchJson();

        $this->assertTrue($rows[$discountId]['in_discount_band'], '할인가 있는 상품은 할인 밴드에 포함돼야 한다');
        $this->assertFalse($rows[$discountId]['in_new_band'], 'welcome_new_count=1 이면 더 최근 상품에 밀려 신상품 밴드에서 빠져야 한다');
    }
}
