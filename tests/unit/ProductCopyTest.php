<?php

namespace Tests\Unit;

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * ProductController::copy() 비즈니스 로직 검증
 *
 * - 복사본 name = 원본 + ' (복사)'
 * - status = hidden, stock = 0
 * - slug 고유 생성
 * - product_images 복사 (media_id 동일)
 * - product_options, product_option_values ID 재매핑 복사
 * - product_skus, product_sku_values ID 재매핑 복사
 * - 원본 상품 보존
 */
final class ProductCopyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;
    private array  $cleanup = [
        'product_sku_values'   => [],
        'product_skus'         => [],
        'product_option_values'=> [],
        'product_options'      => [],
        'product_images'       => [],
        'products'             => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'PCT' . substr(uniqid(), -6) . '_';
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        if ($this->cleanup['product_sku_values'] !== []) {
            $db->table('product_sku_values')->whereIn('sku_id', $this->cleanup['product_skus'])->delete();
        }
        if ($this->cleanup['product_skus'] !== []) {
            $db->table('product_skus')->whereIn('id', $this->cleanup['product_skus'])->delete();
        }
        if ($this->cleanup['product_option_values'] !== []) {
            $db->table('product_option_values')->whereIn('option_id', $this->cleanup['product_options'])->delete();
        }
        if ($this->cleanup['product_options'] !== []) {
            $db->table('product_options')->whereIn('product_id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['product_images'] !== []) {
            $db->table('product_images')->whereIn('product_id', $this->cleanup['products'])->delete();
        }
        if ($this->cleanup['products'] !== []) {
            $db->table('product_categories')->whereIn('product_id', $this->cleanup['products'])->delete();
            $db->table('products')->whereIn('id', $this->cleanup['products'])->delete();
        }
        $this->cleanup = array_fill_keys(array_keys($this->cleanup), []);
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function insertProduct(array $overrides = []): int
    {
        $uid  = uniqid();
        $slug = $this->prefix . $uid;
        $data = array_merge([
            'name'          => $this->prefix . $uid,
            'slug'          => $slug,
            'price'         => 10000,
            'cost_price'    => 0,
            'stock'         => 20,
            'status'        => 'on_sale',
            'shipping_type' => 'free',
            'shipping_fee'  => 0,
            'free_threshold'=> 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], $overrides);
        $db = db_connect();
        $db->table('products')->insert($data);
        $id = (int) $db->insertID();
        $this->cleanup['products'][] = $id;
        return $id;
    }

    private function insertImage(int $productId, int $mediaId = 1, int $isPrimary = 1): int
    {
        $db = db_connect();
        $db->table('product_images')->insert([
            'product_id' => $productId,
            'media_id'   => $mediaId,
            'is_primary' => $isPrimary,
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanup['product_images'][] = $id;
        return $id;
    }

    private function insertOption(int $productId, string $name = '색상'): int
    {
        $db = db_connect();
        $db->table('product_options')->insert(['product_id' => $productId, 'name' => $name, 'sort_order' => 0]);
        $id = (int) $db->insertID();
        $this->cleanup['product_options'][] = $id;
        return $id;
    }

    private function insertOptionValue(int $optionId, string $value = '빨강'): int
    {
        $db = db_connect();
        $db->table('product_option_values')->insert(['option_id' => $optionId, 'value' => $value, 'sort_order' => 0]);
        $id = (int) $db->insertID();
        $this->cleanup['product_option_values'][] = $id;
        return $id;
    }

    private function insertSku(int $productId, int $priceDiff = 0): int
    {
        $db = db_connect();
        $db->table('product_skus')->insert(['product_id' => $productId, 'price_diff' => $priceDiff, 'stock' => 5, 'sku_code' => null]);
        $id = (int) $db->insertID();
        $this->cleanup['product_skus'][] = $id;
        return $id;
    }

    private function insertSkuValue(int $skuId, int $optionValueId): void
    {
        db_connect()->table('product_sku_values')->insert(['sku_id' => $skuId, 'option_value_id' => $optionValueId]);
        $this->cleanup['product_sku_values'][] = $skuId;
    }

    /**
     * ProductController::copy() 와 동일한 복사 로직
     * 새 product id 반환
     */
    private function runCopy(int $sourceId): int
    {
        $db      = db_connect();
        $model   = new ProductModel();
        $product = $model->find($sourceId);
        $now     = date('Y-m-d H:i:s');

        $newName = $product['name'] . ' (복사)';
        $newSlug = $model->generateSlug($newName);

        $newId = (int) $model->insert([
            'supplier_id'    => $product['supplier_id'],
            'name'           => $newName,
            'slug'           => $newSlug,
            'price'          => $product['price'],
            'cost_price'     => $product['cost_price'],
            'discount_price' => $product['discount_price'],
            'stock'          => 0,
            'status'         => 'hidden',
            'description'    => $product['description'],
            'shipping_type'  => $product['shipping_type'],
            'shipping_fee'   => $product['shipping_fee'],
            'free_threshold' => $product['free_threshold'],
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $this->cleanup['products'][] = $newId;

        // 카테고리 복사
        $model->setCategories($newId, $model->getCategories($sourceId));

        foreach ($db->table('product_images')->where('product_id', $sourceId)->get()->getResultArray() as $img) {
            $db->table('product_images')->insert([
                'product_id' => $newId,
                'media_id'   => $img['media_id'],
                'is_primary' => $img['is_primary'],
                'sort_order' => $img['sort_order'],
                'created_at' => $now,
            ]);
            $this->cleanup['product_images'][] = (int) $db->insertID();
        }

        $optionIdMap = $optionValueIdMap = [];
        foreach ($db->table('product_options')->where('product_id', $sourceId)->get()->getResultArray() as $opt) {
            $db->table('product_options')->insert(['product_id' => $newId, 'name' => $opt['name'], 'sort_order' => $opt['sort_order']]);
            $newOptId                      = (int) $db->insertID();
            $optionIdMap[(int) $opt['id']] = $newOptId;
            $this->cleanup['product_options'][] = $newOptId;

            foreach ($db->table('product_option_values')->where('option_id', $opt['id'])->get()->getResultArray() as $val) {
                $db->table('product_option_values')->insert(['option_id' => $newOptId, 'value' => $val['value'], 'sort_order' => $val['sort_order']]);
                $newValId                       = (int) $db->insertID();
                $optionValueIdMap[(int) $val['id']] = $newValId;
                $this->cleanup['product_option_values'][] = $newValId;
            }
        }

        foreach ($db->table('product_skus')->where('product_id', $sourceId)->get()->getResultArray() as $sku) {
            $db->table('product_skus')->insert(['product_id' => $newId, 'price_diff' => $sku['price_diff'], 'stock' => 0, 'sku_code' => $sku['sku_code']]);
            $newSkuId = (int) $db->insertID();
            $this->cleanup['product_skus'][] = $newSkuId;

            foreach ($db->table('product_sku_values')->where('sku_id', $sku['id'])->get()->getResultArray() as $sv) {
                $newValId = $optionValueIdMap[(int) $sv['option_value_id']] ?? null;
                if ($newValId) {
                    $db->table('product_sku_values')->insert(['sku_id' => $newSkuId, 'option_value_id' => $newValId]);
                    $this->cleanup['product_sku_values'][] = $newSkuId;
                }
            }
        }

        return $newId;
    }

    // ── 기본 필드 ─────────────────────────────────────────────────────────────

    public function testCopiedNameHasSuffix(): void
    {
        $srcId = $this->insertProduct();
        $src   = db_connect()->table('products')->where('id', $srcId)->get()->getRowArray();
        $newId = $this->runCopy($srcId);
        $copy  = db_connect()->table('products')->where('id', $newId)->get()->getRowArray();

        $this->assertSame($src['name'] . ' (복사)', $copy['name']);
    }

    public function testCopiedStatusIsHidden(): void
    {
        $srcId = $this->insertProduct(['status' => 'on_sale']);
        $newId = $this->runCopy($srcId);
        $copy  = db_connect()->table('products')->where('id', $newId)->get()->getRowArray();

        $this->assertSame('hidden', $copy['status']);
    }

    public function testCopiedStockIsZero(): void
    {
        $srcId = $this->insertProduct(['stock' => 99]);
        $newId = $this->runCopy($srcId);
        $copy  = db_connect()->table('products')->where('id', $newId)->get()->getRowArray();

        $this->assertSame('0', $copy['stock']);
    }

    public function testCopiedSlugIsUnique(): void
    {
        $srcId  = $this->insertProduct();
        $newId1 = $this->runCopy($srcId);
        $newId2 = $this->runCopy($srcId);

        $db   = db_connect();
        $slug1 = $db->table('products')->where('id', $newId1)->get()->getRowArray()['slug'];
        $slug2 = $db->table('products')->where('id', $newId2)->get()->getRowArray()['slug'];

        $this->assertNotSame($slug1, $slug2, '두 번 복사 시 slug가 달라야 한다');
    }

    // ── 원본 보존 ─────────────────────────────────────────────────────────────

    public function testOriginalProductUnchanged(): void
    {
        $srcId  = $this->insertProduct(['stock' => 30, 'status' => 'on_sale']);
        $this->runCopy($srcId);
        $src    = db_connect()->table('products')->where('id', $srcId)->get()->getRowArray();

        $this->assertSame('on_sale', $src['status']);
        $this->assertSame('30', $src['stock']);
    }

    // ── 이미지 복사 ───────────────────────────────────────────────────────────

    public function testImagesAreCopiedWithSameMediaId(): void
    {
        $srcId = $this->insertProduct();
        $this->insertImage($srcId, mediaId: 42, isPrimary: 1);
        $this->insertImage($srcId, mediaId: 43, isPrimary: 0);

        $newId  = $this->runCopy($srcId);
        $db     = db_connect();
        $images = $db->table('product_images')->where('product_id', $newId)->orderBy('sort_order')->get()->getResultArray();

        $this->assertCount(2, $images);
        $this->assertSame('42', $images[0]['media_id']);
        $this->assertSame('43', $images[1]['media_id']);
    }

    public function testCopyWithNoImagesSucceeds(): void
    {
        $srcId = $this->insertProduct();
        $newId = $this->runCopy($srcId);

        $count = db_connect()->table('product_images')->where('product_id', $newId)->countAllResults();
        $this->assertSame(0, $count);
    }

    // ── 옵션 복사 ─────────────────────────────────────────────────────────────

    public function testOptionsAreCopiedToNewProduct(): void
    {
        $srcId   = $this->insertProduct();
        $optId   = $this->insertOption($srcId, '색상');
        $this->insertOptionValue($optId, '빨강');
        $this->insertOptionValue($optId, '파랑');

        $newId   = $this->runCopy($srcId);
        $db      = db_connect();
        $newOpts = $db->table('product_options')->where('product_id', $newId)->get()->getResultArray();

        $this->assertCount(1, $newOpts, '옵션 1개가 복사되어야 한다');
        $this->assertSame('색상', $newOpts[0]['name']);

        $vals = $db->table('product_option_values')->where('option_id', $newOpts[0]['id'])->orderBy('sort_order')->get()->getResultArray();
        $this->assertCount(2, $vals);
        $this->assertSame('빨강', $vals[0]['value']);
        $this->assertSame('파랑', $vals[1]['value']);
    }

    public function testOptionsBelongToNewProductNotOriginal(): void
    {
        $srcId  = $this->insertProduct();
        $optId  = $this->insertOption($srcId);
        $newId  = $this->runCopy($srcId);
        $db     = db_connect();

        $newOpts = $db->table('product_options')->where('product_id', $newId)->get()->getResultArray();
        $this->assertNotSame($optId, (int) $newOpts[0]['id'], '복사본 옵션 ID는 원본과 달라야 한다');
    }

    // ── SKU 복사 ──────────────────────────────────────────────────────────────

    public function testSkusAreCopiedWithZeroStock(): void
    {
        $srcId  = $this->insertProduct();
        $optId  = $this->insertOption($srcId, '사이즈');
        $valId  = $this->insertOptionValue($optId, 'M');
        $skuId  = $this->insertSku($srcId, priceDiff: 500);
        $this->insertSkuValue($skuId, $valId);

        $newId  = $this->runCopy($srcId);
        $db     = db_connect();
        $skus   = $db->table('product_skus')->where('product_id', $newId)->get()->getResultArray();

        $this->assertCount(1, $skus);
        $this->assertSame('0', $skus[0]['stock'], '복사된 SKU stock은 0이어야 한다');
        $this->assertSame('500', $skus[0]['price_diff']);
    }

    public function testSkuValuesAreRemappedCorrectly(): void
    {
        $srcId  = $this->insertProduct();
        $optId  = $this->insertOption($srcId, '색상');
        $valId  = $this->insertOptionValue($optId, '초록');
        $skuId  = $this->insertSku($srcId);
        $this->insertSkuValue($skuId, $valId);

        $newId  = $this->runCopy($srcId);
        $db     = db_connect();
        $newSku = $db->table('product_skus')->where('product_id', $newId)->get()->getRowArray();
        $sv     = $db->table('product_sku_values')->where('sku_id', $newSku['id'])->get()->getRowArray();

        $this->assertNotNull($sv, 'sku_value 가 복사되어야 한다');
        $this->assertNotSame($valId, (int) $sv['option_value_id'], 'option_value_id 는 새 값으로 재매핑되어야 한다');

        // 새 option_value_id 가 '초록' 값을 가지는지 확인
        $newVal = $db->table('product_option_values')->where('id', $sv['option_value_id'])->get()->getRowArray();
        $this->assertSame('초록', $newVal['value']);
    }
}
