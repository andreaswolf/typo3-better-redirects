<?php

declare(strict_types=1);

defined('TYPO3') or die();

if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['better_redirects'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['better_redirects'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => 86400],
        'groups' => ['pages'],
    ];
}

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['better_redirects']
    = \a9f\BetterRedirects\Hook\DataHandlerResultCacheFlushingHook::class . '->flushResultCacheIfNecessary';
