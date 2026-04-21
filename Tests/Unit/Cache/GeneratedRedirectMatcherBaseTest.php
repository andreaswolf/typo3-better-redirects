<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Tests\Unit\Cache;

use a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Tests the matching orchestration logic in GeneratedRedirectMatcherBase via
 * a hand-written concrete subclass.
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

        self::assertSame(2, $result['uid']);
    }

    #[Test]
    public function firstActiveSkipsRecordsBeforeStarttime(): void
    {
        $future    = $this->makeRedirect(1, starttime: $this->now + 3600);
        $immediate = $this->makeRedirect(2, starttime: 0);

        $matcher = $this->buildMatcher(flat: ['/page/' => [$future, $immediate]]);
        self::assertSame(2, $matcher->match('/page', '')['uid']);
    }

    #[Test]
    public function firstActiveSkipsRecordsPastEndtime(): void
    {
        $expired = $this->makeRedirect(1, endtime: $this->now - 1);
        $active  = $this->makeRedirect(2, endtime: 0);

        $matcher = $this->buildMatcher(flat: ['/page/' => [$expired, $active]]);
        self::assertSame(2, $matcher->match('/page', '')['uid']);
    }

    #[Test]
    public function firstActiveReturnsActiveRedirectWithinTimeWindow(): void
    {
        $redirect = $this->makeRedirect(42, starttime: $this->now - 60, endtime: $this->now + 60);
        $matcher  = $this->buildMatcher(flat: ['/timed/' => [$redirect]]);
        self::assertSame(42, $matcher->match('/timed', '')['uid']);
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
        self::assertSame(5, $matcher->match('/%C3%BCber-uns', '')['uid']);
    }

    #[Test]
    public function trailingSlashNormalisationMatchesBothForms(): void
    {
        $redirect = $this->makeRedirect(7);
        $matcher  = $this->buildMatcher(flat: ['/news/' => [$redirect]]);

        self::assertSame(7, $matcher->match('/news', '')['uid']);
        self::assertSame(7, $matcher->match('/news/', '')['uid']);
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

        self::assertSame(10, $matcher->match('/page', 'ref=1')['uid']);
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

        self::assertSame(20, $matcher->match('/article', '')['uid']);
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

        self::assertSame(30, $matcher->match('/search', 'q=foo')['uid']);
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

        self::assertSame(40, $matcher->match('/page', 'ref=1')['uid']);
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
        self::assertSame(50, $matcher->match('/old/page', 'foo=bar')['uid']);
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
     * @param array<string, list<array<string, mixed>>> $flat         path → [redirect,...]
     * @param array<string, list<array<string, mixed>>> $withQuery    path?query → [redirect,...]
     * @param array<string, list<array<string, mixed>>> $regexFlat    pattern → [redirect,...]
     * @param array<string, list<array<string, mixed>>> $regexQueryParams pattern → [redirect,...]
     */
    private function buildMatcher(
        array $flat = [],
        array $withQuery = [],
        array $regexFlat = [],
        array $regexQueryParams = []
    ): GeneratedRedirectMatcherBase {
        return new class($flat, $withQuery, $regexFlat, $regexQueryParams) extends GeneratedRedirectMatcherBase {
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
        };
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
        ];
    }
}
