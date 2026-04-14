<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use TYPO3\CMS\Redirects\Service\RedirectCacheService;

/**
 * Orchestrates lazy generation and host-by-host matching using PHP file caches.
 *
 * Iterates [$domain, '*'] in the same order as RedirectService::matchRedirect()
 * and returns the first matching redirect. Returns false when a PHP file cannot
 * be loaded or generated, signalling the caller to fall back to the full TYPO3
 * redirect lookup path.
 *
 * Note: BeforeRedirectMatchDomainEvent is NOT dispatched on PHP-file-cache hits,
 * which is the same accepted trade-off as the existing per-request result cache.
 */
class PhpFileRedirectMatcherService
{
    public function __construct(
        private readonly RedirectCacheService $redirectCacheService,
        private readonly RedirectMatcherGenerator $generator,
        private readonly PhpFileRedirectCache $fileCache,
    ) {}

    /**
     * @return false|null|array<string, mixed>
     *   false  = PHP file not available; caller should fall back to parent matching
     *   null   = no redirect matched
     *   array  = matched redirect record
     */
    public function match(string $domain, string $path, string $query): false|null|array
    {
        foreach ([$domain, '*'] as $host) {
            try {
                $matcher = $this->getOrGenerateMatcher($host);
            } catch (\Throwable) {
                return false;
            }

            $result = $matcher->match($path, $query);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function getOrGenerateMatcher(string $host): GeneratedRedirectMatcherBase
    {
        if (!$this->fileCache->exists($host)) {
            $redirects = $this->redirectCacheService->getRedirects($host);
            foreach ($this->generator->generateFiles($host, $redirects) as $slug => $code) {
                $this->fileCache->writeSlug($slug, $code);
            }
            // The main file ({hash}.php) is the last slug yielded; its presence
            // signals that all type files were written successfully.
        }
        return $this->fileCache->load($host);
    }
}
