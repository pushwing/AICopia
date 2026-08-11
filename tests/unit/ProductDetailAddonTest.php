<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\ShopController;
use App\Models\ProductAddonModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class ProductDetailAddonTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['product_addons' => [], 'products' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PDAD' . substr(uniqid(), -6);
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->cleanup['products'] !== []) {
            $skuRows = $db->table('product_skus')->whereIn('product_id', $this->cleanup['products'])->get()->getResultArray();
            if ($skuRows) {
                $db->table('product_sku_values')->whereIn('sku_id', array_column($skuRows, 'id'))->delete();
            }
            $db->table('product_skus')->whereIn('product_id', $this->cleanup['products'])->delete();

            $optRows = $db->table('product_options')->whereIn('product_id', $this->cleanup['products'])->get()->getResultArray();
            if ($optRows) {
                $db->table('product_option_values')->whereIn('option_id', array_column($optRows, 'id'))->delete();
            }
            $db->table('product_options')->whereIn('product_id', $this->cleanup['products'])->delete();
        }

        foreach (['product_addons', 'products'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['product_addons' => [], 'products' => []];
        session()->destroy();
        parent::tearDown();
    }

    private function insertProduct(
        string $suffix,
        int $stock = 10,
        string $status = 'on_sale',
        ?string $name = null,
        int $price = 10000,
        ?int $discountPrice = null,
    ): int {
        $db = db_connect();
        $db->table('products')->insert([
            'name'           => $name ?? ($this->prefix . $suffix),
            'slug'           => strtolower($this->prefix . $suffix),
            'price'          => $price,
            'discount_price' => $discountPrice,
            'stock'          => $stock,
            'status'         => $status,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    private function slugOf(int $productId): string
    {
        $row = db_connect()->table('products')->select('slug')->where('id', $productId)->get()->getRowArray();

        return (string) ($row['slug'] ?? '');
    }

    /** @param array<int, int> $addonIds */
    private function link(int $mainId, array $addonIds): void
    {
        new ProductAddonModel()->saveForProduct($mainId, $addonIds);
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $mainId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    private function detail(int $productId): string
    {
        $controller = new ShopController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->detail($this->slugOf($productId));
    }

    public function testShowsAddonSection(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON');
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertStringContainsString('추가구성상품', $html, '애드온 영역이 없다');
        $this->assertStringContainsString($this->prefix . 'ADDON', $html, '애드온 상품명이 없다');
    }

    public function testHidesSoldOutAddon(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON', 0);
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertStringNotContainsString($this->prefix . 'ADDON', $html, '품절 애드온은 노출되면 안 된다');
    }

    public function testOmitsSectionWhenNoAddons(): void
    {
        $main = $this->insertProduct('MAIN');

        $html = $this->detail($main);

        $this->assertStringNotContainsString('추가구성상품', $html, '애드온이 없으면 영역 자체가 없어야 한다');
    }

    /**
     * 애드온 상품명은 관리자가 아닌 경로(네이버 상품 임포트 등)로도 채워질 수 있어
     * 신뢰할 수 없는 문자열이다. XSS payload가 (1) PHP 렌더 카드 esc()와
     * (2) addonsData JSON의 JSON_HEX_* 플래그, 양쪽 모두에서 무력화되는지 검증한다.
     */
    public function testEscapesXssPayloadInAddonName(): void
    {
        $payload = "<script>alert('1')</script>";

        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON', name: $this->prefix . 'ADDON' . $payload);
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        // 원본 payload가 이스케이프 없이 그대로(문자 그대로) 노출되면 안 된다
        $this->assertStringNotContainsString($payload, $html, 'XSS payload가 이스케이프 없이 그대로 노출됨');

        // PHP 렌더 카드: esc()가 적용되어 &lt;script&gt; 형태여야 한다
        $this->assertStringContainsString('&lt;script&gt;', $html, 'add-on 카드에 esc() 이스케이프가 적용되지 않음');

        // <script> 블록 안 addonsData JSON: 원문 </script> 시퀀스가 그대로 있으면 안 된다
        $this->assertStringNotContainsString('</script>alert', $html, 'addonsData JSON에 raw </script> 시퀀스가 노출됨');

        // JSON_HEX_TAG/JSON_HEX_APOS가 만드는 hex-escape 형태가 존재해야 한다
        // (플래그가 빠지면 <script>·따옴표가 escape 없이 그대로 남아 이 문자열이 사라진다)
        $this->assertStringContainsString(
            '\\u003Cscript\\u003Ealert(\\u00271\\u0027)\\u003C\\/script\\u003E',
            $html,
            'addonsData JSON에 JSON_HEX_TAG/JSON_HEX_APOS hex-escape 형태가 없음',
        );
    }

    /**
     * 옵션(SKU)이 있는 애드온은 본품처럼 옵션 select 가 카드 안에 렌더링돼야 한다.
     */
    public function testRendersOptionSelectForSkuBearingAddon(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON');

        $db = db_connect();
        $db->table('product_options')->insert([
            'product_id' => $addon,
            'name'       => '색상',
            'sort_order' => 0,
        ]);
        $optionId = (int) $db->insertID();
        $db->table('product_option_values')->insert([
            'option_id'  => $optionId,
            'value'      => '빨강',
            'sort_order' => 0,
        ]);
        $valueId = (int) $db->insertID();
        $db->table('product_skus')->insert([
            'product_id' => $addon,
            'price_diff' => 1000,
            'stock'      => 5,
            'sku_code'   => null,
        ]);
        $skuId = (int) $db->insertID();
        $db->table('product_sku_values')->insert([
            'sku_id'          => $skuId,
            'option_value_id' => $valueId,
        ]);

        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertMatchesRegularExpression(
            '/addon-option-select[^>]*data-addon-idx="0"/',
            $html,
            '옵션이 있는 애드온에는 옵션 select 가 렌더링돼야 한다',
        );
        $this->assertStringContainsString('빨강', $html, '옵션값이 select 안에 렌더링돼야 한다');
        $this->assertStringContainsString('"price_diff":1000', $html, 'addonsData JSON에 SKU price_diff가 포함돼야 한다');
    }

    /**
     * discount_price가 0원인 add-on은 `?:` 연산자로 falsy 취급되면 안 된다.
     * price로 대체되지 않고 실제 0원으로 표시·계산되어야 한다.
     */
    public function testZeroDiscountPriceRendersAsZeroNotFullPrice(): void
    {
        $main  = $this->insertProduct('MAIN');
        $addon = $this->insertProduct('ADDON', price: 5000, discountPrice: 0);
        $this->link($main, [$addon]);

        $html = $this->detail($main);

        $this->assertStringContainsString('0원', $html, 'discount_price=0인 add-on이 0원으로 표시되지 않음');
        $this->assertStringNotContainsString('5,000원', $html, 'discount_price=0인 add-on이 price(5,000원)로 대체되어 표시됨');
    }
}
