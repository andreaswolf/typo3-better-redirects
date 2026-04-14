<?php

declare(strict_types=1);

use a9f\BetterRedirects\Cache\MatchResultCache;
use a9f\BetterRedirects\Cache\MatchResultCacheInterface;
use a9f\BetterRedirects\Cache\RedirectMatcherGenerator;
use a9f\BetterRedirects\Service\CachingRedirectService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Redirects\Service\RedirectService;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load('a9f\\BetterRedirects\\', __DIR__ . '/../Classes/*');

    // Register the TYPO3 cache frontend as an injectable DI service.
    // The cache must be configured in ext_localconf.php before this is used.
    $services->set('cache.better_redirects')
        ->class(FrontendInterface::class)
        ->factory([service( CacheManager::class), 'getCache'])
        ->arg('$identifier', 'better_redirects');

    // Bind interface → implementation.
    // Must be public so GeneralUtility::makeInstance(MatchResultCacheInterface::class) works in the hook.
    $services->alias(MatchResultCacheInterface::class, MatchResultCache::class)->public();

    // Wire the configurable split threshold for the PHP file cache generator.
    // Redirects per match-type bucket above this count are split into shard files.
    $services->set(RedirectMatcherGenerator::class)
        ->arg('$splitThreshold', (int)($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['better_redirects']['splitThreshold'] ?? 1000));

    // Replace RedirectService with our caching subclass.
    // RedirectHandler injects RedirectService by concrete class name, so we alias it.
    // Must be public so it can be retrieved from the container where needed.
    $services->alias(RedirectService::class, CachingRedirectService::class)->public();
};
