<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\InquiryController;
use App\Controllers\Front\BoardController;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 쿼리스트링을 href 에 수동 연결하면서 이스케이프를 빠뜨린 반사형 XSS 검증
 * (이슈 #122 게시판 검색 type, 이슈 #120 관리자 문의목록 filter·category)
 *
 * 두 화면 모두 사용자 입력을 검증 없이 받아 `'&type=' . $searchType` 처럼
 * 문자열로 이어 붙였다. 렌더 결과에 원본 페이로드가 그대로 나오면 안 된다.
 */
final class HrefQueryStringXssTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;

    /** 속성을 탈출하는 대표 페이로드 */
    private const string PAYLOAD = '"><img src=x onerror=alert(1)>';

    private string $prefix;

    /** @var array<string, array<int, int>> */
    private array $cleanup = ['posts' => [], 'boards' => [], 'inquiries' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'HQXS' . substr(uniqid(), -6) . '_';
        session()->destroy();
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['posts', 'boards', 'inquiries'] as $table) {
            if ($this->cleanup[$table] !== []) {
                $db->table($table)->whereIn('id', $this->cleanup[$table])->delete();
            }
        }
        $this->cleanup = ['posts' => [], 'boards' => [], 'inquiries' => []];
        session()->destroy();
        $_GET = [];
        parent::tearDown();
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    /** 공유 request 서비스에 GET 파라미터를 주입한다. */
    private function withQuery(array $params): void
    {
        service('request')->setGlobal('get', $params);
    }

    private function insertBoardWithPosts(int $postCount): string
    {
        $slug = $this->prefix . substr(uniqid(), -6);
        $db   = db_connect();
        $db->table('boards')->insert([
            'slug'            => $slug,
            'name'            => $slug,
            'read_permission' => 'guest',
            'posts_per_page'  => 5,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $boardId = (int) $db->insertID();
        $this->cleanup['boards'][] = $boardId;

        for ($i = 0; $i < $postCount; $i++) {
            $db->table('posts')->insert([
                'board_id'    => $boardId,
                'user_id'     => null,
                // 제목·본문 모두에 prefix 를 넣어 type=title/content/기타 어느 분기로도 매칭되게 한다
                'title'       => $this->prefix . 'keyword ' . $i,
                'content'     => $this->prefix . ' body ' . $i,
                'author_name' => '작성자',
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            $this->cleanup['posts'][] = (int) $db->insertID();
        }

        return $slug;
    }

    private function insertInquiries(int $count): void
    {
        $db = db_connect();
        for ($i = 0; $i < $count; $i++) {
            $db->table('inquiries')->insert([
                'name'       => $this->prefix . $i,
                'email'      => $this->prefix . $i . '@example.test',
                'message'    => '문의 본문',
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->cleanup['inquiries'][] = (int) $db->insertID();
        }
    }

    private function boot(object $controller): object
    {
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    // ── #122 게시판 검색 type ─────────────────────────────────────────────────

    public function testBoardListDoesNotReflectRawSearchTypeIntoHref(): void
    {
        $slug = $this->insertBoardWithPosts(12); // posts_per_page=5 → 페이지네이션 렌더

        $this->withQuery(['keyword' => $this->prefix, 'type' => self::PAYLOAD]);
        $html = $this->boot(new BoardController())->index($slug);

        $this->assertStringNotContainsString(
            self::PAYLOAD,
            $html,
            '검색 type 파라미터가 이스케이프 없이 반사됐다',
        );
        $this->assertStringNotContainsString('onerror=alert(1)', $html);
    }

    public function testBoardListKeepsValidSearchTypeWorking(): void
    {
        $slug = $this->insertBoardWithPosts(12);

        $this->withQuery(['keyword' => $this->prefix, 'type' => 'content']);
        $html = $this->boot(new BoardController())->index($slug);

        $this->assertStringContainsString('type=content', $html, '정상 검색 타입이 링크에서 사라졌다');
    }

    // ── #120 관리자 문의목록 filter·category ──────────────────────────────────

    public function testInquiryListDoesNotReflectRawFilterIntoHref(): void
    {
        $this->insertInquiries(1);
        session()->set(['user_id' => 1, 'user_role' => 'admin']);

        // 분류 탭은 페이지네이션과 무관하게 항상 렌더된다
        $this->withQuery(['filter' => self::PAYLOAD]);
        $html = $this->boot(new InquiryController())->index();

        $this->assertStringNotContainsString(
            self::PAYLOAD,
            $html,
            'filter 파라미터가 이스케이프 없이 반사됐다',
        );
        $this->assertStringNotContainsString('onerror=alert(1)', $html);
    }

    public function testInquiryListDoesNotReflectRawCategoryIntoHref(): void
    {
        // category 는 페이지네이션 링크에서만 반사되므로 2페이지 이상이 되게 한다 (limit 20)
        $this->insertInquiries(21);
        session()->set(['user_id' => 1, 'user_role' => 'admin']);

        $this->withQuery(['category' => self::PAYLOAD]);
        $html = $this->boot(new InquiryController())->index();

        $this->assertStringNotContainsString(
            self::PAYLOAD,
            $html,
            'category 파라미터가 이스케이프 없이 반사됐다',
        );
        $this->assertStringNotContainsString('onerror=alert(1)', $html);
    }

    public function testInquiryListKeepsValidFilterWorking(): void
    {
        $this->insertInquiries(1);
        session()->set(['user_id' => 1, 'user_role' => 'admin']);

        $this->withQuery(['filter' => 'unread']);
        $html = $this->boot(new InquiryController())->index();

        $this->assertStringContainsString('filter=unread', $html, '정상 filter 링크가 사라졌다');
    }

    public function testInquiryListKeepsValidCategoryWorking(): void
    {
        $this->insertInquiries(21);
        session()->set(['user_id' => 1, 'user_role' => 'admin']);

        $this->withQuery(['category' => 'refund']);
        $html = $this->boot(new InquiryController())->index();

        $this->assertStringContainsString('category=refund', $html, '정상 category 링크가 사라졌다');
    }
}
