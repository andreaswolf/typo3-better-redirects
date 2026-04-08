<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Service;

use a9f\BetterRedirects\Cache\MatchResultCacheInterface;
use a9f\BetterRedirects\Cache\PhpFileRedirectMatcherService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\CMS\Redirects\Service\RedirectService;

class CachingRedirectService extends RedirectService
{
    public function __construct(
        RedirectCacheService $redirectCacheService,
        LinkService $linkService,
        SiteFinder $siteFinder,
        EventDispatcherInterface $eventDispatcher,
        PageInformationFactory $pageInformationFactory,
        FrontendTypoScriptFactory $frontendTypoScriptFactory,
        // Must repeat #[Autowire] — PHP does not inherit constructor attributes from parent
        #[Autowire(service: 'cache.typoscript')]
        PhpFrontend $typoScriptCache,
        LoggerInterface $logger,
        private readonly MatchResultCacheInterface $matchResultCache,
        private readonly PhpFileRedirectMatcherService $phpFileMatcher,
    ) {
        parent::__construct(
            $redirectCacheService,
            $linkService,
            $siteFinder,
            $eventDispatcher,
            $pageInformationFactory,
            $frontendTypoScriptFactory,
            $typoScriptCache,
            $logger,
        );
    }

    public function matchRedirect(string $domain, string $path, string $query = ''): ?array
    {
        // Layer 1: per-request result cache (keyed by domain+path+query)
        $cached = $this->matchResultCache->get($domain, $path, $query);
        if ($cached !== false) {
            return $cached;
        }

        // Layer 2: PHP-file-compiled matcher (OPcache-resident trie + regex arrays)
        $result = $this->phpFileMatcher->match($domain, $path, $query);
        if ($result !== false) {
            $this->matchResultCache->set($domain, $path, $query, $result, $this->computeLifetime($result));
            return $result;
        }

        // Layer 3: TYPO3 cache / DB fallback (fires BeforeRedirectMatchDomainEvent)
        $result = parent::matchRedirect($domain, $path, $query);
        $this->matchResultCache->set($domain, $path, $query, $result, $this->computeLifetime($result));
        return $result;
    }

    private function computeLifetime(?array $redirect): int
    {
        if ($redirect === null) {
            // Short TTL: a redirect with a future starttime could activate soon
            return 3600;
        }
        $now = $GLOBALS['SIM_ACCESS_TIME'] ?? time();
        if (!empty($redirect['endtime']) && $redirect['endtime'] > $now) {
            // Don't cache past the redirect's endtime
            return min((int)$redirect['endtime'] - $now, 86400);
        }
        return 86400;
    }
}
