<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Front\ShopController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 홈페이지 PICK 상품 배지가 신상품/할인 카드로 새어나가는지 검증한다.
 *
 * CI4 view() 헬퍼는 기본 saveData=true라, product_card 파셜을 여러 번 호출할 때
 * 앞선 호출에서 넘긴 옵션 키(card_pick 등)를 뒤 호출이 생략하면 그대로 남는다.
 * ShopController::welcome()을 실제로 호출해 렌더된 HTML을 그대로 검증한다
 * (로직 재구현 없음) — CartAddonGroupingViewTest와 동일한 방식.
 */
final class WelcomePickBadgeLeakTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    private string $prefix;

    /** @var array<int, int> */
    private array $cleanupProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'WPBL' . substr(uniqid(), -6);
        // site_settings 캐시는 DB 그룹과 무관한 단일 파일 캐시라, 다른 DB(default)를
        // 상대로 쌓인 캐시가 남아있으면 tests DB 값과 어긋난다 — 매 테스트 전 무효화.
        cache()->delete('site_settings');
    }

    protected function tearDown(): void
    {
        if ($this->cleanupProductIds !== []) {
            db_connect()->table('products')->whereIn('id', $this->cleanupProductIds)->delete();
        }
        $this->cleanupProductIds = [];
        cache()->delete('site_settings');
        parent::tearDown();
    }

    private function insertProduct(string $suffix, bool $featured): int
    {
        $db = db_connect();
        $db->table('products')->insert([
            'name'         => $this->prefix . $suffix,
            'slug'         => strtolower($this->prefix . $suffix),
            'price'        => 10000,
            'stock'        => 10,
            'status'       => 'on_sale',
            'is_featured'  => $featured ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $this->cleanupProductIds[] = $id;

        return $id;
    }

    private function renderWelcome(): string
    {
        $controller = new ShopController();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller->welcome();
    }

    /** html에서 $needle이 포함된 상품 카드(.col 래퍼) 조각만 잘라낸다 */
    private function cardHtmlFor(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "'{$needle}' 상품명이 렌더돼야 한다");

        $cardStart = strrpos(substr($html, 0, $pos), '<div class="col"');
        $this->assertNotFalse($cardStart, '상품 카드 래퍼를 찾을 수 없다');

        $nextCard = strpos($html, '<div class="col"', $cardStart + 1);

        return $nextCard === false
            ? substr($html, $cardStart)
            : substr($html, $cardStart, $nextCard - $cardStart);
    }

    public function test_pick_badge_does_not_leak_into_new_product_card(): void
    {
        // 최신순으로 뽑히도록 PLAIN을 나중에(더 높은 id로) 넣는다 — getLatest()가 확실히 포함하도록.
        $this->insertProduct('FEAT', featured: true);
        $this->insertProduct('PLAIN', featured: false);

        $html = $this->renderWelcome();

        $featCard  = $this->cardHtmlFor($html, $this->prefix . 'FEAT');
        $plainCard = $this->cardHtmlFor($html, $this->prefix . 'PLAIN');

        $this->assertStringContainsString('>PICK<', $featCard, 'PICK 상품 카드엔 PICK 배지가 있어야 한다');
        $this->assertStringNotContainsString('>PICK<', $plainCard, '신상품 카드엔 PICK 배지가 새어나가면 안 된다');
    }
}
