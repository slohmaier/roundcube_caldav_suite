<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
