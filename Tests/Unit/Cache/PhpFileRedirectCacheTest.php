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
        // Clean up temp directory
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        unset($GLOBALS['SIM_ACCESS_TIME']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // exists / write / load round-trip
    // -------------------------------------------------------------------------

    #[Test]
    public function existsReturnsFalseWhenNoFilePresent(): void
    {
        self::assertFalse($this->cache->exists('example.com'));
    }

    #[Test]
    public function existsReturnsTrueAfterWrite(): void
    {
        $this->cache->write('example.com', $this->generateCode('example.com'));
        self::assertTrue($this->cache->exists('example.com'));
    }

    #[Test]
    public function loadReturnsMatcherInstanceAfterWrite(): void
    {
        $host = 'load-test-' . uniqid();
        $this->cache->write($host, $this->generateCode($host));
        $matcher = $this->cache->load($host);
        self::assertInstanceOf(GeneratedRedirectMatcherBase::class, $matcher);
    }

    #[Test]
    public function loadedMatcherFunctionsCorrectly(): void
    {
        $host     = 'functional-' . uniqid();
        $redirect = ['uid' => 77, 'disabled' => 0, 'starttime' => 0, 'endtime' => 0, 'target' => '/new'];
        $code     = $this->generator->generate($host, [
            'flat' => ['/old/' => [77 => $redirect]],
        ]);

        $this->cache->write($host, $code);
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
        $this->cache->write($host, $this->generateCode($host));

        $files = glob($this->tmpDir . '/*.tmp.*') ?: [];
        self::assertSame([], $files, 'No .tmp.* files should remain after write()');
    }

    // -------------------------------------------------------------------------
    // invalidate($host) — removes only that host's file
    // -------------------------------------------------------------------------

    #[Test]
    public function invalidateRemovesOnlyTheSpecifiedHostFile(): void
    {
        $hostA = 'host-a-' . uniqid();
        $hostB = 'host-b-' . uniqid();

        $this->cache->write($hostA, $this->generateCode($hostA));
        $this->cache->write($hostB, $this->generateCode($hostB));

        $this->cache->invalidate($hostA);

        self::assertFalse($this->cache->exists($hostA));
        self::assertTrue($this->cache->exists($hostB));
    }

    #[Test]
    public function invalidateSingleHostIsIdempotentWhenFileDoesNotExist(): void
    {
        // Should not throw when the file is absent
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
            $this->cache->write($host, $this->generateCode($host));
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
    // Helpers
    // -------------------------------------------------------------------------

    private function generateCode(string $host): string
    {
        return $this->generator->generate($host, []);
    }
}
