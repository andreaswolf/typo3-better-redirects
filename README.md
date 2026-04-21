# Better Redirects

A TYPO3 extension that replaces the built-in redirect matching with a multi-layer caching strategy optimized for large redirect sets (thousands of entries). Designed for TYPO3 13.4+ with the `redirects` system extension.

## The Problem

TYPO3's default `RedirectService` queries and evaluates all redirects on every request. This works fine for small sets but becomes a bottleneck at scale.

## How It Works

Redirect matching flows through three layers, each falling back to the next on a miss:

1. **Per-request result cache** — database-backed cache keyed by `(host, path, query)`, TTL up to 24 hours. Avoids repeated matching for frequently-hit URLs.
2. **PHP file cache** — redirect data is compiled into PHP files in `var/cache/code/better_redirects/` that are loaded and optimized by OPcache. Redirects are grouped by match type and organized into trie or hash structures for fast lookup. Files are sharded automatically when a type exceeds the threshold (default: 1,000 entries per type).
3. **TYPO3's default `RedirectService`** — fallback if the PHP file cache is unavailable or cold.

Cache invalidation is automatic: when a redirect record is saved or deleted via the TYPO3 backend, the result cache entries and the PHP file cache for that host are flushed immediately.

## Important Notes

> **The extension is active as soon as it is installed.** No configuration is required.

> **TYPO3's built-in redirect cache (`RedirectCacheService`) is superseded** by the PHP file cache and effectively becomes irrelevant. The built-in cache is still rebuilt on redirect changes (because the extension subclasses `RedirectCacheService`), but redirect matching never reaches it under normal operation.

> **The extension aliases `RedirectService` and `RedirectCacheService`** to its own subclasses via Symfony DI. Any code that injects these classes by their concrete TYPO3 type will transparently receive the caching variants.

## Installation

```bash
composer require a9f/typo3-better-redirects
```

Activate the extension in the TYPO3 Extension Manager or via:

```bash
bin/typo3 extension:activate better_redirects
```

No additional configuration is required. The PHP file cache is populated automatically on the first request after installation (or after a cache flush).

## Configuration

One optional setting is available via `$GLOBALS['TYPO3_CONF_VARS']`:

| Key | Default | Description |
|-----|---------|-------------|
| `EXTENSIONS/better_redirects/splitThreshold` | `1000` | Number of redirects per match-type bucket above which the generated PHP file is split into shards |

Set it in `config/system/additional.php` (or equivalent):

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['better_redirects']['splitThreshold'] = 500;
```

## Requirements

- TYPO3 13.4
- `typo3/cms-redirects` system extension
- PHP 8.2+
- OPcache recommended (the PHP file cache provides no benefit without it)
