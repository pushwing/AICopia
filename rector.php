<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Rector 설정 — PHP 8.5 기준 코드 현대화/품질 개선.
 *
 *   composer rector       # 미리보기 (dry-run, 변경 없음)
 *   composer rector-fix   # 실제 적용
 *
 * 적용은 반드시 PR 단위로 검토할 것 (대량 자동 변경 주의).
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app/Controllers',
        __DIR__ . '/app/Models',
        __DIR__ . '/app/Libraries',
        __DIR__ . '/app/Filters',
        __DIR__ . '/app/Traits',
        __DIR__ . '/app/Commands',
    ])
    ->withSkip([
        __DIR__ . '/app/Views',
        // #[\Override]는 CI4 Model의 프로퍼티($table 등)에까지 무의미하게 붙어
        // 노이즈만 유발하므로 관련 룰(메서드·프로퍼티)을 모두 제외한다.
        AddOverrideAttributeToOverriddenMethodsRector::class,
        AddOverrideAttributeToOverriddenPropertiesRector::class,
        // 캐스트 제거 시 괄호까지 함께 지워 연산자 우선순위를 바꾸는 오작동이 있어 제외
        // (예: `. (int) ($skuId ?? 0)` → `. $skuId ?? 0`).
        RecastingRemovalRector::class,
    ])
    ->withPhpSets(php85: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ]);
