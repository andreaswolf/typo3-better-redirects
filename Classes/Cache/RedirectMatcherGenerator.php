<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Dumper;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

/**
 * Generates PHP source files that implement redirect matching logic compiled
 * from redirect data fetched from RedirectCacheService.
 *
 * Per host, a set of files is written to the cache directory:
 *
 *   {hash}.php          — Main class (extends GeneratedRedirectMatcherBase).
 *                         Delegates each match type to a lazy-loaded type file.
 *                         Written last so that file presence signals completion.
 *   {hash}_fq.php       — Flat-with-query (respect_query_parameters) matcher.
 *                         Returns an anonymous class with match(string $key).
 *                         When sharded: acts as a dispatcher to {hash}_fq_N.php.
 *   {hash}_fq_N.php     — Flat-with-query shard N (hash-partitioned by key).
 *   {hash}_tr.php       — Trie matcher for flat path redirects.
 *                         Returns an anonymous class with match(array $seg).
 *                         When sharded: acts as a dispatcher to segment shards.
 *   {hash}_tr_{md5}.php — Trie shard for one top-level path segment.
 *   {hash}_rq.php       — Regex-query-params patterns. Returns array or merges shards.
 *   {hash}_rq_N.php     — Regex-query-params shard N.
 *   {hash}_rf.php       — Regex-flat patterns. Returns array or merges shards.
 *   {hash}_rf_N.php     — Regex-flat shard N.
 *
 * Sharding is triggered when the total redirect count in a type bucket exceeds
 * $splitThreshold. Type files for flat-with-query and trie are then loaded
 * on demand (only the shard for the current request's key/segment is required).
 * Regex shards are all merged on first access but are still loaded lazily as a
 * group (regex matching only runs when flat/trie matching has already failed).
 *
 * @param array{
 *     flat?: array<string, array<int, array<string, mixed>>>,
 *     respect_query_parameters?: array<string, array<int, array<string, mixed>>>,
 *     regexp_flat?: array<string, array<int, array<string, mixed>>>,
 *     regexp_query_parameters?: array<string, array<int, array<string, mixed>>>,
 * } $redirects
 */
class RedirectMatcherGenerator
{
    private Dumper $dumper;

    public function __construct(private readonly int $splitThreshold = 1000)
    {
        $this->dumper = new Dumper();
    }

    /**
     * Yield [slug => phpCode] pairs for all files that must be written for $host.
     *
     * Type files are yielded before the main class so that if the process is
     * interrupted the main file ({hash}.php) is never present unless all type
     * files were written successfully.  The caller (PhpFileRedirectMatcherService)
     * must write each slug immediately to keep peak memory low.
     *
     * @param array{
     *     flat?: array<string, array<int, array<string, mixed>>>,
     *     respect_query_parameters?: array<string, array<int, array<string, mixed>>>,
     *     regexp_flat?: array<string, array<int, array<string, mixed>>>,
     *     regexp_query_parameters?: array<string, array<int, array<string, mixed>>>,
     * } $redirects
     * @return \Generator<string, string>
     */
    public function generateFiles(string $host, array $redirects, string $versionDir): \Generator
    {
        $hash = md5($host);
        // All type/shard files go into a versioned subdirectory so that the
        // cache can be rewritten atomically: type files are written first into
        // a new version dir, then the main file is replaced to point to it.
        $typePrefix = $hash . '/' . $versionDir . '/';

        $fqData = $redirects['respect_query_parameters'] ?? [];
        yield from $this->yieldFlatQueryFiles($hash, $fqData, $typePrefix);
        unset($fqData);

        $flatData = $redirects['flat'] ?? [];
        yield from $this->yieldTrieFiles($hash, $flatData, $typePrefix);
        unset($flatData);

        $rqData = $redirects['regexp_query_parameters'] ?? [];
        yield from $this->yieldRegexFiles($hash, 'rq', $rqData, $typePrefix);
        unset($rqData);

        $rfData = $redirects['regexp_flat'] ?? [];
        yield from $this->yieldRegexFiles($hash, 'rf', $rfData, $typePrefix);
        unset($rfData);

        // Main class last — its presence signals that all type files exist.
        yield $hash => $this->generateMainClass($host, $hash, $versionDir);
    }

    // -------------------------------------------------------------------------
    // Flat-with-query (respect_query_parameters)
    // -------------------------------------------------------------------------

