<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Tests\Unit\Cache;

use a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests the matching orchestration logic in GeneratedRedirectMatcherBase via
 * a hand-written concrete subclass.
 *
 * @phpstan-import-type RedirectRow from \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
 */
class GeneratedRedirectMatcherBaseTest extends UnitTestCase
{
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $GLOBALS['SIM_ACCESS_TIME'] = $this->now;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['SIM_ACCESS_TIME']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // firstActive() behaviour
    // -------------------------------------------------------------------------

    #[Test]
    public function firstActiveReturnsNullForEmptyList(): void
    {
        $matcher = $this->buildMatcher();
        self::assertNull($matcher->match('/no-match', ''));
    }

    #[Test]
    public function firstActiveSkipsDisabledRedirects(): void
    {
        $disabled = $this->makeRedirect(1, disabled: true);
        $enabled  = $this->makeRedirect(2, disabled: false);

        $matcher = $this->buildMatcher(flat: ['/page/' => [$disabled, $enabled]]);
        $result = $matcher->match('/page', '');

        self::assertNotNull($result);
        self::assertSame(2, $result['uid']);
    }

    #[Test]
    public function firstActiveSkipsRecordsBeforeStarttime(): void
    {
        $future    = $this->makeRedirect(1, starttime: $this->now + 3600);
        $immediate = $this->makeRedirect(2, starttime: 0);

        $matcher = $this->buildMatcher(flat: ['/page/' => [$future, $immediate]]);
        $result = $matcher->match('/page', '');
        self::assertNotNull($result);
        self::assertSame(2, $result['uid']);
    }

    #[Test]
    public function firstActiveSkipsRecordsPastEndtime(): void
    {
        $expired = $this->makeRedirect(1, endtime: $this->now - 1);
        $active  = $this->makeRedirect(2, endtime: 0);

        $matcher = $this->buildMatcher(flat: ['/page/' => [$expired, $active]]);
        $result = $matcher->match('/page', '');
        self::assertNotNull($result);
        self::assertSame(2, $result['uid']);
    }

    #[Test]
    public function firstActiveReturnsActiveRedirectWithinTimeWindow(): void
    {
        $redirect = $this->makeRedirect(42, starttime: $this->now - 60, endtime: $this->now + 60);
        $matcher  = $this->buildMatcher(flat: ['/timed/' => [$redirect]]);
        $result = $matcher->match('/timed', '');
        self::assertNotNull($result);
        self::assertSame(42, $result['uid']);
    }

    // -------------------------------------------------------------------------
    // Path normalisation
    // -------------------------------------------------------------------------

    #[Test]
    public function rawurldecodedPathMatchesFlatRedirect(): void
    {
        $redirect = $this->makeRedirect(5);
        $matcher  = $this->buildMatcher(flat: ['/über-uns/' => [$redirect]]);
        // %C3%BC = ü
        $result = $matcher->match('/%C3%BCber-uns', '');
        self::assertNotNull($result);
        self::assertSame(5, $result['uid']);
    }

    #[Test]
    public function trailingSlashNormalisationMatchesBothForms(): void
    {
        $redirect = $this->makeRedirect(7);
        $matcher  = $this->buildMatcher(flat: ['/news/' => [$redirect]]);

        $resultWithoutSlash = $matcher->match('/news', '');
        self::assertNotNull($resultWithoutSlash);
        self::assertSame(7, $resultWithoutSlash['uid']);

        $resultWithSlash = $matcher->match('/news/', '');
        self::assertNotNull($resultWithSlash);
        self::assertSame(7, $resultWithSlash['uid']);
    }

    // -------------------------------------------------------------------------
    // Matching priority
    // -------------------------------------------------------------------------

    #[Test]
    public function flatWithQueryWinsOverFlatPath(): void
    {
        $withQueryRedirect = $this->makeRedirect(10);
        $flatRedirect      = $this->makeRedirect(11);

        $matcher = $this->buildMatcher(
            flat: ['/page/' => [$flatRedirect]],
            withQuery: ['/page?ref=1' => [$withQueryRedirect]]
        );

        $result = $matcher->match('/page', 'ref=1');
        self::assertNotNull($result);
        self::assertSame(10, $result['uid']);
    }

    #[Test]
    public function flatPathWinsOverRegex(): void
    {
        $flatRedirect  = $this->makeRedirect(20);
        $regexRedirect = $this->makeRedirect(21);

        $matcher = $this->buildMatcher(
            flat: ['/article/' => [$flatRedirect]],
            regexFlat: ['/^\/article$/' => [$regexRedirect]]
        );

        $result = $matcher->match('/article', '');
        self::assertNotNull($result);
        self::assertSame(20, $result['uid']);
    }

    #[Test]
    public function regexQueryParamsWinsOverRegexFlat(): void
    {
        $withQRedirect = $this->makeRedirect(30);
        $flatRedirect  = $this->makeRedirect(31);

        $matcher = $this->buildMatcher(
            regexQueryParams: ['/^\/search\?q=.*$/' => [$withQRedirect]],
            regexFlat: ['/^\/search/' => [$flatRedirect]]
        );

        $result = $matcher->match('/search', 'q=foo');
        self::assertNotNull($result);
        self::assertSame(30, $result['uid']);
    }

