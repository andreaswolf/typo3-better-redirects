<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'Better Redirects',
    'description' => 'Improved redirects handling for TYPO3, including optimized caching for large numbers of redirects',
    'category' => 'misc',
    'version' => '0.1.0',
    'state' => 'beta',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'redirects' => '',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