    /** @return \Generator<string, string> */
    private function yieldFlatQueryFiles(string $hash, array $withQuery, string $typePrefix): \Generator
    {
        $count = $this->countBucket($withQuery);

        if ($count <= $this->splitThreshold) {
            yield $typePrefix . $hash . '_fq' => $this->generateFlatQueryFile($withQuery);
            return;
        }

        $numShards = (int)ceil($count / $this->splitThreshold);
        $shardData = array_fill(0, $numShards, []);
        foreach ($withQuery as $key => $redirectsByUid) {
            $idx = abs(crc32((string)$key)) % $numShards;
            $shardData[$idx][(string)$key] = $redirectsByUid;
        }
        foreach ($shardData as $i => $shard) {
            yield $typePrefix . $hash . '_fq_' . $i => $this->generateFlatQueryFile($shard);
        }
        yield $typePrefix . $hash . '_fq' => $this->generateFlatQueryDispatcher($hash, $numShards);
    }

    /**
     * Generate an anonymous-class file that matches a single exact path+query key.
     * Used both for the non-sharded case and for individual shard files.
     */
    private function generateFlatQueryFile(array $withQuery): string
    {
        if ($withQuery === []) {
            $body = "\t\treturn null;";
        } else {
            $arms = [];
            foreach ($withQuery as $path => $redirectsByUid) {
                $arms[] = "\t\t\t" . $this->dumper->dump((string)$path)
                    . ' => GeneratedRedirectMatcherBase::firstActive('
                    . $this->dumper->dump(array_values($redirectsByUid)) . ')';
            }
            $arms[] = "\t\t\tdefault => null";
            $body = "\t\treturn match(\$key) {\n" . implode(",\n", $arms) . "\n\t\t};";
        }

        return $this->anonClassFileHeader(true)
            . "return new class {\n"
            . "\tpublic function match(string \$key): ?array\n"
            . "\t{\n"
            . $body . "\n"
            . "\t}\n"
            . "};\n";
    }

    /**
     * Generate a dispatcher that routes to one of N flat-with-query shard files
     * based on crc32($key) % N.  Only the relevant shard is required per request.
     */
    private function generateFlatQueryDispatcher(string $hash, int $numShards): string
    {
        return $this->anonClassFileHeader(false)
            . "return new class {\n"
            . "\tprivate array \$shards = [];\n"
            . "\n"
            . "\tpublic function match(string \$key): ?array\n"
            . "\t{\n"
            . "\t\t\$idx = abs(crc32(\$key)) % {$numShards};\n"
            . "\t\t\$this->shards[\$idx] ??= require __DIR__ . '/{$hash}_fq_' . \$idx . '.php';\n"
            . "\t\treturn \$this->shards[\$idx]->match(\$key);\n"
            . "\t}\n"
            . "};\n";
    }

    // -------------------------------------------------------------------------
    // Trie (flat path redirects)
    // -------------------------------------------------------------------------

    /** @return \Generator<string, string> */
    private function yieldTrieFiles(string $hash, array $flat, string $typePrefix): \Generator
    {
        $trie = [];
        foreach ($flat as $storedPath => $redirectsByUid) {
            $segments = explode('/', trim((string)$storedPath, '/'));
            $this->insertIntoTrie($trie, $segments, array_values($redirectsByUid));
        }

        $rootSlug = $hash . '_tr';
        if ($this->countTrieNode($trie) <= $this->splitThreshold) {
            yield $typePrefix . $rootSlug => $this->generateTrieNodeFile($trie, '', 0);
            return;
        }

        yield from $this->yieldTrieShardFiles($hash, $trie, '', 0, $typePrefix, $rootSlug);
    }

