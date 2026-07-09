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
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\CMS\Redirects\Service\RedirectService;

/**
 * @phpstan-import-type RedirectRow from \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
 */
readonly class CachingRedirectService extends RedirectService
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
        TypoLinkCodecService $typoLinkCodecService,
        private MatchResultCacheInterface $matchResultCache,
        private PhpFileRedirectMatcherService $phpFileMatcher,
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
            $typoLinkCodecService,
        );
    }

    /**
     * @return RedirectRow|null
     */
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
        if ($result !== null && !self::isRedirectRow($result)) {
            // parent::matchRedirect() is untyped in TYPO3 core; sys_redirect rows always
            // contain these columns, so this can only happen if that contract is broken.
            throw new \UnexpectedValueException(
                'Redirect record from RedirectService::matchRedirect() is missing expected columns.',
                1_752_100_000,
            );
        }
        $this->matchResultCache->set($domain, $path, $query, $result, $this->computeLifetime($result));
        return $result;
    }

    /**
     * @param array<string, mixed> $redirect
     * @phpstan-assert-if-true RedirectRow $redirect
     */
    private static function isRedirectRow(array $redirect): bool
    {
        return array_key_exists('uid', $redirect)
            && array_key_exists('pid', $redirect)
            && array_key_exists('source_host', $redirect)
            && array_key_exists('source_path', $redirect)
            && array_key_exists('target', $redirect)
            && array_key_exists('target_statuscode', $redirect)
            && array_key_exists('force_https', $redirect)
            && array_key_exists('keep_query_parameters', $redirect)
            && array_key_exists('respect_query_parameters', $redirect)
            && array_key_exists('is_regexp', $redirect)
            && array_key_exists('disabled', $redirect)
            && array_key_exists('starttime', $redirect)
            && array_key_exists('endtime', $redirect)
            && array_key_exists('hitcount', $redirect);
    }

    /**
     * @param RedirectRow|null $redirect
     */
    private function computeLifetime(?array $redirect): int
    {
        if ($redirect === null) {
            // Short TTL: a redirect with a future starttime could activate soon
            return 3600;
        }
        $now = $GLOBALS['SIM_ACCESS_TIME'] ?? time();
        $now = is_int($now) ? $now : (int)$now;
        if (!empty($redirect['endtime']) && $redirect['endtime'] > $now) {
            // Don't cache past the redirect's endtime
            return min((int)$redirect['endtime'] - $now, 86400);
        }
        return 86400;
    }
}
