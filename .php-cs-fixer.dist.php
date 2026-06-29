<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer 설정 — PSR-12 기반 + strict_types 강제.
 *
 *   composer cs       # 검사만 (CI/로컬 점검)
 *   composer cs-fix   # 자동 수정
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->exclude(['Views'])      // CI4 뷰는 HTML 혼합이라 제외
    ->ignoreVCSIgnored(true)  // gitignore된 복원 스켈레톤(app/Config 표준 파일 등) 제외
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        'declare_strict_types'         => true,  // 모든 파일에 declare(strict_types=1)
        'array_syntax'                 => ['syntax' => 'short'],
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'            => true,
        'single_quote'                 => true,
        'trailing_comma_in_multiline'  => true,
        'no_trailing_whitespace'       => true,
        'native_function_casing'       => true,
        'no_empty_statement'           => true,
        'no_extra_blank_lines'         => true,
        'single_blank_line_at_eof'     => true,
    ])
    ->setFinder($finder);
