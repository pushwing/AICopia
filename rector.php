<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Rector 설정 — PHP 8.1 기준 코드 현대화/품질 개선.
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
    ])
    ->withPhpSets(php81: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_81,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ]);
