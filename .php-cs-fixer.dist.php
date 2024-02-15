<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(array('build', 'vendor'))
    ->files()
    ->name('*.php')
;

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => false,
        'array_syntax' => ['syntax' => 'short'],
        'cast_spaces' => ['space' => 'single'],
        'fopen_flags' => false,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => true,
        'list_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'protected_to_private' => false,
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_line_span' => true,
        'phpdoc_summary' => false,
        'phpdoc_to_comment' => false,
        'class_definition' => ['multi_line_extends_each_single_line' => true],
    ])
;