    /** @return \Generator<string, string> */
    private function yieldTrieShardFiles(
        string $hash,
        array $trieNode,
        string $pathPrefix,
        int $depth,
        string $typePrefix,
        string $slug
    ): \Generator {
        if ($this->countTrieNode($trieNode) <= $this->splitThreshold) {
            yield $typePrefix . $slug => $this->generateTrieNodeFile($trieNode, $pathPrefix, $depth);
            return;
        }

        $segToBasenameSlug = [];
        $smallChildren = [];

        foreach ($trieNode as $segment => $childNode) {
            if ($segment === '__redirects') {
                continue;
            }
            if ($this->countTrieNode($childNode) > $this->splitThreshold) {
                // Large child: recurse so it gets its own dispatcher + shards.
                $childPrefix = $pathPrefix === '' ? (string)$segment : $pathPrefix . '/' . (string)$segment;
                $childSlug = $hash . '_tr_' . md5($childPrefix);
                $segToBasenameSlug[(string)$segment] = $childSlug;
                yield from $this->yieldTrieShardFiles(
                    $hash, $childNode, $childPrefix, $depth + 1, $typePrefix, $childSlug
                );
            } else {
                // Small child: collect for bin-packing to avoid one-redirect-per-file.
                $smallChildren[(string)$segment] = $childNode;
            }
        }

        // Bin-pack small children into groups of ~splitThreshold redirects each.
        foreach ($this->packChildrenIntoBins($smallChildren) as $i => $binChildren) {
            $binSlug = $slug . '_b' . $i;
            foreach (array_keys($binChildren) as $segment) {
                $segToBasenameSlug[$segment] = $binSlug;
            }
            yield $typePrefix . $binSlug => $this->generateTrieNodeFile($binChildren, $pathPrefix, $depth);
        }

        yield $typePrefix . $slug => $this->generateTrieDispatcherAtDepth($trieNode, $depth, $segToBasenameSlug);
    }

    /**
     * Greedily pack small trie children into bins of at most $splitThreshold
     * redirects each.  Returns a list of bins, each bin being [segment => childNode].
     *
     * @return array<int, array<string, array>>
     */
    private function packChildrenIntoBins(array $children): array
    {
        if ($children === []) {
            return [];
        }

        $bins = [];
        $currentBin = [];
        $currentCount = 0;

        foreach ($children as $segment => $childNode) {
            $childCount = $this->countTrieNode($childNode);
            if ($currentBin !== [] && $currentCount + $childCount > $this->splitThreshold) {
                $bins[] = $currentBin;
                $currentBin = [];
                $currentCount = 0;
            }
            $currentBin[(string)$segment] = $childNode;
            $currentCount += $childCount;
        }

        if ($currentBin !== []) {
            $bins[] = $currentBin;
        }

        return $bins;
    }

    /**
     * Generate a leaf shard file for any trie node at any depth.
     * Used for both the non-sharded root (depth=0) and recursively split shards.
     */
    private function generateTrieNodeFile(array $trieNode, string $pathPrefix, int $depth): string
    {
        $class = new ClassType(null);
        $this->addTrieMethodToClass($class, 'match', 'public', $pathPrefix, $trieNode, $depth);
        return $this->printTrieFile($class);
    }

    /**
     * Generate a dispatcher file that routes $seg[$depth] to child shard files.
     * Any __redirects at this node are handled inline via a '' match arm so that
     * exact-depth matches do not require a separate shard file.
     * Only the shard for the matched segment is loaded per request.
     */
    private function generateTrieDispatcherAtDepth(array $trieNode, int $depth, array $segToBasenameSlug): string
    {
        $arms = [];

        if (isset($trieNode['__redirects'])) {
            $arms[] = "\t\t\t'' => GeneratedRedirectMatcherBase::firstActive("
                . $this->dumper->dump($trieNode['__redirects']) . ')';
        }

        // Group segments by their target slug to emit compact multi-value match arms.
        $slugToSegments = [];
        foreach ($segToBasenameSlug as $seg => $slug) {
            $slugToSegments[$slug][] = (string)$seg;
        }
        foreach ($slugToSegments as $slug => $segments) {
            $segParts = array_map(fn(string $s): string => $this->dumper->dump($s), $segments);
            $arms[] = "\t\t\t" . implode(', ', $segParts)
                . " => \$this->loadShard(" . $this->dumper->dump($slug) . ", \$seg)";
        }
        $arms[] = "\t\t\tdefault => null";

        $matchBody = "return match(\$seg[{$depth}] ?? '') {\n" . implode(",\n", $arms) . "\n\t\t};";

        return $this->anonClassFileHeader(isset($trieNode['__redirects']))
            . "return new class {\n"
            . "\tprivate array \$shards = [];\n"
            . "\n"
            . "\tpublic function match(array \$seg): ?array\n"
            . "\t{\n"
            . "\t\t" . $matchBody . "\n"
            . "\t}\n"
            . "\n"
            . "\tprivate function loadShard(string \$slug, array \$seg): ?array\n"
            . "\t{\n"
            . "\t\t\$this->shards[\$slug] ??= require __DIR__ . '/' . \$slug . '.php';\n"
            . "\t\treturn \$this->shards[\$slug]->match(\$seg);\n"
            . "\t}\n"
            . "};\n";
    }

