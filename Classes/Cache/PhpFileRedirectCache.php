<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Manages generated PHP matcher files in var/cache/code/better_redirects/.
 *
 * Multiple files are written per source host: one main class file and one file
 * per match type (flat-with-query, trie, regex-query, regex-flat), with optional
 * shard files when a type exceeds the split threshold.  The main file ({hash}.php)
 * is always written last, so its presence signals that all type files are complete.
 * Writes are atomic (write to tmp, then rename) to avoid serving partial files
 * under concurrent load.
 */
class PhpFileRedirectCache
{
    private string $cacheDir;

    /**
     * @param string $cacheDir Override the cache directory (empty = use TYPO3 var path).
     *                         Useful for tests that pass a temp directory.
     */
    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir !== ''
            ? rtrim($cacheDir, '/') . '/'
            : Environment::getVarPath() . '/cache/code/better_redirects/';
    }

    public function exists(string $host): bool
    {
        return file_exists($this->filePath($host));
    }

    /**
     * Write a generated PHP file identified by its slug (file name without extension)
     * atomically.  Slugs are structured as:
     *   {md5(host)}           — main matcher class
     *   {md5(host)}_fq        — flat-with-query type file (or dispatcher)
     *   {md5(host)}_fq_N      — flat-with-query shard N
     *   {md5(host)}_tr        — trie type file (or dispatcher)
     *   {md5(host)}_tr_{md5}  — trie segment shard
     *   {md5(host)}_rq[_N]    — regex-query-params type file (or shard)
     *   {md5(host)}_rf[_N]    — regex-flat type file (or shard)
     *
     * Creates the cache directory if it does not exist yet.
     */
    public function writeSlug(string $slug, string $phpCode): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $target = $this->cacheDir . $slug . '.php';
        $tmp = $target . '.tmp.' . getmypid();
        file_put_contents($tmp, $phpCode);
        rename($tmp, $target);
    }

    /**
     * Load the generated matcher for a host.
     * The file must exist; call exists() first or rely on write() having been called.
     */
    public function load(string $host): GeneratedRedirectMatcherBase
    {
        $className = 'a9f\\BetterRedirects\\Cache\\Generated\\RedirectMatcher_' . md5($host);
        if (!class_exists($className, false)) {
            require $this->filePath($host);
        }
        return new $className();
    }

    /**
     * Invalidate all cached files for a specific host, or all files when $host is null.
     *
     * For a specific host, all files whose names start with md5($host) are removed
     * (main file, type files, and any shard files).
     */
    public function invalidate(?string $host = null): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        if ($host !== null) {
            foreach (glob($this->cacheDir . md5($host) . '*.php') ?: [] as $file) {
                unlink($file);
            }
            return;
        }
        foreach (glob($this->cacheDir . '*.php') ?: [] as $file) {
            unlink($file);
        }
    }

    private function filePath(string $host): string
    {
        return $this->cacheDir . md5($host) . '.php';
    }
}