    // -------------------------------------------------------------------------
    // Flat-with-query matching
    // -------------------------------------------------------------------------

    #[Test]
    public function matchFlatWithQueryMatchesPathWithSlashBeforeQueryMark(): void
    {
        $redirect = $this->makeRedirect(40);
        // TYPO3 can store paths as /page/?query
        $matcher  = $this->buildMatcher(withQuery: ['/page/?ref=1' => [$redirect]]);

        $result = $matcher->match('/page', 'ref=1');
        self::assertNotNull($result);
        self::assertSame(40, $result['uid']);
    }

    // -------------------------------------------------------------------------
    // Regex second pass (regexp_flat path-only when query is present)
    // -------------------------------------------------------------------------

    #[Test]
    public function regexFlatMatchesOnPathOnlyEvenWhenQueryPresent(): void
    {
        $redirect = $this->makeRedirect(50);
        // Pattern matches only the path, not a path+query string
        $matcher  = $this->buildMatcher(regexFlat: ['/^\/old\/.*$/' => [$redirect]]);

        // The path+query string "/old/page?foo=bar" should NOT match "^\/old\/.*$" because
        // of the query, but the second pass tests against path only and SHOULD match.
        $result = $matcher->match('/old/page', 'foo=bar');
        self::assertNotNull($result);
        self::assertSame(50, $result['uid']);
    }

    #[Test]
    public function regexQueryParamsDoesNotHaveSecondPass(): void
    {
        // regexp_query_parameters is only tested against path+query, never path-only in a second pass.
        // A pattern that requires a query string should not match a request with no matching query.
        $redirect = $this->makeRedirect(60);
        $matcher  = $this->buildMatcher(
            regexQueryParams: ['/^\/search\?q=specific$/' => [$redirect]]
        );

        self::assertNull($matcher->match('/search', 'q=other'));
        self::assertNull($matcher->match('/search', ''));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, list<RedirectRow>> $flat         path → [redirect,...]
     * @param array<string, list<RedirectRow>> $withQuery    path?query → [redirect,...]
     * @param array<string, list<RedirectRow>> $regexFlat    pattern → [redirect,...]
     * @param array<string, list<RedirectRow>> $regexQueryParams pattern → [redirect,...]
     */
    private function buildMatcher(
        array $flat = [],
        array $withQuery = [],
        array $regexFlat = [],
        array $regexQueryParams = []
    ): GeneratedRedirectMatcherBase {
        return new TestRedirectMatcher($flat, $withQuery, $regexFlat, $regexQueryParams);
    }

    /**
     * @return RedirectRow
     */
    private function makeRedirect(
        int $uid,
        bool $disabled = false,
        int $starttime = 0,
        int $endtime = 0
    ): array {
        return [
            'uid'                      => $uid,
            'pid'                      => 0,
            'source_host'              => '*',
            'source_path'              => '/',
            'target'                   => 'https://example.com/target',
            'target_statuscode'        => 307,
            'force_https'              => 0,
            'keep_query_parameters'    => 0,
            'respect_query_parameters' => 0,
            'is_regexp'                => 0,
            'disabled'                 => $disabled ? 1 : 0,
            'starttime'                => $starttime,
            'endtime'                  => $endtime,
            'hitcount'                 => 0,
        ];
    }
}

/**
 * Hand-written concrete matcher used as a test double for exercising the
 * matching orchestration logic in GeneratedRedirectMatcherBase.
 *
 * @phpstan-import-type RedirectRow from GeneratedRedirectMatcherBase
 */
final class TestRedirectMatcher extends GeneratedRedirectMatcherBase
{
    /**
     * @param array<string, list<RedirectRow>> $flatPaths
     * @param array<string, list<RedirectRow>> $withQueryPaths
     * @param array<string, list<RedirectRow>> $regexFlatPatterns
     * @param array<string, list<RedirectRow>> $regexQueryParamsPatterns
     */
    public function __construct(
        private readonly array $flatPaths,
        private readonly array $withQueryPaths,
        private readonly array $regexFlatPatterns,
        private readonly array $regexQueryParamsPatterns,
    ) {}

    protected function matchFlatWithQuery(string $key): ?array
    {
        if (isset($this->withQueryPaths[$key])) {
            return $this->firstActive($this->withQueryPaths[$key]);
        }
        return null;
    }

    protected function matchTrieRoot(array $seg): ?array
    {
        // Simple linear scan for the test double — correctness, not performance.
        $path = '/' . implode('/', $seg) . '/';
        // Normalise to match stored format: rtrim+slash
        $normalised = rtrim($path, '/') . '/';
        if (isset($this->flatPaths[$normalised])) {
            return $this->firstActive($this->flatPaths[$normalised]);
        }
        return null;
    }

    protected function matchRegexQueryParams(): array
    {
        return $this->regexQueryParamsPatterns;
    }

    protected function matchRegexFlat(): array
    {
        return $this->regexFlatPatterns;
    }
}
