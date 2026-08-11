<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 상품 폼이 보낸 addons_json 이 연결로 저장되는지 검증한다.
 *
 * ProductController::update() 를 실제로 호출해 handleAddons() 가 저장한
 * product_addons 행을 ProductAddonModel::getAddonProductIds() 로 검증한다
 * (addonSearch() 만 호출하던 이전 버전은 저장 경로 자체를 검증하지 못했다).
 */
final class AdminProductAddonSaveTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['product_addons' => [], 'products' => [], 'product_skus' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'APAS' . substr(uniqid(), -6);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['product_addons'] !== []) {
            $db->table('product_addons')->whereIn('id', $this->cleanup['product_addons'])->delete();
        }
        if ($this->cleanup['product_skus'] !== []) {
            $db->table('product_skus')->whereIn('id', $this->cleanup['product_skus'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = ['product_addons' => [], 'products' => [], 'product_skus' => []];

        service('request')->setGlobal('post', []);
        service('request')->setGlobal('get', []);
        service('request')->setGlobal('request', []);

        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

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

    /** 이후 tearDown 에서 지워지도록 product_addons 행을 추적한다 */
    private function trackAddonRows(int $productId): void
    {
        $rows = db_connect()->table('product_addons')->select('id')->where('product_id', $productId)->get()->getResultArray();
        foreach ($rows as $row) {
            $this->cleanup['product_addons'][] = (int) $row['id'];
        }
    }

    /**
     * 상품 수정 폼이 보내는 필수 필드를 모두 채운 뒤 ProductController::update() 를 호출한다.
     *
     * @param array<string, mixed> $overrides
     */
    private function callUpdate(int $productId, string $slug, array $overrides = []): \App\Controllers\Admin\ProductController
    {
        $post = array_merge([
            'name'           => $this->prefix . '_UPDATED',
            'slug'           => $slug,
            'price'          => '10000',
            'cost_price'     => '0',
            'discount_price' => '',
            'stock'          => '5',
            'status'         => 'on_sale',
            'description'    => '',
            'shipping_type'  => 'free',
            'shipping_fee'   => '0',
            'free_threshold' => '0',
        ], $overrides);

        // 주의: 이 CI4 버전은 $_POST 를 Superglobals 서비스가 생성 시점에 스냅샷하므로
        // 테스트 중 $_POST 를 직접 대입해도 반영되지 않는다(OAuthStateValidationTest 와 동일 이슈).
        // setGlobal() 은 내부 캐시와 Superglobals 서비스를 함께 갱신해 반영을 보장한다.
        // $this->validate() 는 요청 메서드가 PUT/PATCH/DELETE 가 아니면 getVar() (= 'request'
        // 슈퍼글로벌)에서 데이터를 읽으므로 'post' 뿐 아니라 'request' 도 같이 채워줘야 한다.
        $request = service('request');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        $request->setGlobal('get', []);

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController($request, service('response'), service('logger'));
        $controller->update($productId);

        return $controller;
    }

    // ── addonSearch() 회귀 (기존) ────────────────────────────────────────────

    public function testAddonSearchExcludesSelfAndReturnsOnSaleOnly(): void
    {
        $main = $this->insertProduct('MAIN');
        $hit  = $this->insertProduct('HIT');

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

    public function testAddonSearchIncludesSkuBearingProduct(): void
    {
        $this->insertProduct('MAIN');
        $hasSku = $this->insertProduct('HASSKU');

        $db = db_connect();
        $db->table('product_skus')->insert([
            'product_id' => $hasSku,
            'price_diff' => 0,
            'stock'      => 5,
            'sku_code'   => null,
        ]);
        $this->cleanup['product_skus'][] = (int) $db->insertID();

        service('request')->setGlobal('get', ['q' => $this->prefix]);

        $controller = new \App\Controllers\Admin\ProductController();
        $controller->initController(service('request'), service('response'), service('logger'));

        $response = $controller->addonSearch();
        service('request')->setGlobal('get', []);

        $body = json_decode((string) $response->getBody(), true);
        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $body['items'] ?? []);

        $this->assertContains(
            $hasSku,
            $ids,
            'SKU(옵션)를 가진 상품도 애드온 후보 검색 결과에 나와야 한다',
        );
    }

    // ── update() 를 통한 실제 저장 경로 (신규) ──────────────────────────────────

    public function testUpdatePersistsAddonLinksFromPostedJson(): void
    {
        $main   = $this->insertProduct('MAIN');
        $addon1 = $this->insertProduct('ADDON1');
        $addon2 = $this->insertProduct('ADDON2');
        $slug   = strtolower($this->prefix . 'MAIN');

        $this->callUpdate($main, $slug, [
            'addons_json' => json_encode([$addon2, $addon1], JSON_THROW_ON_ERROR),
        ]);
        $this->trackAddonRows($main);

        // 검증 통과 여부(=핵심 필드가 실제로 갱신됐는지)를 함께 확인해
        // 리다이렉트로 조용히 빠져나가 handleAddons() 를 타지 않은 거짓 성공을 막는다.
        $updated = db_connect()->table('products')->where('id', $main)->get()->getRowArray();
        $this->assertSame($this->prefix . '_UPDATED', $updated['name'], '검증을 통과하지 못해 update() 가 저장 전에 리다이렉트했다');

        $ids = (new \App\Models\ProductAddonModel())->getAddonProductIds($main);
        $this->assertSame([$addon2, $addon1], $ids, '등록 순서대로 addons_json 이 저장되지 않았다');
    }

    public function testUpdateWithEmptyAddonsJsonClearsExistingLinks(): void
    {
        $main   = $this->insertProduct('MAIN');
        $addon1 = $this->insertProduct('ADDON1');
        $slug   = strtolower($this->prefix . 'MAIN');

        // 먼저 연결을 하나 만들어둔다.
        $this->callUpdate($main, $slug, [
            'addons_json' => json_encode([$addon1], JSON_THROW_ON_ERROR),
        ]);
        $this->trackAddonRows($main);
        $this->assertSame([$addon1], (new \App\Models\ProductAddonModel())->getAddonProductIds($main), '사전 조건: 연결이 먼저 저장돼 있어야 한다');

        // 빈 addons_json 으로 다시 저장하면 기존 연결이 모두 지워져야 한다.
        $this->callUpdate($main, $slug, ['addons_json' => '[]']);

        $this->assertSame([], (new \App\Models\ProductAddonModel())->getAddonProductIds($main), '빈 addons_json 이 기존 연결을 지우지 못했다');
    }

    public function testUpdateWithMalformedAddonsJsonDoesNotThrowAndKeepsOnlyScalarIds(): void
    {
        $main   = $this->insertProduct('MAIN');
        $addon1 = $this->insertProduct('ADDON1');
        $slug   = strtolower($this->prefix . 'MAIN');

        // 변조된 요청: 배열 안에 배열이 섞여 있다 — array_map(intval(...), ...) 가 그대로
        // 받으면 TypeError 로 500 이 난다(Finding 5). 스칼라만 통과시켜야 한다.
        $this->callUpdate($main, $slug, [
            'addons_json' => json_encode([[1, 2], $addon1, 'not-a-number'], JSON_THROW_ON_ERROR),
        ]);
        $this->trackAddonRows($main);

        // 'not-a-number' 는 intval() 로 0이 되어 saveForProduct() 내부에서 걸러진다.
        $this->assertSame([$addon1], (new \App\Models\ProductAddonModel())->getAddonProductIds($main), '스칼라가 아닌 항목이 섞여도 예외 없이 스칼라 항목만 저장돼야 한다');
    }
}
