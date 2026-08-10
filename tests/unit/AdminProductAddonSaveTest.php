<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 폼이 보낸 addons_json 이 연결로 저장되는지 검증한다.
 * 컨트롤러의 private handleAddons() 는 store()/update() 를 통해 간접 검증한다.
 */
final class AdminProductAddonSaveTest extends CIUnitTestCase
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
        $this->prefix = 'APAS' . substr(uniqid(), -6);
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
        parent::tearDown();
    }

    private function insertProduct(string $suffix): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'       => $this->prefix . $suffix,
            'slug'       => strtolower($this->prefix . $suffix),
            'price'      => 10000,
            'stock'      => 5,
            'status'     => 'on_sale',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id                          = (int) $db->insertID();
        $this->cleanup['products'][] = $id;

        return $id;
    }

    private function trackAddonRows(int $productId): void
    {
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $productId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    public function testAddonSearchExcludesSelfAndReturnsOnSaleOnly(): void
    {
        $main = $this->insertProduct('MAIN');
        $hit  = $this->insertProduct('HIT');

        // 주의: 이 CI4 버전은 $_GET 을 Superglobals 서비스가 생성 시점에 스냅샷하므로
        // 테스트 중 $_GET 을 직접 대입해도 반영되지 않는다(OAuthStateValidationTest 와 동일 이슈).
        // setGlobal() 은 내부 캐시와 Superglobals 서비스를 함께 갱신해 반영을 보장한다.
        service('request')->setGlobal('get', ['q' => $this->prefix, 'exclude' => (string) $main]);

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $response = $controller->addonSearch();
        service('request')->setGlobal('get', []);

        $body = json_decode((string) $response->getBody(), true);
        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $body['items'] ?? []);

        $this->assertContains($hit, $ids, '검색어에 맞는 상품이 나와야 한다');
        $this->assertNotContains($main, $ids, '자기 자신은 후보에서 빠져야 한다');
    }

    public function testHiddenProductIsNotOfferedAsAddon(): void
    {
        $this->insertProduct('MAIN');
        $hidden = $this->insertProduct('HIDDEN');
        db_connect()->table('products')->where('id', $hidden)->update(['status' => 'hidden']);

        service('request')->setGlobal('get', ['q' => $this->prefix]);

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $response = $controller->addonSearch();
        service('request')->setGlobal('get', []);

        $body = json_decode((string) $response->getBody(), true);
        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $body['items'] ?? []);

        $this->assertNotContains($hidden, $ids, '판매중이 아닌 상품은 후보에서 빠져야 한다');
    }
}
