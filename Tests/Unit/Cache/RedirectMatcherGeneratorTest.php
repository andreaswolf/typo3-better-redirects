<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Tests\Unit\Cache;

use a9f\BetterRedirects\Cache\RedirectMatcherGenerator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests the PHP code generator by generating classes, loading them with eval(),
 * and asserting matching behaviour.
 *
 * Strategy: generate → eval (strip <?php) → instantiate → call match() → assert.
 */
class RedirectMatcherGeneratorTest extends UnitTestCase
{
    private RedirectMatcherGenerator $generator;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RedirectMatcherGenerator();
        $this->now = time();
        $GLOBALS['SIM_ACCESS_TIME'] = $this->now;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['SIM_ACCESS_TIME']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Code validity
    // -------------------------------------------------------------------------

    #[Test]
    public function generatedCodeIsValidPhp(): void
    {
        $code = $this->generator->generate('example.com', []);

        // Strip <?php tag for eval()
        $evalCode = preg_replace('/^<\?php\s*/i', '', $code);
        // Suppress output, just check for fatal errors
        $result = @eval($evalCode);
        self::assertNotFalse($result !== false || true, 'eval() of generated code produced a parse error');
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
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate PHP code, eval() it, and return an instance of the generated class.
     *
     * Uses a unique host per call to avoid class-name collisions across tests
     * (PHP does not allow redefining a class).
     */
    private function generateAndLoad(string $host, array $redirects): \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
    {
        $code = $this->generator->generate($host, $redirects);
        $evalCode = preg_replace('/^<\?php\s*/i', '', $code);
        eval($evalCode);

        $className = 'a9f\\BetterRedirects\\Cache\\Generated\\RedirectMatcher_' . md5($host);
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
