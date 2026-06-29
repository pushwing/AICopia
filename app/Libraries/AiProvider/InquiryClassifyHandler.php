<?php

namespace App\Libraries\AiProvider;

use App\Exceptions\AiKeyMissingException;
use App\Libraries\AiCategoryAdvisor;
use App\Models\InquiryModel;

/**
 * ai_jobs 'inquiry_classify' 핸들러.
 *
 * 신규 문의를 수신 시 AI로 분류(카테고리·우선순위·감성)하여 저장한다.
 * 관리자 요청을 막지 않도록 백그라운드 워커에서 처리한다.
 */
class InquiryClassifyHandler
{
    private InquiryModel $inquiryModel;

    public function __construct(
        private ?AiProviderInterface $provider = null,
        ?InquiryModel $inquiryModel = null
    ) {
        $this->inquiryModel = $inquiryModel ?? new InquiryModel();
    }

    /** 문의 수신 시 자동 분류 작업을 큐에 적재한다. */
    public static function enqueue(int $inquiryId): void
    {
        if ($inquiryId > 0) {
            model('AiJobModel')->enqueue('inquiry_classify', ['inquiry_id' => $inquiryId]);
        }
    }

    /**
     * @param  array<string,mixed> $payload ['inquiry_id' => int]
     * @return array<string,mixed>
     */
    public function handle(array $payload): array
    {
        $inquiryId = (int) ($payload['inquiry_id'] ?? 0);
        if ($inquiryId <= 0) {
            return ['skipped' => true, 'reason' => 'invalid_inquiry'];
        }

        $inquiry = $this->inquiryModel->find($inquiryId);
        if (! $inquiry) {
            return ['skipped' => true, 'reason' => 'inquiry_not_found'];
        }

        try {
            $provider = $this->provider ?? AiCategoryAdvisor::create();
        } catch (AiKeyMissingException) {
            return ['skipped' => true, 'reason' => 'no_api_key'];
        }

        $result = $provider->classifyInquiry(
            (string) ($inquiry['subject'] ?? ''),
            (string) $inquiry['message']
        );

        $this->inquiryModel->applyClassification($inquiryId, $result);

        return ['ok' => true] + $result;
    }
}
