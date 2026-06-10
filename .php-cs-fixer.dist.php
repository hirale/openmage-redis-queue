<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/app/code/community/Hirale/Queue',
        __DIR__ . '/lib',
        __DIR__ . '/tests',
    ])
    ->exclude([
        // The install script intentionally follows the Magento 1 setup-script
        // style; leave it out of the auto-formatter.
        __DIR__ . '/app/code/community/Hirale/Queue/sql',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'cast_spaces' => ['space' => 'single'],
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
