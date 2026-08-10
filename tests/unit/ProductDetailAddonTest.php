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
        foreach (['product_addons', 'products'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['product_addons' => [], 'products' => []];
        session()->destroy();
        parent::tearDown();
    }

    private function insertProduct(string $suffix, int $stock = 10, string $status = 'on_sale'): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'       => $this->prefix . $suffix,
            'slug'       => strtolower($this->prefix . $suffix),
            'price'      => 10000,
            'stock'      => $stock,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
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
}
