# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A TYPO3 extension (`better_redirects`) that replaces TYPO3's default `RedirectService` with a multi-layer caching strategy for large redirect sets (thousands of redirects). Requires TYPO3 13.4+ with the redirects extension.

## Commands

```bash
composer install                    # Install dependencies
vendor/bin/phpunit                  # Run all unit tests
vendor/bin/phpunit Tests/Unit/Cache/RedirectMatcherGeneratorTest.php  # Run a single test file
vendor/bin/phpunit --filter testSomeMethod  # Run a single test
```

Memory limit may be needed for large test runs: `php -d memory_limit=1G vendor/bin/phpunit`

## Architecture: 3-Layer Caching

Redirect matching flows through three layers, falling back on a miss:

```
1. Per-request Result Cache (MatchResultCache)
   ↓ miss
2. PHP File Cache (PhpFileRedirectMatcherService + generated matchers)
   ↓ miss / unavailable
3. TYPO3 Default RedirectService (parent class)
```

### Layer 1 – Per-Request Result Cache (`Cache/MatchResultCache`)

Caches lookup results by `(domain, path, query)` using TYPO3's database cache backend. TTL: 1 hour for "no match", up to 24 hours for active redirects. Tagged by `tx_redirects` and `redirect_host_{sha1(host)}` for targeted invalidation.

### Layer 2 – PHP File Cache

This is the core innovation. Redirect data is compiled into optimized PHP files that benefit from OPcache.

**`Cache/RedirectMatcherGenerator`** — Generates PHP source code from redirect DB rows. Groups redirects into 4 match types and builds trie structures for flat path matching. Implements sharding when a type exceeds the configured threshold (default: 1000 redirects).

**`Cache/PhpFileRedirectCache`** — Manages generated files on disk at `var/cache/code/better_redirects/`. Uses atomic writes (write to temp → rename) and versioned subdirectories for safe concurrent cache regeneration. Prunes old versions automatically.

**`Cache/GeneratedRedirectMatcherBase`** — Abstract base class that all generated matcher classes extend. Implements the 4-stage matching priority (flat+query → flat trie → regex+query → regex flat) and checks redirect activation (disabled flag, starttime, endtime).

**`Cache/PhpFileRedirectMatcherService`** — Orchestrates Layer 2: loads the generated matcher for a domain, calls its `match()`, falls back to regenerating if the cache file is unavailable.

### Layer 3 – Integration Point

**`Service/CachingRedirectService`** — Extends TYPO3's `RedirectService`, overrides `matchRedirect()` to call the two cache layers before delegating to parent. Wired as a transparent alias via `Configuration/Services.php`.

### Generated File Layout (per source host)

```
{md5(host)}.php       — Main entry point, lazily requires type files
{md5(host)}_fq.php    — Flat-with-query redirects (+ shards _fq_0.php, _fq_1.php, ...)
{md5(host)}_tr.php    — Trie for flat redirects (+ recursive shards)
{md5(host)}_rq.php    — Regex-with-query patterns (+ shards)
{md5(host)}_rf.php    — Regex-flat patterns (+ shards)
```

Sharding strategy per type:
- **Flat-with-query**: hash key using `crc32` to select shard
- **Trie**: recursive split at any depth with bin-packing for small branches
- **Regex**: split by pattern count, merged on first access

### Cache Invalidation

`Hook/DataHandlerResultCacheFlushingHook` fires on TYPO3 DataHandler `clearCachePostProc` when redirect records change. It flushes both the result cache (by tag) and the PHP file cache (deletes generated files for the affected host).

## Key Configuration

`Configuration/Services.php` — Symfony DI wiring. Notable aliases:
- `MatchResultCacheInterface` → `MatchResultCache`
- `RedirectService` → `CachingRedirectService` (the transparent override)

The split threshold (default 1000) is bound as a scalar argument in `Services.php`.

`ext_localconf.php` — Registers the `better_redirects` TYPO3 cache and the DataHandler hook.