<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'phpdoc_to_comment' => false,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__.'/src', __DIR__.'/tests']));
