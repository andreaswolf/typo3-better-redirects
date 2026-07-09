<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

/**
 * @phpstan-import-type RedirectRow from \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
 */
interface MatchResultCacheInterface
{
    /**
     * Returns the cached match result for the given request tuple.
     *
     * Return values:
     *   false  — cache miss (no entry stored yet)
     *   null   — cached "no match" (a redirect lookup was done and found nothing)
     *   array  — cached redirect record
     *
     * @return RedirectRow|false|null
     */
    public function get(string $domain, string $path, string $query): false|null|array;

    /**
     * Stores a match result. Pass null for $redirect when no redirect was found.
     *
     * @param RedirectRow|null $redirect
     */
    public function set(string $domain, string $path, string $query, ?array $redirect, int $lifetime): void;

    /**
     * Invalidates cached entries.
     *
     * Pass null to flush all result cache entries.
     * Pass a source host string to flush only entries for that host.
     */
    public function invalidate(?string $sourceHost = null): void;
}
