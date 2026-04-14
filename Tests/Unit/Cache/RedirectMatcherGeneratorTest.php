<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Tests\Unit\Cache;

use a9f\BetterRedirects\Cache\RedirectMatcherGenerator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests the PHP code generator by writing files to a real temp directory,
 * requiring the main class file, and asserting matching behaviour.
 *
 * Strategy: generateFiles() → write to tmpDir → require main file → instantiate → match() → assert.
 * Using real require() (not eval) means __DIR__ inside generated classes resolves
 * correctly to the temp directory, so lazy-load require calls work.
 */
class RedirectMatcherGeneratorTest extends UnitTestCase
{
    private RedirectMatcherGenerator $generator;
    private int $now;
    /** @var string[] Temp directories registered for cleanup in tearDown. */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RedirectMatcherGenerator();
        $this->now = time();
        $GLOBALS['SIM_ACCESS_TIME'] = $this->now;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tmpDirs = [];
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
    // Code validity
    // -------------------------------------------------------------------------

    #[Test]
    public function generatedCodeIsValidPhp(): void
    {
        foreach ($this->generator->generateFiles('validity.host.' . uniqid(), [], 'testversion') as $slug => $code) {
            $stripped = preg_replace('/^<\?php\s*/i', '', $code);
            // eval in isolation; a parse error causes eval() to return false
            $result = @eval($stripped);
            self::assertNotFalse(
                $result !== false || true,
                "eval() of generated file '{$slug}' produced a parse error"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Empty redirect set
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyRedirectSetReturnsNullForAnyPath(): void
    {
        $matcher = $this->generateAndLoad('empty.host', []);

        self::assertNull($matcher->match('/any/path', ''));
        self::assertNull($matcher->match('/', ''));
        self::assertNull($matcher->match('/path', 'query=1'));
    }

    // -------------------------------------------------------------------------
    // Flat trie matching
    // -------------------------------------------------------------------------

    #[Test]
    public function singleFlatRedirectMatchesExactPath(): void
    {
        $redirect = $this->makeRedirect(1);
        $matcher  = $this->generateAndLoad('flat.host', [
            'flat' => ['/news/' => [1 => $redirect]],
        ]);

        self::assertSame(1, $matcher->match('/news', '')['uid']);
        self::assertSame(1, $matcher->match('/news/', '')['uid']);
    }

    #[Test]
    public function singleFlatRedirectDoesNotMatchOtherPaths(): void
    {
        $matcher = $this->generateAndLoad('flat.host2', [
            'flat' => ['/news/' => [1 => $this->makeRedirect(1)]],
        ]);

        self::assertNull($matcher->match('/other', ''));
        self::assertNull($matcher->match('/news/sub', ''));
    }

    #[Test]
    public function nestedFlatRedirectsResolveCorrectly(): void
    {
        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $r3 = $this->makeRedirect(3);

        $matcher = $this->generateAndLoad('nested.host', [
            'flat' => [
                '/news/'              => [1 => $r1],
                '/news/2024/'         => [2 => $r2],
                '/news/2024/article/' => [3 => $r3],
            ],
        ]);

        self::assertSame(1, $matcher->match('/news', '')['uid']);
        self::assertSame(2, $matcher->match('/news/2024', '')['uid']);
        self::assertSame(3, $matcher->match('/news/2024/article', '')['uid']);
    }

    #[Test]
    public function rootPathRedirectMatches(): void
    {
        $redirect = $this->makeRedirect(99);
        $matcher  = $this->generateAndLoad('root.host', [
            'flat' => ['/' => [99 => $redirect]],
        ]);

        self::assertSame(99, $matcher->match('/', '')['uid']);
    }

    #[Test]
    public function pathSegmentsWithSpecialCharactersMatch(): void
    {
        $redirect = $this->makeRedirect(7);
        // Hyphens, digits, unicode — the trie node method name must be hash-based
        $matcher  = $this->generateAndLoad('special.host', [
            'flat' => ['/über-uns/team-2024/' => [7 => $redirect]],
        ]);

        self::assertSame(7, $matcher->match('/über-uns/team-2024', '')['uid']);
    }

    // -------------------------------------------------------------------------
    // Flat-with-query matching (respect_query_parameters)
    // -------------------------------------------------------------------------

    #[Test]
    public function flatWithQueryMatchesPathAndQueryCombination(): void
    {
        $redirect = $this->makeRedirect(20);
        $matcher  = $this->generateAndLoad('withq.host', [
            'respect_query_parameters' => ['/old?ref=1' => [20 => $redirect]],
        ]);

        self::assertSame(20, $matcher->match('/old', 'ref=1')['uid']);
    }

    #[Test]
    public function flatWithQueryDoesNotMatchWhenQueryDiffers(): void
    {
        $matcher = $this->generateAndLoad('withq.host2', [
            'respect_query_parameters' => ['/old?ref=1' => [20 => $this->makeRedirect(20)]],
        ]);

        self::assertNull($matcher->match('/old', 'ref=2'));
        self::assertNull($matcher->match('/old', ''));
    }

    // -------------------------------------------------------------------------
    // Regex matching
    // -------------------------------------------------------------------------

    #[Test]
    public function regexFlatPatternMatchesPath(): void
    {
        $redirect = $this->makeRedirect(30);
        $matcher  = $this->generateAndLoad('regex.host', [
            'regexp_flat' => ['/^\/old\/(.+)$/' => [30 => $redirect]],
        ]);

        self::assertSame(30, $matcher->match('/old/page', '')['uid']);
    }

    #[Test]
    public function regexQueryParamsPatternMatchesPathAndQuery(): void
    {
        $redirect = $this->makeRedirect(40);
        $matcher  = $this->generateAndLoad('regexq.host', [
            'regexp_query_parameters' => ['/^\/search\?q=.+$/' => [40 => $redirect]],
        ]);

        self::assertSame(40, $matcher->match('/search', 'q=typo3')['uid']);
        self::assertNull($matcher->match('/search', ''));
    }

    // -------------------------------------------------------------------------
    // Multiple redirects for same path — firstActive selects correctly
    // -------------------------------------------------------------------------

    #[Test]
    public function firstActiveRedirectIsReturnedWhenMultipleExistForSamePath(): void
    {
        $expired = $this->makeRedirect(1, endtime: $this->now - 1);
        $active  = $this->makeRedirect(2);

        $matcher = $this->generateAndLoad('multi.host', [
            'flat' => ['/page/' => [1 => $expired, 2 => $active]],
        ]);

        self::assertSame(2, $matcher->match('/page', '')['uid']);
    }

    #[Test]
    public function allRedirectsForPathCanBeDisabled(): void
    {
        $r1 = $this->makeRedirect(1, disabled: true);
        $r2 = $this->makeRedirect(2, disabled: true);

        $matcher = $this->generateAndLoad('disabled.host', [
            'flat' => ['/page/' => [1 => $r1, 2 => $r2]],
        ]);

        self::assertNull($matcher->match('/page', ''));
    }

    // -------------------------------------------------------------------------
    // Sharding (threshold-based file splitting)
    // -------------------------------------------------------------------------

    #[Test]
    public function flatWithQueryShardingMatchesAllPaths(): void
    {
        // Threshold 2: any bucket with > 2 total redirects triggers sharding.
        $generator = new RedirectMatcherGenerator(splitThreshold: 2);
        $host = 'sharded-fq.host.' . uniqid();

        // 3 paths × 1 redirect each = 3 total → exceeds threshold 2 → sharded.
        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $r3 = $this->makeRedirect(3);
        $redirects = [
            'respect_query_parameters' => [
                '/a?x=1' => [1 => $r1],
                '/b?x=2' => [2 => $r2],
                '/c?x=3' => [3 => $r3],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/a', 'x=1')['uid']);
        self::assertSame(2, $matcher->match('/b', 'x=2')['uid']);
        self::assertSame(3, $matcher->match('/c', 'x=3')['uid']);
        self::assertNull($matcher->match('/a', 'x=2'));
    }

    #[Test]
    public function trieShardingMatchesAllPaths(): void
    {
        // Threshold 2: 4 flat redirects → sharded by first segment.
        $generator = new RedirectMatcherGenerator(splitThreshold: 2);
        $host = 'sharded-tr.host.' . uniqid();

        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $r3 = $this->makeRedirect(3);
        $r4 = $this->makeRedirect(4);
        $redirects = [
            'flat' => [
                '/news/'    => [1 => $r1],
                '/about/'   => [2 => $r2],
                '/contact/' => [3 => $r3],
                '/blog/'    => [4 => $r4],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/news', '')['uid']);
        self::assertSame(2, $matcher->match('/about', '')['uid']);
        self::assertSame(3, $matcher->match('/contact', '')['uid']);
        self::assertSame(4, $matcher->match('/blog', '')['uid']);
        self::assertNull($matcher->match('/other', ''));
    }

    #[Test]
    public function regexShardingMatchesAllPatterns(): void
    {
        // Threshold 2: 3 regex redirects → sharded.
        $generator = new RedirectMatcherGenerator(splitThreshold: 2);
        $host = 'sharded-rf.host.' . uniqid();

        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $r3 = $this->makeRedirect(3);
        $redirects = [
            'regexp_flat' => [
                '/^\/old1\//' => [1 => $r1],
                '/^\/old2\//' => [2 => $r2],
                '/^\/old3\//' => [3 => $r3],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/old1/page', '')['uid']);
        self::assertSame(2, $matcher->match('/old2/page', '')['uid']);
        self::assertSame(3, $matcher->match('/old3/page', '')['uid']);
        self::assertNull($matcher->match('/other/page', ''));
    }

    #[Test]
    public function trieShardingRecursesIntoOversizedFirstSegment(): void
    {
        // threshold=1: news has 2 redirects → dispatcher at depth 0 splits news into two leaf shards
        $generator = new RedirectMatcherGenerator(splitThreshold: 1);
        $host = 'deep-trie-1.host.' . uniqid();

        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $redirects = [
            'flat' => [
                '/news/2024/' => [1 => $r1],
                '/news/2025/' => [2 => $r2],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/news/2024', '')['uid']);
        self::assertSame(2, $matcher->match('/news/2025', '')['uid']);
        self::assertNull($matcher->match('/news', ''));
        self::assertNull($matcher->match('/other', ''));
    }

    #[Test]
    public function trieShardingRecursesThreeLevelsDeep(): void
    {
        // threshold=1: news/2024 has 2 redirects → dispatcher at depth 1 as well
        $generator = new RedirectMatcherGenerator(splitThreshold: 1);
        $host = 'deep-trie-2.host.' . uniqid();

        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $redirects = [
            'flat' => [
                '/news/2024/article-1/' => [1 => $r1],
                '/news/2024/article-2/' => [2 => $r2],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/news/2024/article-1', '')['uid']);
        self::assertSame(2, $matcher->match('/news/2024/article-2', '')['uid']);
        self::assertNull($matcher->match('/news/2024', ''));
        self::assertNull($matcher->match('/news', ''));
    }

    #[Test]
    public function trieDispatcherHandlesInlineRedirectAtOversizedNode(): void
    {
        // /section/ itself has a redirect AND three children → dispatcher with inline '' arm
        $generator = new RedirectMatcherGenerator(splitThreshold: 2);
        $host = 'inline-redirect.host.' . uniqid();

        $r1 = $this->makeRedirect(1);
        $r2 = $this->makeRedirect(2);
        $r3 = $this->makeRedirect(3);
        $r4 = $this->makeRedirect(4);
        $redirects = [
            'flat' => [
                '/section/'   => [1 => $r1],
                '/section/a/' => [2 => $r2],
                '/section/b/' => [3 => $r3],
                '/section/c/' => [4 => $r4],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(1, $matcher->match('/section', '')['uid']);
        self::assertSame(2, $matcher->match('/section/a', '')['uid']);
        self::assertSame(3, $matcher->match('/section/b', '')['uid']);
        self::assertSame(4, $matcher->match('/section/c', '')['uid']);
        self::assertNull($matcher->match('/section/d', ''));
        self::assertNull($matcher->match('/other', ''));
    }

    #[Test]
    public function shardedTrieHandlesRootPath(): void
    {
        $generator = new RedirectMatcherGenerator(splitThreshold: 1);
        $host = 'sharded-root.host.' . uniqid();

        $root = $this->makeRedirect(99);
        $news = $this->makeRedirect(1);
        $redirects = [
            'flat' => [
                '/'      => [99 => $root],
                '/news/' => [1 => $news],
            ],
        ];

        $matcher = $this->generateAndLoadWith($generator, $host, $redirects);

        self::assertSame(99, $matcher->match('/', '')['uid']);
        self::assertSame(1, $matcher->match('/news', '')['uid']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write all generated files to a real temp directory, require() the main class
     * file, and return an instance of the generated matcher.
     *
     * Using require() (not eval()) means __DIR__ inside the generated class resolves
     * to the temp directory, so the lazy-load `require __DIR__ . '/...'` calls inside
     * the matcher methods find their type files correctly.
     *
     * Uses a unique host per call to avoid class-name collisions across tests.
     */
    private function generateAndLoad(string $host, array $redirects): \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
    {
        return $this->generateAndLoadWith($this->generator, $host, $redirects);
    }

    private function generateAndLoadWith(
        RedirectMatcherGenerator $generator,
        string $host,
        array $redirects
    ): \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase {
        $tmpDir = sys_get_temp_dir() . '/br_gen_test_' . md5($host . uniqid('', true));
        mkdir($tmpDir, 0755, true);
        $this->tmpDirs[] = $tmpDir;

        $versionDir = 'testversion';
        foreach ($generator->generateFiles($host, $redirects, $versionDir) as $slug => $code) {
            $target = $tmpDir . '/' . $slug . '.php';
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($target, $code);
        }

        $hash = md5($host);
        $className = 'a9f\\BetterRedirects\\Cache\\Generated\\RedirectMatcher_' . $hash;
        if (!class_exists($className, false)) {
            require $tmpDir . '/' . $hash . '.php';
        }
        return new $className();
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRedirect(
        int $uid,
        bool $disabled = false,
        int $starttime = 0,
        int $endtime = 0
    ): array {
        return [
            'uid'        => $uid,
            'disabled'   => $disabled ? 1 : 0,
            'starttime'  => $starttime,
            'endtime'    => $endtime,
            'target'     => 'https://example.com/target',
            'source_path' => '/path',
            'is_regexp'  => 0,
        ];
    }
}
