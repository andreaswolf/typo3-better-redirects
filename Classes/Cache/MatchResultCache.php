<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MatchResultCache implements MatchResultCacheInterface
{
    private const TAG_ALL = 'tx_redirects';
    private const TAG_HOST_PREFIX = 'redirect_host_';

    private FrontendInterface $cache;

    public function __construct(
        #[Autowire(service: 'cache.better_redirects')]
        ?FrontendInterface $cache = null,
    ) {
        // Fall back to CacheManager for instantiation via GeneralUtility::makeInstance()
        $this->cache = $cache ?? GeneralUtility::makeInstance(CacheManager::class)->getCache('better_redirects');
    }

    public function get(string $domain, string $path, string $query): false|null|array
    {
        return $this->cache->get($this->buildCacheKey($domain, $path, $query));
    }

    public function set(string $domain, string $path, string $query, ?array $redirect, int $lifetime): void
    {
        $this->cache->set(
            $this->buildCacheKey($domain, $path, $query),
            $redirect,
            [self::TAG_ALL, self::TAG_HOST_PREFIX . sha1($domain)],
            $lifetime,
        );
    }

    public function invalidate(?string $sourceHost = null): void
    {
        if ($sourceHost === null) {
            $this->cache->flushByTag(self::TAG_ALL);
        } else {
            $this->cache->flushByTag(self::TAG_HOST_PREFIX . sha1($sourceHost));
        }
    }

    private function buildCacheKey(string $domain, string $path, string $query): string
    {
        return sha1($domain . '|' . $path . '|' . $query);
    }
}