    /**
     * Wrap a Nette anonymous ClassType in a PHP file that returns `new class { ... }`.
     */
    private function printTrieFile(ClassType $class): string
    {
        $classBody = (new PsrPrinter())->printClass($class);
        // printClass on an anonymous class produces "{\n    methods\n}" (no "class" keyword).
        return $this->anonClassFileHeader(true) . "return new class " . $classBody . ";\n";
    }

    /**
     * Add a trie-matching method (and its recursive child methods) to a ClassType.
     *
     * @param ClassType $class      Target class to add methods to.
     * @param string    $methodName Name of the method to add.
     * @param string    $visibility 'public' for root entry point, 'private' for helpers.
     * @param string    $pathPrefix Slash-joined path segments consumed above this node.
     * @param array     $trieNode   Current trie node (may have '__redirects' and child keys).
     * @param int       $depth      Which $seg[N] index to inspect.
     */
    private function addTrieMethodToClass(
        ClassType $class,
        string $methodName,
        string $visibility,
        string $pathPrefix,
        array $trieNode,
        int $depth
    ): void {
        $method = $class->addMethod($methodName);
        $method->setVisibility($visibility);
        $method->addParameter('seg')->setType('array');
        $method->setReturnType('?array');
        if (str_starts_with($methodName, 'matchSeg_')) {
            $method->addComment($pathPrefix . '/…');
        }

        $arms = [];

        if (isset($trieNode['__redirects'])) {
            $arms[] = sprintf(
                "\t'' => GeneratedRedirectMatcherBase::firstActive(%s)",
                $this->dumper->dump($trieNode['__redirects'])
            );
        }

        foreach ($trieNode as $segment => $childNode) {
            if ($segment === '__redirects') {
                continue;
            }

            $childPrefix = $pathPrefix === '' ? (string)$segment : $pathPrefix . '/' . $segment;
            $childMethodName = 'matchSeg_' . md5($childPrefix);

            $this->addTrieMethodToClass($class, $childMethodName, 'private', $childPrefix, $childNode, $depth + 1);
            $arms[] = sprintf(
                "\t%s => \$this->%s(\$seg)",
                $this->dumper->dump((string)$segment),
                $childMethodName
            );
        }

        $arms[] = "\tdefault => null";

        $method->setBody(
            sprintf("return match(\$seg[%d] ?? '') {\n", $depth) .
            implode(",\n", $arms) . "\n};"
        );
    }

