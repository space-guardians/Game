<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in(__DIR__)
    ->exclude(['var', 'vendor'])
    ->notPath(['config/bundles.php', 'config/reference.php']);

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        // PER-CS 3.1 : PHP-CS-Fixer ne fournit que @PER-CS3x0, complété ci-dessous
        // par les règles ajoutées en 3.1. Passer à @PER-CS3x1 dès qu'il existe.
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'no_break_comment' => ['comment_text' => 'No break'],
        'nullable_type_declaration_for_default_null_value' => true,

        // Bonnes pratiques du projet
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    ]);
