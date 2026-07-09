<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

/**
 * A single node of the in-memory trie built by RedirectMatcherGenerator while
 * grouping flat-path redirects by path segment.
 *
 * Modeled as a real class (rather than a nested associative array keyed by a
 * sentinel '__redirects' string) so that the recursive node/children
 * relationship can be expressed as a genuine PHPStan type: PHPStan does not
 * support self-referential array shapes/aliases, but a class property typed
 * as `array<string, self>` is natively supported.
 *
 * @phpstan-import-type RedirectRow from \a9f\BetterRedirects\Cache\GeneratedRedirectMatcherBase
 */
final class RedirectTrieNode
{
    /** @var array<int, RedirectRow> */
    public array $redirects = [];

    /** @var array<string, RedirectTrieNode> */
    public array $children = [];
}
