<?php

namespace App\Libraries\AiProvider;

/**
 * AI 작업 큐 디스패처.
 *
 * 작업 타입(type)을 핸들러에 매핑한다. 각 핸들러는 payload 배열을 받아
 * 결과 배열을 반환하는 callable(`fn (array $payload): array`)이다.
 *
 * 2단계 기능(예: 리뷰 요약)은 defaultHandlers()에 타입을 추가하기만 하면 된다.
 */
class AiJobRunner
{
    /** @var array<string,callable> */
    private array $handlers;

    /**
     * @param array<string,callable>|null $handlers 테스트·확장용 핸들러 주입 (null이면 기본값)
     */
    public function __construct(?array $handlers = null)
    {
        $this->handlers = $handlers ?? self::defaultHandlers();
    }

    /**
     * 기본 핸들러 레지스트리.
     * 2단계에서 'review_summary' 등 실제 핸들러를 여기에 등록한다.
     *
     * @return array<string,callable>
     */
    public static function defaultHandlers(): array
    {
        return [
            'review_summary'   => static fn (array $p): array => (new ReviewSummaryHandler())->handle($p),
            'inquiry_classify' => static fn (array $p): array => (new InquiryClassifyHandler())->handle($p),
        ];
    }

    /** 등록된 타입인지 확인. */
    public function supports(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * 작업을 실행하고 결과 배열을 반환한다.
     *
     * @param  array<string,mixed> $job ai_jobs 레코드
     * @return array<string,mixed>
     *
     * @throws \RuntimeException 미등록 타입인 경우
     */
    public function run(array $job): array
    {
        $type = (string) ($job['type'] ?? '');

        if (! $this->supports($type)) {
            throw new \RuntimeException("미등록 AI 작업 타입: {$type}");
        }

        $payload = json_decode((string) ($job['payload'] ?? '[]'), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $result = ($this->handlers[$type])($payload);

        return is_array($result) ? $result : ['value' => $result];
    }
}
