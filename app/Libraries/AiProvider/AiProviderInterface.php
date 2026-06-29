<?php

namespace App\Libraries\AiProvider;

interface AiProviderInterface
{
    /**
     * 상품명과 설명을 바탕으로 추천 카테고리 ID 배열을 반환한다.
     *
     * @param  string $name        상품명
     * @param  string $description 상품 설명
     * @param  array  $tree        CategoryModel::getTree() 반환값
     * @return int[]               추천 카테고리 ID 배열 (최대 3개)
     */
    public function suggestCategories(string $name, string $description, array $tree): array;

    /**
     * 상품명과 기존 설명을 바탕으로 새로운 상품 설명(HTML)을 생성해 반환한다.
     *
     * @param  string $name        상품명
     * @param  string $description 기존 설명 (참고용, 비어있어도 됨)
     * @return string              생성된 HTML 설명 (실패 시 빈 문자열)
     */
    public function generateDescription(string $name, string $description): string;

    /**
     * 상품 문의에 대한 답변 초안을 생성해 반환한다.
     *
     * @param  string $productName        상품명
     * @param  string $productDescription 상품 설명 (참고용)
     * @param  string $questionTitle      문의 제목
     * @param  string $questionContent    문의 내용
     * @return string                     생성된 답변 텍스트 (실패 시 빈 문자열)
     */
    public function generateQnaAnswer(string $productName, string $productDescription, string $questionTitle, string $questionContent): string;

    /**
     * 상품 리뷰 목록을 요약하고 장단점·감성을 분석한다.
     *
     * @param  string $productName 상품명
     * @param  array  $reviews     [['id' => int, 'content' => string], ...]
     * @return array{summary:string, pros:string[], cons:string[], sentiment:string, negative_review_ids:int[]}
     *               실패 시 빈 요약(summary='')을 담은 기본 구조를 반환한다.
     *               sentiment: 'positive' | 'mixed' | 'negative'
     */
    public function summarizeReviews(string $productName, array $reviews): array;

    /**
     * 고객 문의를 분류·우선순위·감성으로 판정한다.
     *
     * @return array{category:string, priority:string, sentiment:string}
     *               category: shipping|refund|product|payment|etc
     *               priority: high|normal|low / sentiment: positive|neutral|negative
     *               실패 시 안전한 기본값(etc/normal/neutral)을 반환한다.
     */
    public function classifyInquiry(string $subject, string $message): array;

    /**
     * 고객 문의에 대한 이메일 답변 초안을 생성한다.
     *
     * @return string 답변 초안 텍스트 (실패 시 빈 문자열)
     */
    public function generateInquiryReply(string $name, string $subject, string $message): string;

    /**
     * 매출 집계 데이터를 바탕으로 자연어 분석 리포트를 생성한다.
     *
     * @param  array $stats 기간·요약·기간별·결제수단별 집계 (구조화된 배열)
     * @return string 한국어 분석 텍스트 (실패 시 빈 문자열)
     */
    public function generateSalesReport(array $stats): string;

    /**
     * 재입고 알림 메일에 넣을 개인화된 안내 문구를 생성한다.
     *
     * @return string 2~3문장의 일반 텍스트 (실패 시 빈 문자열 → 호출처에서 기본 문구로 폴백)
     */
    public function generateRestockMessage(string $productName, string $productDescription): string;

    /**
     * 검색어를 의미 기반으로 확장한다 (오타 교정 · 동의어 · 관련어).
     *
     * @return string[] 확장된 검색어 목록 (실패 시 빈 배열)
     */
    public function expandSearchQuery(string $query): array;
}
