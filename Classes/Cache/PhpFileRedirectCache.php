<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Manages generated PHP matcher files in var/cache/code/better_redirects/.
 *
 * One file is written per source host (including '*' for wildcard redirects).
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
     * Write the generated PHP code for a host atomically.
     * Creates the cache directory if it does not exist yet.
     */
    public function write(string $host, string $phpCode): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $target = $this->filePath($host);
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
     * Invalidate the cached file for a specific host, or all files when $host is null.
     */
    public function invalidate(?string $host = null): void
    {
        if ($host !== null) {
            $file = $this->filePath($host);
            if (file_exists($file)) {
                unlink($file);
            }
            return;
        }
        if (!is_dir($this->cacheDir)) {
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