    private function insertIntoTrie(array &$trie, array $segments, array $redirects): void
    {
        $node = &$trie;
        foreach ($segments as $segment) {
            if (!array_key_exists($segment, $node)) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node['__redirects'] = array_merge($node['__redirects'] ?? [], $redirects);
    }

    // -------------------------------------------------------------------------
    // Regex (regexp_flat / regexp_query_parameters)
    // -------------------------------------------------------------------------

    /** @return \Generator<string, string> */
    private function yieldRegexFiles(string $hash, string $type, array $patterns, string $typePrefix): \Generator
    {
        $count = $this->countBucket($patterns);

        if ($count <= $this->splitThreshold) {
            yield $typePrefix . $hash . '_' . $type => $this->generateRegexFile($patterns);
            return;
        }

        $numShards = (int)ceil($count / $this->splitThreshold);
        $chunks = array_chunk($patterns, max(1, (int)ceil(count($patterns) / $numShards)), true);
        foreach ($chunks as $i => $chunk) {
            yield $typePrefix . $hash . '_' . $type . '_' . $i => $this->generateRegexFile($chunk);
        }

        $actualShards = count($chunks);
        yield $typePrefix . $hash . '_' . $type => $this->generateRegexLoader($hash, $type, $actualShards);
    }

    /**
     * Generate a file that returns a regex-pattern array.
     * Used for both non-sharded and individual shard files.
     */
    private function generateRegexFile(array $patterns): string
    {
        $normalized = [];
        foreach ($patterns as $pattern => $redirectsByUid) {
            $normalized[(string)$pattern] = array_values($redirectsByUid);
        }
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $this->dumper->dump($normalized) . ";\n";
    }

    /**
     * Generate a file that merges all regex shard files into a single array.
     * All shards are loaded on first access (regex is only reached after flat/trie fail).
     */
    private function generateRegexLoader(string $hash, string $type, int $numShards): string
    {
        $args = [];
        for ($i = 0; $i < $numShards; $i++) {
            $args[] = "require __DIR__ . '/{$hash}_{$type}_{$i}.php'";
        }
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn array_merge(" . implode(', ', $args) . ");\n";
    }

    // -------------------------------------------------------------------------
    // Main class
    // -------------------------------------------------------------------------

    /**
     * Generate the main class file that extends GeneratedRedirectMatcherBase and
     * delegates each abstract method to a lazily-loaded type handler file.
     */
    private function generateMainClass(string $host, string $hash, string $versionDir): string
    {
        $file = new PhpFile();
        $file->setStrictTypes(true);
        $file->addComment(
            "@generated\n" .
            '@date ' . date('Y-m-d H:i:s') . "\n" .
            '@host ' . $host . "\n" .
            '@version ' . $versionDir
        );

        $namespace = $file->addNamespace('a9f\\BetterRedirects\\Cache\\Generated');
        $namespace->addUse(GeneratedRedirectMatcherBase::class);

        $class = $namespace->addClass('RedirectMatcher_' . $hash);
        $class->setExtends(GeneratedRedirectMatcherBase::class);

        // Type files live in the versioned subdirectory.  Using the full relative
        // path from __DIR__ (which resolves to the flat cache dir next to this file)
        // means switching to a new version dir is just an atomic rename of this file.
        $typePath = "{$hash}/{$versionDir}/";

        $m = $class->addMethod('matchFlatWithQuery');
        $m->setVisibility('protected');
        $m->addParameter('key')->setType('string');
        $m->setReturnType('?array');
        $m->setBody(
            "static \$handler = null;\n" .
            "\$handler ??= require __DIR__ . '/{$typePath}{$hash}_fq.php';\n" .
            "return \$handler->match(\$key);"
        );

        $m = $class->addMethod('matchTrieRoot');
        $m->setVisibility('protected');
        $m->addParameter('seg')->setType('array');
        $m->setReturnType('?array');
        $m->setBody(
            "static \$handler = null;\n" .
            "\$handler ??= require __DIR__ . '/{$typePath}{$hash}_tr.php';\n" .
            "return \$handler->match(\$seg);"
        );

        $m = $class->addMethod('matchRegexQueryParams');
        $m->setVisibility('protected');
        $m->setReturnType('array');
        $m->setBody(
            "static \$patterns = null;\n" .
            "\$patterns ??= require __DIR__ . '/{$typePath}{$hash}_rq.php';\n" .
            "return \$patterns;"
        );

        $m = $class->addMethod('matchRegexFlat');
        $m->setVisibility('protected');
        $m->setReturnType('array');
        $m->setBody(
            "static \$patterns = null;\n" .
            "\$patterns ??= require __DIR__ . '/{$typePath}{$hash}_rf.php';\n" .
            "return \$patterns;"
        );

        return (new PsrPrinter())->printFile($file);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the standard PHP file header for anonymous-class type files.
     * When $useBase is true, adds a `use` statement for GeneratedRedirectMatcherBase.
     */
    private function anonClassFileHeader(bool $useBase): string
    {
        $header = "<?php\n\ndeclare(strict_types=1);\n";
        if ($useBase) {
            $header .= "\nuse " . GeneratedRedirectMatcherBase::class . ";\n";
        }
        return $header . "\n";
    }

    /**
     * Count the total number of redirect entries across all paths in a type bucket.
     */
    private function countBucket(array $bucket): int
    {
        $count = 0;
        foreach ($bucket as $redirectsByUid) {
            $count += count($redirectsByUid);
        }
        return $count;
    }

    /**
     * Count the total number of redirect entries in a trie node and all its descendants.
     */
    private function countTrieNode(array $trieNode): int
    {
        $count = count($trieNode['__redirects'] ?? []);
        foreach ($trieNode as $key => $child) {
            if ($key !== '__redirects') {
                $count += $this->countTrieNode($child);
            }
        }
        return $count;
    }
}
