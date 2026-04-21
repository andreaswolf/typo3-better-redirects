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
        $target = $this->cacheDir . $slug . '.php';
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
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
     * For a specific host, the main file and the per-host version directory are removed.
     * For a full flush, all main files (*.php) and all per-host directories are removed.
     */
    public function invalidate(?string $host = null): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        if ($host !== null) {
            $hash = md5($host);
            $mainFile = $this->cacheDir . $hash . '.php';
            if (file_exists($mainFile)) {
                unlink($mainFile);
            }
            $hostDir = $this->cacheDir . $hash;
            if (is_dir($hostDir)) {
                $this->removeDirectory($hostDir);
            }
            return;
        }
        foreach (glob($this->cacheDir . '*.php') ?: [] as $file) {
            unlink($file);
        }
        // Remove all per-host version directories (named after md5 hashes).
        foreach (glob($this->cacheDir . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->removeDirectory($dir);
        }
    }

    /**
     * Parse the main file for $host and return the version directory name embedded
     * in its require statements, or null if the main file does not exist or predates
     * versioned subdirectories.
     */
    public function readCurrentVersionDir(string $host): ?string
    {
        $mainFile = $this->filePath($host);
        if (!file_exists($mainFile)) {
            return null;
        }
        $hash = md5($host);
        $content = file_get_contents($mainFile);
        if ($content === false) {
            return null;
        }
        if (preg_match('#/' . preg_quote($hash, '#') . '/([^/\'"]+)/' . preg_quote($hash, '#') . '_fq\.php#', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Remove all version subdirectories inside the per-host container except the one
     * named $keepVersionDir.  Called at the start of a new cache write to clean up the
     * version dir from the write *before* the current one (the current version dir is
     * kept so that any in-flight requests can still lazy-load its type files).
     */
    public function pruneOldVersions(string $host, string $keepVersionDir): void
    {
        $hostDir = $this->cacheDir . md5($host);
        if (!is_dir($hostDir)) {
            return;
        }
        foreach (glob($hostDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== $keepVersionDir) {
                $this->removeDirectory($dir);
            }
        }
    }

    private function filePath(string $host): string
    {
        return $this->cacheDir . md5($host) . '.php';
    }

    private function removeDirectory(string $path): void
    {
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
