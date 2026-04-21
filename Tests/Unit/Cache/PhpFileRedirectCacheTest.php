<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Tests\Unit\Cache;

use a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase;
use a9f\BetterRedirects\Cache\PhpFileRedirectCache;
use a9f\BetterRedirects\Cache\RedirectMatcherGenerator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests the PhpFileRedirectCache read/write/invalidate operations using a
 * temporary directory so no TYPO3 environment is required.
 */
class PhpFileRedirectCacheTest extends UnitTestCase
{
    private string $tmpDir;
    private PhpFileRedirectCache $cache;
    private RedirectMatcherGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/better_redirects_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->cache     = new PhpFileRedirectCache($this->tmpDir);
        $this->generator = new RedirectMatcherGenerator();
        $GLOBALS['SIM_ACCESS_TIME'] = time();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        unset($GLOBALS['SIM_ACCESS_TIME']);
        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
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

    // -------------------------------------------------------------------------
    // exists / writeSlug / load round-trip
    // -------------------------------------------------------------------------

    #[Test]
    public function existsReturnsFalseWhenNoFilePresent(): void
    {
        self::assertFalse($this->cache->exists('example.com'));
    }

    #[Test]
    public function existsReturnsTrueAfterWritingAllFiles(): void
    {
        $this->writeAllFiles('example.com');
        self::assertTrue($this->cache->exists('example.com'));
    }

    #[Test]
    public function loadReturnsMatcherInstanceAfterWrite(): void
    {
        $host = 'load-test-' . uniqid();
        $this->writeAllFiles($host);
        $matcher = $this->cache->load($host);
        self::assertInstanceOf(GeneratedRedirectMatcherBase::class, $matcher);
    }

    #[Test]
    public function loadedMatcherFunctionsCorrectly(): void
    {
        $host     = 'functional-' . uniqid();
        $redirect = ['uid' => 77, 'disabled' => 0, 'starttime' => 0, 'endtime' => 0, 'target' => '/new'];

        $this->writeAllFiles($host, [
            'flat' => ['/old/' => [77 => $redirect]],
        ]);
        $matcher = $this->cache->load($host);

        self::assertSame(77, $matcher->match('/old', '')['uid']);
        self::assertNull($matcher->match('/other', ''));
    }

    // -------------------------------------------------------------------------
    // Atomic write: tmp file is used, then renamed
    // -------------------------------------------------------------------------

    #[Test]
    public function writeDoesNotLeaveTemporaryFilesBehind(): void
    {
        $host = 'atomic-' . uniqid();
        $this->writeAllFiles($host);

        // Search recursively for any leftover .tmp.* files (type files are in subdirs)
        $tmpFiles = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir));
        foreach ($it as $file) {
            if ($file->isFile() && str_contains($file->getFilename(), '.tmp.')) {
                $tmpFiles[] = $file->getPathname();
            }
        }
        self::assertSame([], $tmpFiles, 'No .tmp.* files should remain after writeSlug()');
    }

    // -------------------------------------------------------------------------
    // invalidate($host) — removes all files for that host
    // -------------------------------------------------------------------------

    #[Test]
    public function invalidateRemovesAllFilesForTheSpecifiedHost(): void
    {
        $hostA = 'host-a-' . uniqid();
        $hostB = 'host-b-' . uniqid();

        $this->writeAllFiles($hostA);
        $this->writeAllFiles($hostB);

        $this->cache->invalidate($hostA);

        self::assertFalse($this->cache->exists($hostA));
        self::assertTrue($this->cache->exists($hostB));
    }

    #[Test]
    public function invalidateSingleHostIsIdempotentWhenFilesDoNotExist(): void
    {
        // Should not throw when no files are present for the host
        $this->cache->invalidate('nonexistent.host');
        self::assertFalse($this->cache->exists('nonexistent.host'));
    }

    // -------------------------------------------------------------------------
    // invalidate(null) — removes all files
    // -------------------------------------------------------------------------

    #[Test]
    public function invalidateNullRemovesAllCachedFiles(): void
    {
        $hosts = ['site-a-' . uniqid(), 'site-b-' . uniqid(), 'site-c-' . uniqid()];
        foreach ($hosts as $host) {
            $this->writeAllFiles($host);
        }

        $this->cache->invalidate(null);

        foreach ($hosts as $host) {
            self::assertFalse($this->cache->exists($host));
        }
    }

    #[Test]
    public function invalidateNullIsIdempotentWhenDirectoryIsEmpty(): void
    {
        // Should not throw when no files exist
        $this->cache->invalidate(null);
        self::assertSame([], glob($this->tmpDir . '/*.php') ?: []);
    }

    // -------------------------------------------------------------------------
    // pruneOldVersions — keeps current, removes older version dirs
    // -------------------------------------------------------------------------

    #[Test]
    public function pruneOldVersionsRemovesAllButTheKeptVersionDir(): void
    {
        $host = 'prune-' . uniqid();
        $hash = md5($host);

        // Write two versions of the cache.
        $this->writeAllFiles($host, [], 'v_old');
        $this->writeAllFiles($host, [], 'v_new');

        // The main file now points to v_new (last write wins).
        self::assertTrue($this->cache->exists($host));

        // Both version dirs should exist at this point.
        self::assertDirectoryExists($this->tmpDir . '/' . $hash . '/v_old');
        self::assertDirectoryExists($this->tmpDir . '/' . $hash . '/v_new');

        // Prune keeping v_new: v_old should be removed, v_new preserved.
        $this->cache->pruneOldVersions($host, 'v_new');

        self::assertDirectoryDoesNotExist($this->tmpDir . '/' . $hash . '/v_old');
        self::assertDirectoryExists($this->tmpDir . '/' . $hash . '/v_new');

        // The main file must still be loadable.
        self::assertTrue($this->cache->exists($host));
    }

    #[Test]
    public function readCurrentVersionDirReturnsVersionEmbeddedInMainFile(): void
    {
        $host = 'version-read-' . uniqid();

        $this->writeAllFiles($host, [], 'my_version_123');

        self::assertSame('my_version_123', $this->cache->readCurrentVersionDir($host));
    }

    #[Test]
    public function readCurrentVersionDirReturnsNullWhenNoMainFileExists(): void
    {
        self::assertNull($this->cache->readCurrentVersionDir('no-cache.host'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write all generated PHP files for $host to the cache using writeSlug().
     * This mirrors what PhpFileRedirectMatcherService does at runtime.
     */
    private function writeAllFiles(string $host, array $redirects = [], string $versionDir = 'testversion'): void
    {
        foreach ($this->generator->generateFiles($host, $redirects, $versionDir) as $slug => $code) {
            $this->cache->writeSlug($slug, $code);
        }
    }
}
