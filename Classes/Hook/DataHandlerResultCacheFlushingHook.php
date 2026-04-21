<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Hook;

use a9f\BetterRedirects\Cache\MatchResultCacheInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Flushes the per-request result cache (Layer 1) when a sys_redirect record changes.
 *
 * Mirrors the logic of DataHandlerCacheFlushingHook from cms-redirects, but invalidates
 * the MatchResultCache instead of rebuilding the redirect index.  The PHP file cache
 * (Layer 2) is rebuilt by CachingRedirectCacheService::rebuildForHost, which is called
 * by DataHandlerCacheFlushingHook via the RedirectCacheService alias.
 */
class DataHandlerResultCacheFlushingHook
{
    public function flushResultCacheIfNecessary(array $parameters, DataHandler $dataHandler): void
    {
        if (
            ($parameters['table'] ?? false) !== 'sys_redirect'
            || !($parameters['uid'] ?? false)
            || (
                !isset($dataHandler->datamap['sys_redirect'])
                && !isset($dataHandler->cmdmap['sys_redirect'][(int)$parameters['uid']])
            )
        ) {
            return;
        }

        $matchResultCache = GeneralUtility::makeInstance(MatchResultCacheInterface::class);
        $sourceHosts = $this->resolveSourceHosts($parameters, $dataHandler);

        if ($sourceHosts !== []) {
            foreach (array_unique($sourceHosts) as $sourceHost) {
                $matchResultCache->invalidate($sourceHost);
            }
            return;
        }

        // Safety fallback: flush all result cache entries when source hosts are unknown
        $matchResultCache->invalidate(null);
    }

    private function resolveSourceHosts(array $parameters, DataHandler $dataHandler): array
    {
        $uid = (int)$parameters['uid'];
        $historyKey = 'sys_redirect:' . $uid;
        $sourceHosts = [];

        if (isset($dataHandler->getHistoryRecords()[$historyKey]['oldRecord']['source_host'])) {
            $sourceHosts[] = $dataHandler->getHistoryRecords()[$historyKey]['oldRecord']['source_host'];
        }
        if (isset($dataHandler->getHistoryRecords()[$historyKey]['newRecord']['source_host'])) {
            $sourceHosts[] = $dataHandler->getHistoryRecords()[$historyKey]['newRecord']['source_host'];
        }

        // For delete commands the history record may not exist — look up from DB directly
        if ($sourceHosts === [] && isset($dataHandler->cmdmap['sys_redirect'][$uid])) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('source_host')
                ->from('sys_redirect')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAssociative();

            if (isset($row['source_host'])) {
                $sourceHosts[] = $row['source_host'] ?: '*';
            }
        }

        return $sourceHosts;
    }
}
