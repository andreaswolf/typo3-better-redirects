<?php

declare(strict_types=1);

namespace a9f\BetterRedirects\Cache;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Dumper;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

/**
 * Generates a PHP class that implements redirect matching logic compiled from
 * redirect data fetched from RedirectCacheService.
 *
 * The generated class extends GeneratedRedirectMatcherBase and implements:
 *  - matchFlatWithQuery: single match expression over all respect_query_parameters paths
 *  - matchTrieRoot + matchSeg_*: path-segment trie for flat redirects (one method per trie node)
 *  - matchRegexQueryParams / matchRegexFlat: literal arrays returned for runtime regex iteration
 *
 * Trie method naming: matchSeg_{md5($pathPrefix)} where $pathPrefix is the
 * slash-joined segments consumed above this node. Collision-free for any characters.
 */
class RedirectMatcherGenerator
{
    /**
     * Generate a PHP source file string for the given host and redirect data.
     *
     * @param array{
     *     flat?: array<string, array<int, array<string, mixed>>>,
     *     respect_query_parameters?: array<string, array<int, array<string, mixed>>>,
     *     regexp_flat?: array<string, array<int, array<string, mixed>>>,
     *     regexp_query_parameters?: array<string, array<int, array<string, mixed>>>,
     * } $redirects
     */
    public function generate(string $host, array $redirects): string
    {
        $file = new PhpFile();
        $file->setStrictTypes(true);
        $file->addComment(
            "@generated\n" .
            '@date ' . date('Y-m-d H:i:s') . "\n" .
            '@host ' . $host . "\n" .
            '@redirects ' . $this->countRedirects($redirects)
        );

        $namespace = $file->addNamespace('a9f\\BetterRedirects\\Cache\\Generated');
        $namespace->addUse(GeneratedRedirectMatcherBase::class);

        $class = $namespace->addClass('RedirectMatcher_' . md5($host));
        $class->setExtends(GeneratedRedirectMatcherBase::class);

        $this->addMatchFlatWithQuery($class, $redirects['respect_query_parameters'] ?? []);
        $this->addTrieMethods($class, $redirects['flat'] ?? []);
        $this->addMatchRegexMethod($class, 'matchRegexQueryParams', $redirects['regexp_query_parameters'] ?? []);
        $this->addMatchRegexMethod($class, 'matchRegexFlat', $redirects['regexp_flat'] ?? []);

        return (new PsrPrinter())->printFile($file);
    }

    private function countRedirects(array $redirects): int
    {
        $count = 0;
        foreach ($redirects as $bucket) {
            foreach ((array)$bucket as $entries) {
                $count += count($entries);
            }
        }
        return $count;
    }

    private function addMatchFlatWithQuery(ClassType $class, array $withQuery): void
    {
        $method = $class->addMethod('matchFlatWithQuery');
        $method->setVisibility('protected');
        $method->addParameter('key')->setType('string');
        $method->setReturnType('?array');

        if ($withQuery === []) {
            $method->setBody('return null;');
            return;
        }

        $dumper = new Dumper();
        $arms = [];
        foreach ($withQuery as $path => $redirectsByUid) {
            $arms[] = sprintf(
                "\t%s => \$this->firstActive(%s)",
                $dumper->dump((string)$path),
                $dumper->dump(array_values($redirectsByUid))
            );
        }
        $arms[] = "\tdefault => null";

        $method->setBody("return match(\$key) {\n" . implode(",\n", $arms) . "\n};");
    }

    private function addTrieMethods(ClassType $class, array $flat): void
    {
        $trie = [];
        foreach ($flat as $storedPath => $redirectsByUid) {
            $segments = explode('/', trim((string)$storedPath, '/'));
            $this->insertIntoTrie($trie, $segments, array_values($redirectsByUid));
        }
        $this->generateTrieMethod($class, 'matchTrieRoot', '', $trie, 0);
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

    private function generateTrieMethod(
        ClassType $class,
        string $methodName,
        string $pathPrefix,
        array $trieNode,
        int $depth
    ): void {
        $method = $class->addMethod($methodName);
        $method->setVisibility($methodName === 'matchTrieRoot' ? 'protected' : 'private');
        $method->addParameter('seg')->setType('array');
        $method->setReturnType('?array');
        if ($pathPrefix !== '') {
            $method->addComment($pathPrefix . '/…');
        }

        $dumper = new Dumper();
        $arms = [];

        // Terminal at this node: '' arm (path ends here, no more segments)
        if (isset($trieNode['__redirects'])) {
            $arms[] = sprintf(
                "\t'' => \$this->firstActive(%s)",
                $dumper->dump($trieNode['__redirects'])
            );
        }

        foreach ($trieNode as $segment => $childNode) {
            if ($segment === '__redirects') {
                continue;
            }

            $childPrefix = $pathPrefix === '' ? (string)$segment : $pathPrefix . '/' . $segment;
            $childMethodName = 'matchSeg_' . md5($childPrefix);

            // Always generate a child method. Inlining is not correct here because
            // a match arm like "'news' => firstActive([...])" would fire for any
            // request whose first segment is 'news', regardless of deeper segments.
            // The child method checks the *next* segment and enforces '' for terminals.
            $this->generateTrieMethod($class, $childMethodName, $childPrefix, $childNode, $depth + 1);
            $arms[] = sprintf(
                "\t%s => \$this->%s(\$seg)",
                $dumper->dump((string)$segment),
                $childMethodName
            );
        }

        $arms[] = "\tdefault => null";

        $method->setBody(
            sprintf("return match(\$seg[%d] ?? '') {\n", $depth) .
            implode(",\n", $arms) . "\n};"
        );
    }

    private function addMatchRegexMethod(ClassType $class, string $methodName, array $patterns): void
    {
        $method = $class->addMethod($methodName);
        $method->setVisibility('protected');
        $method->setReturnType('array');

        if ($patterns === []) {
            $method->setBody('return [];');
            return;
        }

        $dumper = new Dumper();
        $normalized = [];
        foreach ($patterns as $pattern => $redirectsByUid) {
            $normalized[(string)$pattern] = array_values($redirectsByUid);
        }
        $method->setBody('return ' . $dumper->dump($normalized) . ';');
    }
}
