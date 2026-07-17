<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Event\CacheWarmupEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

#[AsEventListener(identifier: 'better-redirects/cache-warmup')]
class CacheWarmupEventListener
{
    public function __construct(
        private readonly RedirectCacheService $redirectCacheService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function __invoke(CacheWarmupEvent $event): void
    {
        foreach ($this->getDistinctSourceHosts() as $host) {
            try {
                $this->redirectCacheService->rebuildForHost($host);
            } catch (\Throwable $e) {
                $event->addError(
                    sprintf('better_redirects: Failed to warm cache for host "%s": %s', $host, $e->getMessage())
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getDistinctSourceHosts(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $rows = $queryBuilder
            ->select('source_host')
            ->from('sys_redirect')
            ->groupBy('source_host')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): string => $row['source_host'] ?: '*',
            $rows
        );
    }
}
