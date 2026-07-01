<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CategoryModel FAQ 인코딩/디코딩 헬퍼 검증 — 이슈 #34 (카테고리 랜딩)
 */
final class CategoryFaqTest extends CIUnitTestCase
{
    public function testEncodeFaqFromLinesParsesValidLines(): void
    {
        $raw  = "배송 얼마나 걸리나요? || 2~3일 소요됩니다.\n교환 되나요? || 7일 이내 가능합니다.";
        $json = CategoryModel::encodeFaqFromLines($raw);

        $this->assertIsString($json);
        $decoded = json_decode((string) $json, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('배송 얼마나 걸리나요?', $decoded[0]['question']);
        $this->assertSame('2~3일 소요됩니다.', $decoded[0]['answer']);
    }

    public function testEncodeFaqSkipsLinesWithoutSeparatorOrEmptySide(): void
    {
        $raw  = "구분자 없는 줄\n질문만 || \n || 답변만\n정상 질문 || 정상 답변";
        $json = CategoryModel::encodeFaqFromLines($raw);

        $decoded = json_decode((string) $json, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('정상 질문', $decoded[0]['question']);
    }

    public function testEncodeFaqReturnsNullWhenNoValidLines(): void
    {
        $this->assertNull(CategoryModel::encodeFaqFromLines(''));
        $this->assertNull(CategoryModel::encodeFaqFromLines("구분자 없음\n또 없음"));
    }

    public function testDecodeFaqFiltersIncompleteEntries(): void
    {
        $json = json_encode([
            ['question' => 'Q1', 'answer' => 'A1'],
            ['question' => 'Q2', 'answer' => ''],   // 답변 없음 → 제외
            ['question' => '', 'answer' => 'A3'],    // 질문 없음 → 제외
        ]);

        $items = CategoryModel::decodeFaq($json);
        $this->assertCount(1, $items);
        $this->assertSame('Q1', $items[0]['question']);
    }

    public function testDecodeFaqHandlesNullAndInvalidJson(): void
    {
        $this->assertSame([], CategoryModel::decodeFaq(null));
        $this->assertSame([], CategoryModel::decodeFaq(''));
        $this->assertSame([], CategoryModel::decodeFaq('not-json'));
    }

    public function testFaqRoundTripLinesToJsonToLines(): void
    {
        $raw  = 'Q1 || A1';
        $json = CategoryModel::encodeFaqFromLines($raw);
        $this->assertSame('Q1 || A1', CategoryModel::faqToLines($json));
    }
}
