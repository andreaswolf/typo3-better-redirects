<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Service;

use a9f\BetterRedirects\Cache\PhpFileRedirectCache;
use a9f\BetterRedirects\Cache\RedirectMatcherGenerator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

/**
 * Extends TYPO3's RedirectCacheService so that every time the redirect index is rebuilt
 * (i.e. rebuildForHost / rebuildAll), the PHP file cache (Layer 2) is also regenerated
 * atomically for the affected host.
 *
 * Wired as a transparent DI alias in Configuration/Services.php so that all call sites
 * (DataHandlerCacheFlushingHook, SlugService, …) transparently use this subclass.
 *
 * @phpstan-import-type RedirectRow from \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
 */
class CachingRedirectCacheService extends RedirectCacheService
{
    public function __construct(
        private readonly PhpFileRedirectCache $fileCache,
        private readonly RedirectMatcherGenerator $generator,
        ?CacheManager $cacheManager = null,
    ) {
        parent::__construct($cacheManager);
    }

    /**
     * Rebuild the TYPO3 redirect index for $sourceHost and immediately regenerate the
     * PHP file cache for that host from the freshly-fetched redirect data.
     *
     * @return array{
     *     flat?: array<string, array<int, RedirectRow>>,
     *     respect_query_parameters?: array<string, array<int, RedirectRow>>,
     *     regexp_flat?: array<string, array<int, RedirectRow>>,
     *     regexp_query_parameters?: array<string, array<int, RedirectRow>>,
     * }
     */
    public function rebuildForHost(string $sourceHost): array
    {
        $redirects = parent::rebuildForHost($sourceHost);
        $this->rebuildPhpFileCache($sourceHost, $redirects);
        return $redirects;
    }

    /**
     * Write a new versioned set of PHP matcher files for $host, keeping the currently
     * active version directory alive so that in-flight requests can still lazy-load its
     * type files.  The atomic rename of the main file ({hash}.php) switches all new
     * readers to the new version; the old version directory is pruned on the next write.
     *
     * @param array{
     *     flat?: array<string, array<int, RedirectRow>>,
     *     respect_query_parameters?: array<string, array<int, RedirectRow>>,
     *     regexp_flat?: array<string, array<int, RedirectRow>>,
     *     regexp_query_parameters?: array<string, array<int, RedirectRow>>,
     * } $redirects
     */
    private function rebuildPhpFileCache(string $host, array $redirects): void
    {
        $currentVersionDir = $this->fileCache->readCurrentVersionDir($host);
        if ($currentVersionDir !== null) {
            $this->fileCache->pruneOldVersions($host, $currentVersionDir);
        }

        $newVersionDir = date('Ymd_His') . '_' . getmypid();
        foreach ($this->generator->generateFiles($host, $redirects, $newVersionDir) as $slug => $code) {
            $this->fileCache->writeSlug($slug, $code);
        }
    }
}